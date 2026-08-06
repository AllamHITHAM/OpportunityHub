<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\AssessmentAlreadyExistsException;
use App\Exceptions\InvalidAssessmentSourceStatusException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompleteInterviewRequest;
use App\Http\Requests\Organization\StoreInterviewRequest;
use App\Http\Requests\Organization\UpdateInterviewRequest;
use App\Models\Application;
use App\Models\Interview;
use App\Services\AssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    public function __construct(private readonly AssessmentService $assessments)
    {
    }

    public function store(StoreInterviewRequest $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        try {
            $assessment = $this->assessments->createInterviewAssessment(
                $application,
                $request->validated(),
            );
        } catch (InvalidAssessmentSourceStatusException) {
            return response()->json([
                'success' => false,
                'message' => 'An interview can only be scheduled for shortlisted applications',
                'data' => null,
            ], 422);
        } catch (AssessmentAlreadyExistsException) {
            return response()->json([
                'success' => false,
                'message' => 'An interview already exists for this application',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interview scheduled successfully',
            'data' => $assessment->interview->load('assessment.application'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $interviews = Interview::whereHas('assessment.application.opportunity', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->with('assessment.application')->get();

        return response()->json([
            'success' => true,
            'message' => 'Interviews retrieved successfully',
            'data' => $interviews,
        ]);
    }

    public function show(Interview $interview, Request $request): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interview retrieved successfully',
            'data' => $interview->load('assessment.application'),
        ]);
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        $interview->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Interview updated successfully',
            'data' => $interview->fresh('assessment.application'),
        ]);
    }

    public function complete(CompleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        DB::transaction(function () use ($request, $interview) {
            $interview->status = 'completed';
            $interview->completed_at = now();

            if ($request->filled('decision')) {
                $interview->decision = $request->validated('decision');
            }

            if ($request->filled('rating')) {
                $interview->rating = $request->validated('rating');
            }

            if ($request->filled('company_feedback')) {
                $interview->company_feedback = $request->validated('company_feedback');
            }

            $interview->save();

            // The assessment mirrors the interview's shared lifecycle
            // state -- it never drives the application's recruitment
            // status, which stays under the organization's manual control.
            $assessment = $interview->assessment;
            $assessment->status = 'completed';
            $assessment->completed_at = $interview->completed_at;
            $assessment->result = $this->assessmentResultFor($interview->decision);
            $assessment->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Interview marked as completed',
            'data' => $interview->fresh('assessment.application'),
        ]);
    }

    public function destroy(Interview $interview, Request $request): JsonResponse
    {
        if ($interview->assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        if ($interview->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Completed interviews cannot be deleted',
                'data' => null,
            ], 409);
        }

        DB::transaction(function () use ($interview) {
            $assessment = $interview->assessment;
            $application = $assessment->application;

            // Cascades to the Interview row via assessments.id -> interviews.assessment_id.
            $assessment->delete();

            // Never leave an application at `interview_scheduled` once its
            // only assessment is gone.
            if ($application->status === 'interview_scheduled') {
                $application->status = 'shortlisted';
                $application->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Interview deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * interviews.decision -> assessments.result. No decision recorded yet
     * (`null`/`pending`) maps to `null` -- matching the convention
     * documented on the assessments migration/model.
     */
    private function assessmentResultFor(?string $decision): ?string
    {
        return match ($decision) {
            'passed', 'failed', 'waiting' => $decision,
            default => null,
        };
    }
}
