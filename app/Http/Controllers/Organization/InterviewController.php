<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompleteInterviewRequest;
use App\Http\Requests\Organization\StoreInterviewRequest;
use App\Http\Requests\Organization\UpdateInterviewRequest;
use App\Models\Application;
use App\Models\Interview;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterviewController extends Controller
{
    public function store(StoreInterviewRequest $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        if (! in_array($application->status, ['shortlisted', 'interview_scheduled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'An interview can only be scheduled for shortlisted applications',
                'data' => null,
            ], 422);
        }

        if ($application->interview()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An interview already exists for this application',
                'data' => null,
            ], 409);
        }

        try {
            $interview = DB::transaction(function () use ($request, $application) {
                $interview = $application->interview()->create($request->validated());

                $application->status = 'interview_scheduled';
                $application->reviewed_at = now();
                $application->save();

                return $interview;
            });
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'An interview already exists for this application',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interview scheduled successfully',
            'data' => $interview->load('application'),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $interviews = Interview::whereHas('application.opportunity', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->with('application')->get();

        return response()->json([
            'success' => true,
            'message' => 'Interviews retrieved successfully',
            'data' => $interviews,
        ]);
    }

    public function show(Interview $interview, Request $request): JsonResponse
    {
        if ($interview->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Interview retrieved successfully',
            'data' => $interview->load('application'),
        ]);
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): JsonResponse
    {
        if ($interview->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
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
            'data' => $interview->fresh('application'),
        ]);
    }

    public function complete(CompleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        if ($interview->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Interview not found',
                'data' => null,
            ], 404);
        }

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

        return response()->json([
            'success' => true,
            'message' => 'Interview marked as completed',
            'data' => $interview->fresh('application'),
        ]);
    }

    public function destroy(Interview $interview, Request $request): JsonResponse
    {
        if ($interview->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
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

        $interview->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interview deleted successfully',
            'data' => null,
        ]);
    }
}
