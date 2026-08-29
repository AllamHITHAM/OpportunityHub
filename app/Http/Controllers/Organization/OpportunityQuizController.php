<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOpportunityQuizRequest;
use App\Models\Opportunity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10A.4B — the Organization's shared Quiz template for an Opportunity:
 * authored ONCE (title/instructions/settings; questions are still added
 * afterward, one at a time, via the existing
 * `Organization\QuizController` question endpoints, which already work
 * correctly for a template Quiz id once ownership resolution branches on
 * `opportunity_id` -- see that controller's `ownsQuiz()`), then referenced
 * by every candidate's own Assessment (`AssessmentService::advanceToSharedQuiz()`)
 * instead of each candidate getting a private copy. See
 * `App\Models\Quiz`'s own doc comment for the two mutually exclusive Quiz
 * shapes this template/legacy split relies on.
 */
class OpportunityQuizController extends Controller
{
    /**
     * `GET /organization/opportunities/{opportunity}/quiz`. `data: null`
     * (never a 404) when the Opportunity has no shared Quiz template yet --
     * mirrors `Organization\AssessmentController::show()`'s own "no quiz
     * yet" convention for a single Assessment.
     */
    public function show(int $opportunity, Request $request): JsonResponse
    {
        $opportunity = $this->ownedOpportunity($opportunity, $request);

        if ($opportunity === null) {
            return $this->opportunityNotFound();
        }

        $quiz = $opportunity->quizTemplate()->with('questions')->first();

        return response()->json([
            'success' => true,
            'message' => $quiz === null
                ? 'This opportunity has no quiz yet'
                : 'Quiz retrieved successfully',
            'data' => $quiz,
        ]);
    }

    /**
     * `POST /organization/opportunities/{opportunity}/quiz`. One shared
     * template per Opportunity -- `409` if one already exists, matching the
     * "at most one" invariant `quizzes.opportunity_id`'s unique constraint
     * itself enforces at the database level. Always starts `status=draft`,
     * with no questions yet, exactly like the legacy ad-hoc creation path.
     */
    public function store(StoreOpportunityQuizRequest $request, int $opportunity): JsonResponse
    {
        $opportunity = $this->ownedOpportunity($opportunity, $request);

        if ($opportunity === null) {
            return $this->opportunityNotFound();
        }

        if ($opportunity->quizTemplate()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This opportunity already has a quiz',
                'data' => null,
            ], 409);
        }

        $quiz = $opportunity->quizTemplate()->create(array_merge($request->validated(), [
            'status' => 'draft',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Quiz created successfully',
            'data' => $quiz,
        ], 201);
    }

    /**
     * `PUT /organization/opportunities/{opportunity}/quiz`. Full-replace
     * settings update -- same `draft`-only immutability rule as question
     * CRUD (`Organization\QuizController`): once published, settings are
     * frozen exactly like questions are, for the same reason (candidates
     * may already be relying on what was published).
     */
    public function update(StoreOpportunityQuizRequest $request, int $opportunity): JsonResponse
    {
        $opportunity = $this->ownedOpportunity($opportunity, $request);

        if ($opportunity === null) {
            return $this->opportunityNotFound();
        }

        $quiz = $opportunity->quizTemplate;

        if ($quiz === null) {
            return response()->json([
                'success' => false,
                'message' => 'This opportunity has no quiz yet',
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

        $quiz->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Quiz updated successfully',
            'data' => $quiz->fresh(),
        ]);
    }

    /**
     * `PUT /organization/opportunities/{opportunity}/quiz/publish`. Unlike
     * the legacy `Organization\QuizController::publish()`, this never
     * touches any Assessment/Application/notification -- none exist yet at
     * template-publish time. A candidate only gets their own Assessment
     * (and their own "Quiz Available" notification/email) later, when
     * individually advanced via `AssessmentService::advanceToSharedQuiz()`.
     */
    public function publish(int $opportunity, Request $request): JsonResponse
    {
        $opportunity = $this->ownedOpportunity($opportunity, $request);

        if ($opportunity === null) {
            return $this->opportunityNotFound();
        }

        $quiz = $opportunity->quizTemplate;

        if ($quiz === null) {
            return response()->json([
                'success' => false,
                'message' => 'This opportunity has no quiz yet',
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

        $quiz->status = 'published';
        $quiz->save();

        return response()->json([
            'success' => true,
            'message' => 'Quiz published successfully',
            'data' => $quiz->fresh('questions'),
        ]);
    }

    /**
     * `GET /organization/opportunities/{opportunity}/quiz/results`
     * (section 16/25) -- every candidate who has been advanced to the
     * shared Quiz, with their own score/result/decision/release state, in
     * one deliberately query-conscious pass: one query for the Assessments
     * (eager-loading `application.studentProfile.user`, `nextActionAssessment.interview`),
     * one for every one of their `QuizAttempt`s keyed by `application_id`
     * (never N+1 per candidate). Never includes `answers`/`correct_answer`
     * -- this view is score/result/decision only, matching section 17's
     * "never invent analytics that don't exist" instruction; a full answer
     * review stays out of scope for this phase exactly as it already is
     * for the single-candidate view.
     */
    public function results(int $opportunity, Request $request): JsonResponse
    {
        $opportunity = $this->ownedOpportunity($opportunity, $request);

        if ($opportunity === null) {
            return $this->opportunityNotFound();
        }

        $quiz = $opportunity->quizTemplate;

        if ($quiz === null) {
            return response()->json([
                'success' => true,
                'message' => 'This opportunity has no quiz yet',
                'data' => [],
            ]);
        }

        $assessments = $quiz->candidateAssessments()
            ->with([
                'application.studentProfile.user',
                'nextActionAssessment.interview',
            ])
            ->orderBy('created_at')
            ->get();

        $attemptsByApplication = DB::table('quiz_attempts')
            ->where('quiz_id', $quiz->id)
            ->get()
            ->keyBy('application_id');

        $rows = $assessments->map(function ($assessment) use ($attemptsByApplication) {
            $attempt = $attemptsByApplication->get($assessment->application_id);

            return [
                'assessment_id' => $assessment->id,
                'application_id' => $assessment->application_id,
                'student_name' => $assessment->application->studentProfile->user->name,
                'status' => $assessment->status,
                'score' => $attempt?->score,
                // Only real "submitted" signal this table has (see its own
                // migration) -- re-parsed through Carbon so it serializes as
                // UTC ISO-8601 with the other datetimes below, since $attempt
                // comes from a raw DB::table() query, not an Eloquent model
                // with its own date casts.
                'submitted_at' => $attempt?->submitted_at
                    ? Carbon::parse($attempt->submitted_at)
                    : null,
                'result' => $assessment->result,
                'result_released_at' => $assessment->result_released_at,
                'next_action' => $assessment->next_action,
                'next_action_prepared_at' => $assessment->next_action_prepared_at,
                'interview' => $assessment->nextActionAssessment?->interview,
                // Phase 10A.4B addendum (section 12): this candidate's own
                // frozen window plus a derived timing label -- see
                // `Assessment::quizTimingStatus()`.
                'available_at' => $assessment->available_at,
                'due_at' => $assessment->due_at,
                'timing_status' => $assessment->quizTimingStatus($attempt),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Quiz results retrieved successfully',
            'data' => [
                'quiz' => $quiz,
                'candidates' => $rows,
            ],
        ]);
    }

    private function ownedOpportunity(int $opportunityId, Request $request): ?Opportunity
    {
        $opportunity = Opportunity::find($opportunityId);

        if ($opportunity === null || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return null;
        }

        return $opportunity;
    }

    private function opportunityNotFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Opportunity not found',
            'data' => null,
        ], 404);
    }
}
