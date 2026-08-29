<?php

namespace App\Services;

use App\Exceptions\AssessmentAlreadyExistsException;
use App\Exceptions\InvalidAssessmentSourceStatusException;
use App\Exceptions\SharedQuizNotReadyException;
use App\Jobs\ReleaseQuizResultJob;
use App\Models\Application;
use App\Models\Assessment;
use App\Models\Quiz;
use App\Support\InterviewContactDetailNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the shared workflow for turning a shortlisted (or already
 * interview_scheduled/in_assessment) Application into a new Assessment plus
 * its type-specific detail record. The legacy
 * `POST .../applications/{application}/interview` endpoint,
 * `POST .../applications/{application}/assessments` with `type=interview`
 * (both `createInterviewAssessment()`), and, as of Phase 6B-1,
 * `type=quiz` (`createQuizAssessment()`) all call this directly, so the
 * status precondition, active-assessment guard, transaction boundary, and
 * application-status side effect are never duplicated between them. As of
 * Phase 10A.3, this is also the one path "Advance to Interview" uses (no
 * dedicated endpoint exists for it) -- an organization advancing a
 * completed Quiz to an Interview is just another call to
 * `createInterviewAssessment()`, now legal because the application's
 * status has been widened to include `in_assessment` (see
 * `ALLOWED_SOURCE_STATUSES`) and the guard below checks for an *active*
 * conflicting Assessment rather than *any* Assessment ever.
 *
 * `Application.status` only ever moves to the generic `in_assessment` value
 * from here (see `transitionToInAssessment()`) -- never to a type-specific
 * value, and never a second time once already there -- so
 * `createQuizAssessment()` reuses the exact same transition
 * `createInterviewAssessment()` does, without introducing another
 * Application status, and advancing a completed Quiz to an Interview simply
 * leaves the application at `in_assessment` it was already at (see
 * docs/BUSINESS_RULES.md section 8). `Assessment.type` still distinguishes
 * interview vs. quiz; `Assessment.status`/`Interview.status`/`Quiz.status`
 * own their own type-specific lifecycles.
 *
 * **Phase 10A.3 — Assessment history and the active-assessment invariant.**
 * An application may now have more than one Assessment over its lifetime
 * (`assessments.application_id` is no longer unique -- see that migration's
 * own doc comment), but may still have **at most one active (non-final)
 * Assessment at a time**. "Active" means `status` in
 * [ACTIVE_ASSESSMENT_STATUSES] (`pending`, `scheduled`, `in_progress`);
 * anything else (`completed`, and the schema-allowed but never-yet-used
 * `declined`/`cancelled`) is final and never blocks a new Assessment. This
 * invariant is no longer a database unique constraint (it can't be, once
 * history is allowed) -- it is enforced here, inside the creation
 * transaction, by row-locking the parent `Application` first
 * (`lockForUpdate()`, the same discipline `OfferService::respondToOffer()`
 * already uses for its own once-only transition) and only then checking for
 * an active Assessment, so two concurrent creation requests for the same
 * application can never both succeed.
 *
 * Deliberately does not perform HTTP response construction, does not
 * return a JsonResponse, and does not perform organization-ownership
 * authorization -- all of that stays in the calling controllers, matching
 * every other controller/service pairing in this codebase (see
 * ApplicationAnalysisController + MatchingService).
 */
class AssessmentService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * `interview_scheduled` stays allowed here purely for legacy rows that
     * reached that status without a real Assessment ever being created
     * (e.g. set directly through the generic status endpoint back when that
     * input was still allowed) -- it lets such an application still receive
     * its first real Assessment.
     *
     * **`in_assessment` is allowed as of Phase 10A.3** (previously
     * deliberately excluded, back when it could only ever mean "a real
     * Assessment already exists for this application" -- see git history).
     * That's no longer sound as the *only* signal: `in_assessment` now also
     * describes "this application's evaluation chain is ongoing, but its
     * most recent Assessment has already been finalized" -- exactly the
     * state a completed Quiz leaves behind before "Advance to Interview".
     * Safe to allow unconditionally here because `assertNoActiveAssessment()`
     * below always runs first and independently blocks the one case that
     * would actually be wrong (an `in_assessment` application whose most
     * recent Assessment is still active) with its own 409 -- so this list
     * no longer needs to carry that responsibility.
     */
    private const ALLOWED_SOURCE_STATUSES = ['shortlisted', 'interview_scheduled', 'in_assessment'];

    /**
     * Assessment statuses considered "active" -- i.e. still open, and
     * therefore blocking a new Assessment from being created for the same
     * application. Everything else (`completed`, `declined`, `cancelled`)
     * is final. See this class's own doc comment for the full invariant.
     */
    private const ACTIVE_ASSESSMENT_STATUSES = ['pending', 'scheduled', 'in_progress'];

    /**
     * Creates an Assessment (`type=interview`) and its Interview detail
     * record in one transaction, then moves the application to the generic
     * `in_assessment` status (see `transitionToInAssessment()`) -- a no-op
     * if it's already there (Phase 10A.3 "Advance to Interview").
     *
     * @param  array<string, mixed>  $interviewData  Already-validated Interview fields
     *                                                (interview_type, scheduled_at, ...).
     * @param  int|null  $originAssessmentId  **Phase 10A.4A.** Non-null only when this
     *                                        Interview is being created as a staged
     *                                        "Advance to Interview" *decision* on some
     *                                        other (Quiz) Assessment
     *                                        (`Organization\QuizController::setNextActionInterview()`)
     *                                        rather than a direct interview creation
     *                                        (Path A, or a plain post-10A.3 advance
     *                                        outside the decision-release flow). When
     *                                        set: `origin_assessment_id` is stamped onto
     *                                        the newly-created Assessment (the Student-
     *                                        visibility gate -- see
     *                                        `Assessment::isPendingDecisionRelease()`),
     *                                        and the usual `notifyInterviewScheduled()`
     *                                        call is suppressed entirely -- the Student
     *                                        must not learn of this Interview until the
     *                                        origin Assessment's decision is actually
     *                                        released, at which point
     *                                        `QuizResultReleaseService` sends one
     *                                        coherent combined notification/email
     *                                        instead (never this one, and never both).
     *
     * @throws InvalidAssessmentSourceStatusException  When the application's status
     *                                                  is not shortlisted/interview_scheduled/in_assessment.
     * @throws AssessmentAlreadyExistsException         When the application already has an
     *                                                   *active* Assessment.
     */
    public function createInterviewAssessment(
        Application $application,
        array $interviewData,
        ?int $originAssessmentId = null,
    ): Assessment {
        return DB::transaction(function () use ($application, $interviewData, $originAssessmentId) {
            $locked = $this->lockApplication($application);

            // Active-assessment is checked first so that a genuine conflict
            // always reports as "already exists" (409), even for an
            // `in_assessment` application whose status alone would
            // otherwise -- and redundantly -- also fail the source-status
            // check below.
            $this->assertNoActiveAssessment($locked);
            $this->assertAllowedSourceStatus($locked);

            $assessment = $locked->assessments()->create([
                'type' => 'interview',
                'status' => 'scheduled',
                'result' => null,
                'origin_assessment_id' => $originAssessmentId,
            ]);

            $interview = $assessment->interview()->create(
                InterviewContactDetailNormalizer::normalize($interviewData)
            );

            $this->transitionToInAssessment($locked);

            // Phase 7A-2: emitted once, from this shared service
            // boundary -- both `Organization\InterviewController::store()`
            // (the legacy dedicated route) and
            // `Organization\AssessmentController::store()` (the generic
            // `type=interview` route) delegate here, so notifying from
            // either controller instead would risk a double
            // notification for the same interview if a future change
            // ever called both. This is the one place `type=interview`
            // Assessment creation actually happens -- including Phase
            // 10A.3's "Advance to Interview", which reuses this method
            // as-is, so a newly-scheduled follow-up Interview notifies
            // the student exactly the same way a first one always has.
            // Phase 7A-4.2: the freshly-created $interview is passed
            // through so NotificationService can forward its scheduling
            // fields to EmailService for the "Interview Scheduled" email
            // -- still exactly one call to notifyInterviewScheduled().
            //
            // **Phase 10A.4A**: suppressed entirely when $originAssessmentId
            // is set -- see this parameter's own doc comment above.
            if ($originAssessmentId === null) {
                $this->notifications->notifyInterviewScheduled(
                    $locked->studentProfile->user,
                    $locked->opportunity->title,
                    $locked->id,
                    $interview,
                );
            }

            return $assessment;
        });
    }

    /**
     * Creates an Assessment (`type=quiz`) and its Quiz shell -- no
     * questions yet, those are added afterward one at a time via
     * `App\Http\Controllers\Organization\QuizController` -- in one
     * transaction, then moves the application to the generic `in_assessment`
     * status (see `transitionToInAssessment()`). The Quiz always starts
     * `status=draft` and the Assessment `status=pending`; publishing
     * (`QuizController::publish()`) is a separate, later step that moves the
     * Assessment to `scheduled` without touching the Application again.
     *
     * @param  array<string, mixed>  $quizData  Already-validated Quiz fields
     *                                           (title, instructions, time_limit_minutes, passing_score, ...).
     *
     * @throws InvalidAssessmentSourceStatusException  When the application's status
     *                                                  is not shortlisted/interview_scheduled/in_assessment.
     * @throws AssessmentAlreadyExistsException         When the application already has an
     *                                                   *active* Assessment.
     */
    public function createQuizAssessment(Application $application, array $quizData): Assessment
    {
        return DB::transaction(function () use ($application, $quizData) {
            $locked = $this->lockApplication($application);

            $this->assertNoActiveAssessment($locked);
            $this->assertAllowedSourceStatus($locked);

            $assessment = $locked->assessments()->create([
                'type' => 'quiz',
                'status' => 'pending',
                'result' => null,
            ]);

            $assessment->quiz()->create(array_merge($quizData, ['status' => 'draft']));

            $this->transitionToInAssessment($locked);

            return $assessment;
        });
    }

    /**
     * Phase 10A.4B — advances a shortlisted (or already
     * interview_scheduled/in_assessment) candidate straight to the
     * Opportunity's *shared* Quiz template, instead of authoring a brand-new
     * private Quiz the way `createQuizAssessment()` does. Creates only a new
     * Assessment (`type=quiz`, `quiz_id` referencing the shared template,
     * `status=scheduled` -- the template is already published, so there's no
     * candidate-specific draft/authoring step left to go through) --
     * **never a new Quiz row and never duplicated Questions**. The same
     * active-assessment invariant, source-status guard, and
     * `in_assessment` transition as every other Assessment-creation path in
     * this class apply unchanged.
     *
     * Sends the Student the real "Quiz Available" notification/email
     * (`notifyQuizPublished()`, reused verbatim, now carrying this
     * candidate's own `available_at`/`due_at`) at this exact moment --
     * never when the template itself was published, and never for every
     * applicant just because the Opportunity has a Quiz. If the template
     * uses `result_release_mode=scheduled`, dispatches
     * `ReleaseQuizResultJob` for *this candidate's own* new Assessment,
     * delayed until the template's shared `result_release_at` -- unlike the
     * legacy ad-hoc path (dispatched once, at template-publish time), this
     * must happen per-candidate, since a candidate's Assessment doesn't
     * exist yet when the shared template is published.
     *
     * **Phase 10A.4B addendum**: also computes and freezes this candidate's
     * own `available_at`/`due_at` from the shared template's policy
     * (`availability_delay_days`/`availability_time`/`submission_window_hours`)
     * plus this exact assignment moment -- see `calculateAvailabilityWindow()`.
     * Computed once, here, and never again: a later edit to the shared
     * policy must never retroactively move an already-assigned candidate's
     * dates.
     *
     * @throws InvalidAssessmentSourceStatusException  When the application's status
     *                                                  is not shortlisted/interview_scheduled/in_assessment.
     * @throws AssessmentAlreadyExistsException         When the application already has an
     *                                                   *active* Assessment.
     * @throws SharedQuizNotReadyException               When the Opportunity has no shared Quiz
     *                                                    template, or it isn't published yet.
     */
    public function advanceToSharedQuiz(Application $application): Assessment
    {
        $opportunity = $application->opportunity()->with('quizTemplate')->firstOrFail();
        $sharedQuiz = $opportunity->quizTemplate;

        if ($sharedQuiz === null || $sharedQuiz->status !== 'published') {
            throw new SharedQuizNotReadyException();
        }

        $assessment = DB::transaction(function () use ($application, $sharedQuiz) {
            $locked = $this->lockApplication($application);

            $this->assertNoActiveAssessment($locked);
            $this->assertAllowedSourceStatus($locked);

            [$availableAt, $dueAt] = $this->calculateAvailabilityWindow($sharedQuiz, now());

            $created = $locked->assessments()->create([
                'type' => 'quiz',
                'quiz_id' => $sharedQuiz->id,
                'status' => 'scheduled',
                'result' => null,
                'available_at' => $availableAt,
                'due_at' => $dueAt,
            ]);

            $this->transitionToInAssessment($locked);

            $this->notifications->notifyQuizPublished(
                $locked->studentProfile->user,
                $locked->opportunity->title,
                $created->id,
                $sharedQuiz,
                availableAt: $availableAt,
                dueAt: $dueAt,
            );

            if ($sharedQuiz->result_release_mode === 'scheduled' && $sharedQuiz->result_release_at !== null) {
                ReleaseQuizResultJob::dispatch($created->id)->delay($sharedQuiz->result_release_at);
            }

            return $created;
        });

        return $assessment;
    }

    /**
     * Phase 10A.4B addendum — computes a candidate's frozen `available_at`/
     * `due_at` from the shared Quiz's policy plus their exact assignment
     * moment. `$assignedAt->addDays($sharedQuiz->availability_delay_days)`
     * lands on the correct calendar day, then `setTimeFromTimeString()`
     * overwrites the time-of-day to the policy's own `availability_time`
     * (a deterministic overwrite, not an addition -- "opens at 10:00" means
     * exactly that, regardless of what time the candidate happened to be
     * assigned at). `due_at = available_at + submission_window_hours`.
     *
     * A shared template with no policy configured at all (defensive only --
     * `StoreOpportunityQuizRequest` requires all three fields for every new
     * template) falls back to "available immediately, no deadline" rather
     * than throwing, the same safe default a legacy/ad-hoc Assessment gets.
     *
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function calculateAvailabilityWindow(Quiz $sharedQuiz, Carbon $assignedAt): array
    {
        if ($sharedQuiz->availability_delay_days === null || $sharedQuiz->availability_time === null) {
            return [$assignedAt->copy(), null];
        }

        $availableAt = $assignedAt->copy()
            ->addDays($sharedQuiz->availability_delay_days)
            ->setTimeFromTimeString($sharedQuiz->availability_time);

        $dueAt = $sharedQuiz->submission_window_hours !== null
            ? $availableAt->copy()->addHours($sharedQuiz->submission_window_hours)
            : null;

        return [$availableAt, $dueAt];
    }

    /**
     * Row-locks and returns a fresh copy of `$application` for the
     * duration of the enclosing transaction -- the single point every
     * active-assessment check and status transition in this class reads
     * from, so two concurrent creation requests for the same application
     * always serialize against each other rather than both passing the
     * active-assessment check before either commits. Must be called from
     * inside `DB::transaction()`.
     */
    private function lockApplication(Application $application): Application
    {
        return Application::where('id', $application->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * The single place `Application.status` moves to the generic
     * `in_assessment` state. Every Assessment-creation path -- interview and,
     * as of Phase 6B-1, quiz -- routes through here instead of assigning the
     * status literal itself, keeping this the one source of truth for the
     * transition. A no-op write when the application is already
     * `in_assessment` (Phase 10A.3 "Advance to Interview") -- still safe to
     * call unconditionally, since it only ever writes the same value back
     * and refreshes `reviewed_at`, never regresses a further-along status.
     */
    private function transitionToInAssessment(Application $application): void
    {
        $application->status = 'in_assessment';
        $application->reviewed_at = now();
        $application->save();
    }

    private function assertAllowedSourceStatus(Application $application): void
    {
        if (! in_array($application->status, self::ALLOWED_SOURCE_STATUSES, true)) {
            throw new InvalidAssessmentSourceStatusException();
        }
    }

    /**
     * The active-assessment invariant (Phase 10A.3) -- see this class's own
     * doc comment. Queries [Application::assessments()] (the full history),
     * not [Application::assessment()] (the latest one), because the
     * invariant genuinely means "no active row anywhere in the history",
     * not merely "the latest row isn't active" -- the two are equivalent in
     * practice (only one Assessment is ever active at a time, by this same
     * invariant), but this is the version that's true by construction
     * rather than by a separate argument.
     */
    private function assertNoActiveAssessment(Application $application): void
    {
        if ($application->assessments()->whereIn('status', self::ACTIVE_ASSESSMENT_STATUSES)->exists()) {
            throw new AssessmentAlreadyExistsException();
        }
    }
}
