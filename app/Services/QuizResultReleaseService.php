<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Assessment;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative place a Quiz's graded result *and* the
 * Organization's real next-step decision about it become visible to the
 * Student, together, as one coherent recruitment update (Phase 10A.4A).
 *
 * **The critical rule this whole class exists to enforce**: a configured
 * release time is never sufficient on its own. A Student-facing update may
 * only be released when *all* of the following hold —
 * see {@see isReadyToRelease()}:
 *
 *   1. The Quiz has been submitted and graded (`Assessment.completed_at`).
 *   2. The Organization has selected a real next action
 *      (`Assessment.next_action` — `interview`/`offer`/`reject`).
 *   3. Whatever data that next action requires is actually complete
 *      (see {@see isNextActionReady()} — a real linked Interview
 *      Assessment for `interview`, staged Offer terms for `offer`, an
 *      explicit confirmation timestamp for `reject`).
 *   4. Not already released.
 *
 * If any of these is missing, the Student remains "Assessment Submitted /
 * Result Pending" — no score, no pass/fail, no Interview/Offer/rejection
 * message leaks through any Student-facing path. This is deliberately
 * decoupled from *when* (release-mode timing) — see
 * {@see attemptRelease()} for that half.
 *
 * **Four concepts, kept genuinely separate, never merged**: (A) the raw
 * Quiz score (`QuizAttempt.score`) — computed once, at submit, always. (B)
 * the technical result (`Assessment.result`, `passed`/`failed`) — computed
 * once, at submit, always, from (A). (C) the Organization's next-step
 * decision (`Assessment.next_action` + its readiness data) — set later, by
 * an explicit Organization action, never automatically from (B). (D) the
 * Student-facing *release* of the combined (B)+(C) outcome
 * (`Assessment.result_released_at`) — this class's entire job.
 *
 * **Three release triggers, one shared readiness/timing rule, never
 * duplicated between them**:
 *   - `Student\QuizController::submit()` — via {@see attemptRelease()},
 *     immediately after grading (in case a decision was somehow already
 *     staged before submission, or `immediate` mode needs nothing else).
 *   - The Organization completing a next-action decision
 *     (`Organization\QuizController::setNextAction*()`) — via
 *     {@see attemptRelease()}, since a decision completing after a
 *     `scheduled` release time has already passed must release
 *     immediately, without waiting for another day or a job re-run.
 *   - `App\Jobs\ReleaseQuizResultJob`, firing once at `result_release_at`
 *     — via {@see attemptRelease()} if the decision is ready, or
 *     {@see sendDecisionReminder()} if it isn't (Organization reminder,
 *     never a Student-facing anything).
 *   - The manual release endpoint
 *     (`Organization\QuizController::releaseResult()`) — via
 *     {@see releaseManually()}, which still requires full readiness (a
 *     manual release with no decision is rejected) but bypasses the
 *     release-mode/timing gate, matching the pre-10A.4A "an organization
 *     may always release early" rule.
 *
 * **One coherent Student communication per release, never two.** The
 * low-level {@see release()} method sends exactly one notification/email —
 * never a separate generic "quiz result" message *and* a separate
 * "Interview scheduled"/"Offer received"/"rejected" message for the same
 * release. For `offer`/`reject`, this reuses the *existing* Offer/rejection
 * workflows and their existing notifications verbatim (never a new,
 * parallel one) — see {@see releaseOfferDecision()}/{@see releaseRejectDecision()}.
 * For `interview`, a dedicated combined notification exists
 * (`NotificationService::notifyQuizDecisionInterview()`) precisely because
 * no existing single notification says both "you passed" and "here are
 * your real interview details" at once.
 *
 * **Concurrency**: {@see release()} re-locks and re-checks readiness
 * (`lockForUpdate()`) inside its own transaction, so a race between the
 * scheduled job, a manual release click, and a decision-completion trigger
 * can never release the same Assessment twice — whichever transaction
 * commits first wins; every other one finds `isResultReleased()` already
 * true (or the decision no longer matching) and no-ops.
 */
class QuizResultReleaseService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly OfferService $offers,
    ) {
    }

    /**
     * The mode/mid-air-decision-agnostic readiness check — grading,
     * decision, and not-already-released, nothing about *when*. Every
     * other public method in this class is built on top of this one; call
     * it directly only when you genuinely don't care about release-mode
     * timing (e.g. deciding whether to show "Decision Required" in an
     * Organization UI).
     */
    public function isReadyToRelease(Assessment $assessment): bool
    {
        if ($assessment->completed_at === null) {
            return false;
        }

        if ($assessment->isResultReleased()) {
            return false;
        }

        return $this->isNextActionReady($assessment);
    }

    /**
     * Whether the Organization's selected `next_action` (if any) has
     * everything it needs to actually be released. `next_action === null`
     * (no decision selected yet) is always `false` — the critical "missing
     * decision" case this entire phase exists to close.
     */
    public function isNextActionReady(Assessment $assessment): bool
    {
        return match ($assessment->next_action) {
            'interview' => $assessment->next_action_assessment_id !== null,
            'offer' => $assessment->next_action_data !== null,
            'reject' => $assessment->next_action_prepared_at !== null,
            default => false,
        };
    }

    /**
     * The automatic-trigger release path — called from `submit()`, from
     * every `setNextAction*()` decision-completion action, and from
     * {@see App\Jobs\ReleaseQuizResultJob}. Honors `Quiz.result_release_mode`'s
     * full Phase 10A.4A semantics:
     *
     *   - `immediate`: releases the moment grading *and* the decision are
     *     both ready — never merely at submit time the way pre-10A.4A
     *     `immediate` did.
     *   - `scheduled`: releases only once `result_release_at` has passed
     *     *and* the decision is ready — if the decision becomes ready
     *     before that time, this call is a no-op (the job or a later
     *     decision-completion call will release it once the time has
     *     genuinely passed); if the time has already passed by the time
     *     the decision becomes ready, this releases immediately, right
     *     here — no waiting for "another day" or a re-scheduled job.
     *   - `manual`: never auto-releases here, regardless of readiness —
     *     only {@see releaseManually()} (the explicit Organization action)
     *     can release a `manual`-mode Assessment.
     *
     * Returns `true` only if a release actually happened just now.
     */
    public function attemptRelease(Assessment $assessment): bool
    {
        if (! $this->isReadyToRelease($assessment)) {
            return false;
        }

        // Phase 10A.4B: `resolvedQuiz()` -- not the bare `quiz` relation --
        // since this Assessment may reference an Opportunity's shared Quiz
        // template (`quiz_id` set) rather than own a private one.
        $quiz = $assessment->resolvedQuiz();

        $modeAllowsNow = match ($quiz?->result_release_mode) {
            'immediate' => true,
            'scheduled' => $quiz->result_release_at !== null && $quiz->result_release_at->isPast(),
            default => false,
        };

        if (! $modeAllowsNow) {
            return false;
        }

        return $this->release($assessment);
    }

    /**
     * The explicit Organization "Release" action
     * (`Organization\QuizController::releaseResult()`). Bypasses the
     * release-mode/timing gate entirely (an Organization may always
     * release early, exactly like pre-10A.4A) but still requires full
     * readiness — a manual release attempt with no ready decision returns
     * `false` (the controller turns that into a clear 422), never silently
     * releasing a bare technical result with no next step.
     */
    public function releaseManually(Assessment $assessment): bool
    {
        if (! $this->isReadyToRelease($assessment)) {
            return false;
        }

        return $this->release($assessment);
    }

    /**
     * Sends the Organization a "Decision Required" reminder when a
     * `scheduled` release time has arrived (or a manual/immediate check
     * happened) but the decision still isn't ready — never a Student-facing
     * effect of any kind. Idempotent: a second call for the same Assessment
     * (a queue retry, a repeated job firing) is a guaranteed no-op once the
     * first call's `decision_reminder_sent_at` write has committed, checked
     * again under a row lock to close the race between two genuinely
     * concurrent callers. Returns `true` only if a reminder was actually
     * created just now.
     */
    public function sendDecisionReminder(Assessment $assessment): bool
    {
        if (! $this->needsDecisionReminder($assessment)) {
            return false;
        }

        return DB::transaction(function () use ($assessment) {
            $locked = Assessment::where('id', $assessment->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->needsDecisionReminder($locked)) {
                return false;
            }

            $locked->decision_reminder_sent_at = now();
            $locked->save();

            $locked->loadMissing([
                'application.opportunity.organizationProfile.user',
                'application.studentProfile.user',
            ]);
            $application = $locked->application;

            $this->notifications->notifyDecisionRequired(
                $application->opportunity->organizationProfile->user,
                $application->studentProfile->user->name,
                $application->opportunity->title,
                $application->id,
            );

            return true;
        });
    }

    private function needsDecisionReminder(Assessment $assessment): bool
    {
        if ($assessment->decision_reminder_sent_at !== null) {
            return false;
        }

        if ($assessment->completed_at === null || $assessment->isResultReleased()) {
            return false;
        }

        // The decision *is* ready -- a release should happen instead of a
        // reminder; this is never the reminder's job to decide.
        return ! $this->isNextActionReady($assessment);
    }

    /**
     * The low-level, idempotent, single-communication release. Never call
     * directly from a controller — go through {@see attemptRelease()} or
     * {@see releaseManually()}, both of which already enforce readiness
     * before reaching here; this method re-checks under a row lock purely
     * as the final concurrency authority (see this class's own doc comment
     * on the three-trigger race).
     */
    private function release(Assessment $assessment): bool
    {
        return DB::transaction(function () use ($assessment) {
            $locked = Assessment::where('id', $assessment->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->isReadyToRelease($locked)) {
                return false;
            }

            $locked->result_released_at = now();
            $locked->save();

            $locked->loadMissing(['application.studentProfile.user', 'application.opportunity']);
            $application = $locked->application;

            match ($locked->next_action) {
                'interview' => $this->releaseInterviewDecision($locked, $application),
                'offer' => $this->releaseOfferDecision($locked, $application),
                'reject' => $this->releaseRejectDecision($locked, $application),
            };

            return true;
        });
    }

    /**
     * The Interview decision's release: the follow-up Interview Assessment
     * already exists (created, hidden from the Student, back when the
     * Organization prepared this decision) — releasing sends the one
     * combined notification/email and, because Student-visibility for that
     * Interview Assessment is *derived* from this origin's
     * `result_released_at` (see `Assessment::isPendingDecisionRelease()`),
     * requires no further write to the Interview Assessment itself.
     */
    private function releaseInterviewDecision(Assessment $quizAssessment, Application $application): void
    {
        $quizAssessment->loadMissing('nextActionAssessment.interview');
        $interview = $quizAssessment->nextActionAssessment?->interview;

        if ($interview === null) {
            return;
        }

        $this->notifications->notifyQuizDecisionInterview(
            $application->studentProfile->user,
            $application->opportunity->title,
            $application->id,
            $interview,
        );
    }

    /**
     * The Offer decision's release: `next_action_data` holds exactly the
     * validated shape `OfferService::sendOffer()` already expects (staged
     * by `Organization\QuizController::setNextActionOffer()` via the same
     * `SendOfferRequest` the direct Offer flow uses) — calling it here, for
     * the first time, is what actually creates the real `Offer` row, moves
     * `Application.status` to `offer_sent`, and sends the existing
     * `notifyOfferSent()` notification/email. Nothing about this Offer
     * exists before this exact moment.
     */
    private function releaseOfferDecision(Assessment $quizAssessment, Application $application): void
    {
        $this->offers->sendOffer($application, $quizAssessment->next_action_data ?? []);
    }

    /**
     * The Reject decision's release: applies the same transition
     * `Organization\ApplicationController::updateStatus()` already applies
     * for a generic reject (`status = rejected`, `reviewed_at = now()`)
     * and sends the existing `notifyApplicationRejected()` notification/
     * email — never a new, separate "quiz failed" message. Assessment
     * history (this Quiz Assessment, its Questions, its QuizAttempt) is
     * untouched — rejecting never deletes or alters it.
     */
    private function releaseRejectDecision(Assessment $quizAssessment, Application $application): void
    {
        $application->status = 'rejected';
        $application->reviewed_at = now();
        $application->save();

        $this->notifications->notifyApplicationRejected(
            $application->studentProfile->user,
            $application->opportunity->title,
            $application->id,
        );
    }
}
