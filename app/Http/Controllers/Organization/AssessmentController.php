<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\AssessmentAlreadyExistsException;
use App\Exceptions\InvalidAssessmentSourceStatusException;
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
     * `type=interview` is implemented via the same shared workflow the
     * legacy Interview endpoint uses; `type=quiz` is a recognized-but-
     * unavailable value that is rejected explicitly here, before any
     * database write, rather than by generic validation.
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

        if ($request->validated('type') === 'quiz') {
            return response()->json([
                'success' => false,
                'message' => 'Quiz assessments are not available yet.',
                'data' => null,
            ], 422);
        }

        try {
            $assessment = $this->assessments->createInterviewAssessment(
                $application,
                $request->validated('interview'),
            );
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
        // property access lazy-load them one row at a time.
        $assessment->load([
            'application.opportunity',
            'application.studentProfile.user',
            'application.cv',
            'interview',
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
     * The one assessment (if any) belonging to a specific application the
     * organization owns.
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

        $assessment = $application->assessment()->with('interview')->first();
        // `$application` here is the route-bound model reused as-is (not a
        // fresh fetch via `load()`), so its own missing relations need
        // filling in explicitly -- `loadMissing` skips `opportunity` if the
        // ownership check above already lazy-loaded it, avoiding a
        // redundant query.
        $application->loadMissing(['opportunity', 'studentProfile.user', 'cv']);
        $assessment?->setRelation('application', $application);

        return response()->json([
            'success' => true,
            'message' => $assessment === null
                ? 'This application has no assessment yet'
                : 'Assessment retrieved successfully',
            'data' => $assessment,
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

        return response()->json([
            'success' => true,
            'message' => 'Assessment retrieved successfully',
            'data' => $assessment->load('interview'),
        ]);
    }
}
