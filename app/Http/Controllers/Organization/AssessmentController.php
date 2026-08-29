<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\AssessmentAlreadyExistsException;
use App\Exceptions\InvalidAssessmentSourceStatusException;
use App\Exceptions\SharedQuizNotReadyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreAssessmentRequest;
use App\Models\Application;
use App\Models\Assessment;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments)
    {
    }

    /**
     * The generic "organization chooses an assessment type" entry point.
     * `type=interview` and, as of Phase 6B-1, `type=quiz` are each
     * implemented via their own `AssessmentService` method
     * (`createInterviewAssessment()` / `createQuizAssessment()`) -- this
     * controller only branches on `type` and translates the outcome into
     * the shared response envelope; it never implements assessment-type
     * business logic itself.
     */
    public function store(StoreAssessmentRequest $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        try {
            $assessment = $request->validated('type') === 'quiz'
                ? $this->assessments->createQuizAssessment($application, $request->validated('quiz'))
                : $this->assessments->createInterviewAssessment($application, $request->validated('interview'));
        } catch (InvalidAssessmentSourceStatusException) {
            return response()->json([
                'success' => false,
                'message' => 'An assessment can only be created for shortlisted applications',
                'data' => null,
            ], 422);
        } catch (AssessmentAlreadyExistsException) {
            return response()->json([
                'success' => false,
                'message' => 'An assessment already exists for this application',
                'data' => null,
            ], 409);
        }

        // The same fully-populated Application shape every other
        // organization-facing endpoint returns (see ApplicationController) --
        // `cv` is required by the Flutter client's ApplicationModel, and
        // `opportunity`/`studentProfile.user` are the rest of that
        // established contract. Nested dot-notation keeps this eager
        // loading (no N+1) rather than letting the client's own later
        // property access lazy-load them one row at a time. `interview`/
        // `quiz.questions` are both always loaded (exactly one is ever
        // populated, matching `type`) -- the unused one simply serializes
        // as `null`, the same permissive pattern `show()`/
        // `showForApplication()` already use for `interview`.
        $assessment->load([
            'application.opportunity',
            'application.studentProfile.user',
            'application.cv',
            'interview',
            'quiz.questions',
        ]);
        // Avoid redundant/duplicated data: the Interview's own
        // backward-compatible `application` accessor (see Interview.php)
        // exists for the legacy standalone Interview endpoints, but has no
        // reason to repeat `data.application` a second time, nested,
        // inside a generic Assessment response.
        $assessment->interview?->makeHidden('application');

        return response()->json([
            'success' => true,
            'message' => 'Assessment created successfully',
            'data' => $assessment,
        ], 201);
    }

    /**
     * `POST /organization/applications/{application}/quiz-assessment`
     * (Phase 10A.4B) — advances this candidate straight to the
     * Opportunity's shared Quiz template, via
     * `AssessmentService::advanceToSharedQuiz()`. Deliberately a separate
     * endpoint from `store()` above, not an overload of
     * `type=quiz` on it — `store()` always *authors* a brand-new private
     * Quiz; this always *references* an existing, already-published one.
     * Conflating the two behind one endpoint/payload shape would make the
     * generic `assessments` endpoint's behavior depend on hidden
     * Opportunity configuration rather than what the request itself says,
     * which section 24 of the phase spec explicitly asks to avoid ("keep
     * candidate Assessment/Attempt endpoints separate").
     */
    public function storeSharedQuizAssessment(Request $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        try {
            $assessment = $this->assessments->advanceToSharedQuiz($application);
        } catch (InvalidAssessmentSourceStatusException) {
            return response()->json([
                'success' => false,
                'message' => 'An assessment can only be created for shortlisted applications',
                'data' => null,
            ], 422);
        } catch (AssessmentAlreadyExistsException) {
            return response()->json([
                'success' => false,
                'message' => 'An assessment already exists for this application',
                'data' => null,
            ], 409);
        } catch (SharedQuizNotReadyException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }

        $assessment->load([
            'application.opportunity',
            'application.studentProfile.user',
            'application.cv',
        ]);
        $assessment->withResolvedQuizRelation();

        return response()->json([
            'success' => true,
            'message' => 'Candidate advanced to the shared quiz',
            'data' => $assessment,
        ], 201);
    }

    /**
     * The full Assessment *history* for a specific application the
     * organization owns, oldest first (Phase 10A.3) -- a completed Quiz
     * followed by a new Interview both come back here, in full; nothing is
     * ever collapsed down to "just the latest one". Before this phase an
     * application could only ever have one Assessment, so `data` was a
     * single nullable object; **as of Phase 10A.3, `data` is always an
     * array** (possibly empty) -- this is a deliberate breaking response-
     * shape change, made together with the Flutter client in the same
     * phase (see docs/API.md section 7 for the full contract). Each
     * element is fully loaded exactly as before (`interview`,
     * `quiz.questions`, `quiz.attempts`).
     */
    public function showForApplication(Application $application, Request $request): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        $assessments = $application->assessments()
            // Phase 10A.4A: `quiz.attempts` already gives the Organization
            // the real score/result; `nextActionAssessment.interview` is
            // the "Next Step" panel's own data -- the real, already-
            // scheduled (but possibly still Student-hidden) follow-up
            // Interview a `next_action = 'interview'` decision points at.
            // Never gated for the Organization -- see
            // `Assessment::isPendingDecisionRelease()`'s own doc comment on
            // why that gate is Student-only.
            ->with(['interview', 'quiz.questions', 'quiz.attempts', 'nextActionAssessment.interview'])
            ->get();
        // `$application` here is the route-bound model reused as-is (not a
        // fresh fetch via `load()`), so its own missing relations need
        // filling in explicitly -- `loadMissing` skips `opportunity` if the
        // ownership check above already lazy-loaded it, avoiding a
        // redundant query.
        $application->loadMissing(['opportunity', 'studentProfile.user', 'cv']);
        $assessments->each(function (Assessment $assessment) use ($application) {
            $assessment->setRelation('application', $application);
            // Phase 10A.4B: resolves `quiz` for an Assessment referencing an
            // Opportunity's shared Quiz template -- a no-op for every
            // legacy Assessment (`quiz_id` null), whose `quiz.questions`/
            // `quiz.attempts` are already correctly eager-loaded above.
            $assessment->withResolvedQuizRelation();
            // Phase 10A.4B addendum (section 12): a derived, human-facing
            // timing label -- see `Assessment::quizTimingStatus()`.
            $assessment->quiz_timing_status = $assessment->quizTimingStatus();
        });

        return response()->json([
            'success' => true,
            'message' => $assessments->isEmpty()
                ? 'This application has no assessment yet'
                : 'Assessment history retrieved successfully',
            'data' => $assessments,
        ]);
    }

    /**
     * A single assessment addressed by its own ID.
     */
    public function show(Assessment $assessment, Request $request): JsonResponse
    {
        $assessment->loadMissing([
            'application.opportunity',
            'application.studentProfile.user',
            'application.cv',
        ]);

        if ($assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        $assessment->load(['interview', 'quiz.questions', 'quiz.attempts', 'nextActionAssessment.interview']);
        // Phase 10A.4B: see the identical call in showForApplication().
        $assessment->withResolvedQuizRelation();
        // Phase 10A.4B addendum (section 12): see the identical call in
        // showForApplication().
        $assessment->quiz_timing_status = $assessment->quizTimingStatus();

        return response()->json([
            'success' => true,
            'message' => 'Assessment retrieved successfully',
            'data' => $assessment,
        ]);
    }
}
