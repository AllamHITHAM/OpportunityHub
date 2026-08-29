<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\SendOfferRequest;
use App\Http\Requests\Organization\StoreInterviewRequest;
use App\Http\Requests\Organization\StoreQuestionRequest;
use App\Http\Requests\Organization\UpdateQuestionRequest;
use App\Jobs\ReleaseQuizResultJob;
use App\Models\Assessment;
use App\Models\Question;
use App\Models\Quiz;
use App\Services\AssessmentService;
use App\Services\NotificationService;
use App\Services\QuizResultReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Organization-only Quiz authoring: viewing a quiz, adding/updating/
 * deleting its questions while still a draft, and publishing it. There is
 * no Student endpoint here or anywhere yet -- attempt/submission is a later
 * phase (see docs/BUSINESS_RULES.md).
 *
 * **Phase 10A.4A** adds the "next action" decision endpoints
 * (`setNextActionInterview()`/`setNextActionOffer()`/`setNextActionReject()`)
 * -- see `QuizResultReleaseService`'s own doc comment for the full
 * decision-readiness/release-timing model these implement.
 */
class QuizController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly QuizResultReleaseService $resultRelease,
        private readonly AssessmentService $assessments,
    ) {
    }

    /**
     * The quiz (if any) belonging to a specific assessment the organization
     * owns. Mirrors `AssessmentController::showForApplication()`'s
     * null-data convention rather than 404ing when the assessment exists
     * but has no quiz (e.g. `type=interview`).
     *
     * Also eager-loads `attempts` (Phase 10A.2) -- this is the
     * Organization's own result-review data: the real `score` and
     * `submitted_at`/`started_at`, always visible to the Organization
     * regardless of `result_release_mode` (that mode only ever gates
     * Student-facing visibility, never the Organization's own). At most
     * one attempt ever exists per quiz (the `quiz_attempts` unique
     * constraint), so `attempts` is either empty (not started) or a
     * single-element array -- deliberately still a plain collection
     * rather than a `hasOne`, matching `Quiz::attempts()`'s own doc
     * comment on why.
     */
    public function show(Assessment $assessment, Request $request): JsonResponse
    {
        $assessment->loadMissing('application.opportunity');

        if ($assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        // Phase 10A.4B: `$assessment->quiz` is the legacy, one-candidate
        // relation -- `null` for an Assessment that instead references an
        // Opportunity's shared Quiz template (`quiz_id` set). Resolve
        // whichever kind this Assessment actually uses (see
        // `Assessment::resolvedQuiz()`'s own doc comment on why the two
        // stay separate relations).
        $quiz = $assessment->quiz_id !== null
            ? $assessment->sharedQuiz()->with(['questions', 'attempts' => fn ($q) => $q->where('application_id', $assessment->application_id)])->first()
            : $assessment->quiz()->with(['questions', 'attempts'])->first();

        return response()->json([
            'success' => true,
            'message' => $quiz === null
                ? 'This assessment has no quiz yet'
                : 'Quiz retrieved successfully',
            'data' => $quiz,
        ]);
    }

    public function storeQuestion(StoreQuestionRequest $request, Quiz $quiz): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Published quizzes cannot be modified',
                'data' => null,
            ], 422);
        }

        $question = $quiz->questions()->create($this->questionAttributes($request));

        return response()->json([
            'success' => true,
            'message' => 'Question added successfully',
            'data' => $question,
        ], 201);
    }

    public function updateQuestion(UpdateQuestionRequest $request, Quiz $quiz, Question $question): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($question->quiz_id !== $quiz->id) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Published quizzes cannot be modified',
                'data' => null,
            ], 422);
        }

        $question->update($this->questionAttributes($request));

        return response()->json([
            'success' => true,
            'message' => 'Question updated successfully',
            'data' => $question->fresh(),
        ]);
    }

    public function destroyQuestion(Quiz $quiz, Question $question, Request $request): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($question->quiz_id !== $quiz->id) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Published quizzes cannot be modified',
                'data' => null,
            ], 422);
        }

        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Every question already had to pass `StoreQuestionRequest`/
     * `UpdateQuestionRequest` validation to exist at all, so a question
     * count of at least one is the only "structurally valid" check left to
     * make here -- there is no way for an invalid question to be in the
     * database by the time this runs.
     */
    public function publish(Quiz $quiz, Request $request): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        // Phase 10A.4B: a shared template is published through
        // `Organization\OpportunityQuizController::publish()` instead --
        // this route only ever handles a legacy, one-candidate-only Quiz,
        // and the rest of this method assumes exactly that (`$quiz->assessment`
        // being a single, already-existing row).
        if ($quiz->opportunity_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft quizzes can be published',
                'data' => null,
            ], 422);
        }

        if ($quiz->questions()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'A quiz must have at least one question before it can be published',
                'data' => null,
            ], 422);
        }

        DB::transaction(function () use ($quiz) {
            $quiz->status = 'published';
            $quiz->save();

            // Application.status is left untouched -- it is already
            // `in_assessment` from quiz creation and stays there; only the
            // Assessment's own generic lifecycle advances here.
            $quiz->assessment->status = 'scheduled';
            $quiz->assessment->save();

            // Phase 7A-2: only reachable once, since the draft-only guard
            // above already 422s a second publish attempt -- exactly one
            // notification per real draft -> published transition. Phase
            // 7A-4.2: $quiz is passed through so NotificationService can
            // forward passing_score/time_limit_minutes to EmailService for
            // the "Quiz Available" email -- still exactly one call to
            // notifyQuizPublished().
            $application = $quiz->assessment->application;
            $this->notifications->notifyQuizPublished(
                $application->studentProfile->user,
                $application->opportunity->title,
                $quiz->assessment_id,
                $quiz,
            );

            // Phase 10A.2: a scheduled release is dispatched exactly once,
            // here, at publish time -- the one moment `result_release_at`
            // is finalized and the quiz becomes live. Not dispatched
            // inside `Student\QuizController::submit()`, since the
            // release moment is a single fixed point in time for this
            // quiz, independent of when the Student happens to submit.
            // Phase 10A.4B: dispatched by Assessment id, not Quiz id (see
            // `ReleaseQuizResultJob`'s own doc comment) -- this method is
            // only ever reached for a legacy, one-candidate-only Quiz
            // (`ownsQuiz()` above already rejected a shared-template quiz
            // id here), so `$quiz->assessment_id` unambiguously identifies
            // that one candidate's Assessment.
            if ($quiz->result_release_mode === 'scheduled' && $quiz->result_release_at !== null) {
                ReleaseQuizResultJob::dispatch($quiz->assessment_id)->delay($quiz->result_release_at);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Quiz published successfully',
            'data' => $quiz->fresh('questions')->load('assessment.application'),
        ]);
    }

    /**
     * `PUT /organization/assessments/{assessment}/release-result` --
     * manually releases an already-graded Quiz result (and its now-
     * mandatory next-step decision -- Phase 10A.4A) to the Student. Only
     * meaningful for `result_release_mode = 'manual'` in practice
     * (`immediate`/already-past-`scheduled` are released automatically once
     * both grading and the decision are ready), but not restricted to that
     * mode -- an Organization is always allowed to release early, via
     * `QuizResultReleaseService::releaseManually()`, which still requires
     * decision readiness (a bare technical result with no next step can
     * never be released, manually or otherwise).
     */
    public function releaseResult(Assessment $assessment, Request $request): JsonResponse
    {
        $assessment->loadMissing('application.opportunity');

        if ($assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        if ($assessment->completed_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'This assessment has not been completed yet',
                'data' => null,
            ], 422);
        }

        if ($assessment->isResultReleased()) {
            return response()->json([
                'success' => false,
                'message' => 'This result has already been released',
                'data' => null,
            ], 409);
        }

        // **Phase 10A.4A**: checked after the two guards above so those
        // keep their own specific 404/422/409 wording -- this is the new
        // failure mode a manual release attempt with no ready decision
        // hits.
        if (! $this->resultRelease->isNextActionReady($assessment)) {
            return response()->json([
                'success' => false,
                'message' => 'A next-step decision (Advance to Interview, Proceed to Offer, or '.
                    'Reject) must be selected and ready before releasing this result.',
                'data' => null,
            ], 422);
        }

        $this->resultRelease->releaseManually($assessment);

        return response()->json([
            'success' => true,
            'message' => 'Result released successfully',
            'data' => $assessment->fresh(),
        ]);
    }

    /**
     * `POST /organization/assessments/{assessment}/next-action/interview`
     * (Phase 10A.4A) -- "Advance to Interview" as a *staged decision*: the
     * real follow-up Interview Assessment is created right now, with all
     * the same validated fields/rules the direct Interview-scheduling flow
     * already uses (`StoreInterviewRequest`), but the Student is not
     * notified and cannot see it through any Student-facing endpoint (see
     * `Assessment::isPendingDecisionRelease()`) until this Quiz
     * Assessment's decision is actually released. Attempts an immediate
     * release right after staging, in case the release-mode/timing gate is
     * already satisfied (e.g. `immediate` mode, or a `scheduled` time that
     * has already passed).
     */
    public function setNextActionInterview(StoreInterviewRequest $request, Assessment $assessment): JsonResponse
    {
        $assessment->loadMissing('application.opportunity');

        if ($assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        if ($assessment->type !== 'quiz' || $assessment->completed_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'This assessment has not been completed yet',
                'data' => null,
            ], 422);
        }

        if ($assessment->isResultReleased()) {
            return response()->json([
                'success' => false,
                'message' => 'This result has already been released',
                'data' => null,
            ], 409);
        }

        DB::transaction(function () use ($assessment, $request) {
            $this->discardStaleInterviewDecision($assessment);

            $interviewAssessment = $this->assessments->createInterviewAssessment(
                $assessment->application,
                $request->validated(),
                originAssessmentId: $assessment->id,
            );

            $assessment->next_action = 'interview';
            $assessment->next_action_assessment_id = $interviewAssessment->id;
            $assessment->next_action_data = null;
            $assessment->next_action_prepared_at = now();
            $assessment->save();
        });

        $this->resultRelease->attemptRelease($assessment);

        return response()->json([
            'success' => true,
            'message' => 'Interview prepared as the next step',
            'data' => $assessment->fresh()->load('nextActionAssessment.interview'),
        ]);
    }

    /**
     * `POST /organization/assessments/{assessment}/next-action/offer`
     * (Phase 10A.4A) -- "Proceed to Offer" as a *staged decision*: validated
     * with the exact same `SendOfferRequest` rules the direct Offer flow
     * uses, but nothing Student-visible is created yet -- no `Offer` row,
     * no `Application.status` change, no notification. The validated data
     * is staged on this Assessment and only actually passed to
     * `OfferService::sendOffer()` at release time (see
     * `QuizResultReleaseService::releaseOfferDecision()`), which is what
     * first creates the real Offer.
     */
    public function setNextActionOffer(SendOfferRequest $request, Assessment $assessment): JsonResponse
    {
        $assessment->loadMissing('application.opportunity');

        if ($assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        if ($assessment->type !== 'quiz' || $assessment->completed_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'This assessment has not been completed yet',
                'data' => null,
            ], 422);
        }

        if ($assessment->isResultReleased()) {
            return response()->json([
                'success' => false,
                'message' => 'This result has already been released',
                'data' => null,
            ], 409);
        }

        $this->discardStaleInterviewDecision($assessment);

        $assessment->next_action = 'offer';
        $assessment->next_action_assessment_id = null;
        $assessment->next_action_data = $request->validated();
        $assessment->next_action_prepared_at = now();
        $assessment->save();

        $this->resultRelease->attemptRelease($assessment);

        return response()->json([
            'success' => true,
            'message' => 'Offer prepared as the next step',
            'data' => $assessment->fresh(),
        ]);
    }

    /**
     * `POST /organization/assessments/{assessment}/next-action/reject`
     * (Phase 10A.4A) -- "Reject" as a *staged decision*: no body, this
     * endpoint itself is the explicit confirmation the Reject path needs.
     * Nothing Student-visible happens until release -- the real
     * `Application.status = rejected` transition and its existing
     * notification/email only ever happen inside
     * `QuizResultReleaseService::releaseRejectDecision()`.
     */
    public function setNextActionReject(Assessment $assessment, Request $request): JsonResponse
    {
        $assessment->loadMissing('application.opportunity');

        if ($assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        if ($assessment->type !== 'quiz' || $assessment->completed_at === null) {
            return response()->json([
                'success' => false,
                'message' => 'This assessment has not been completed yet',
                'data' => null,
            ], 422);
        }

        if ($assessment->isResultReleased()) {
            return response()->json([
                'success' => false,
                'message' => 'This result has already been released',
                'data' => null,
            ], 409);
        }

        $this->discardStaleInterviewDecision($assessment);

        $assessment->next_action = 'reject';
        $assessment->next_action_assessment_id = null;
        $assessment->next_action_data = null;
        $assessment->next_action_prepared_at = now();
        $assessment->save();

        $this->resultRelease->attemptRelease($assessment);

        return response()->json([
            'success' => true,
            'message' => 'Reject prepared as the next step',
            'data' => $assessment->fresh(),
        ]);
    }

    /**
     * Discards a previously staged "Advance to Interview" decision's real
     * follow-up Assessment before a *different* decision overwrites this
     * Quiz's `next_action` -- without this, the abandoned Interview
     * Assessment would stay `status=scheduled` (active) forever, since
     * nothing else ever finalizes or deletes it, permanently tripping the
     * active-assessment invariant (`AssessmentService::assertNoActiveAssessment()`)
     * for every future Assessment on this application. Safe to hard-delete
     * unconditionally: a still-staged Interview is never Student-visible
     * (`Assessment::isPendingDecisionRelease()`) and this is the only write
     * path that ever touches it before release, so it can never have been
     * seen or acted on by anyone outside the Organization that just
     * changed its mind. A no-op when the current decision isn't `interview`
     * or was never actually staged.
     */
    private function discardStaleInterviewDecision(Assessment $assessment): void
    {
        if ($assessment->next_action === 'interview' && $assessment->next_action_assessment_id !== null) {
            Assessment::whereKey($assessment->next_action_assessment_id)->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function questionAttributes(StoreQuestionRequest|UpdateQuestionRequest $request): array
    {
        $data = $request->validated();
        $data['points'] = $data['points'] ?? 1;
        $data['position'] = $data['position'] ?? 0;

        // true_false never stores options -- its two choices are fixed and
        // never held per-row (see Question model docblock) -- regardless of
        // whatever the client submitted for that field.
        if ($data['type'] === 'true_false') {
            $data['options'] = null;
        }

        return $data;
    }

    /**
     * Phase 10A.4B: branches on which of the two mutually exclusive Quiz
     * shapes `$quiz` is -- a shared template (`opportunity_id` set) resolves
     * ownership directly through its Opportunity; a legacy, one-candidate
     * Quiz resolves it through its one Assessment exactly as before. Reused
     * unchanged by every question CRUD endpoint below, which therefore
     * already works correctly for a template's questions once this branch
     * is in place -- no other change was needed in those methods.
     */
    private function ownsQuiz(Quiz $quiz, Request $request): bool
    {
        if ($quiz->opportunity_id !== null) {
            $quiz->loadMissing('opportunity');

            return $quiz->opportunity->organization_id === $request->user()->organizationProfile->id;
        }

        $quiz->loadMissing('assessment.application.opportunity');

        return $quiz->assessment->application->opportunity->organization_id
            === $request->user()->organizationProfile->id;
    }
}
