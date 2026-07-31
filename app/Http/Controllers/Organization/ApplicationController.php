<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateApplicationStatusRequest;
use App\Models\Application;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $applications = Application::whereHas('opportunity', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->with(['opportunity', 'studentProfile.user', 'cv'])->get();

        return response()->json([
            'success' => true,
            'message' => 'Applications retrieved successfully',
            'data' => $applications,
        ]);
    }

    public function indexForOpportunity(Opportunity $opportunity, Request $request): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $applications = $opportunity->applications()->with(['studentProfile.user', 'cv'])->get();

        return response()->json([
            'success' => true,
            'message' => 'Applications retrieved successfully',
            'data' => $applications,
        ]);
    }

    public function show(Application $application, Request $request): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Application retrieved successfully',
            'data' => $application->load(['opportunity', 'studentProfile.user', 'cv']),
        ]);
    }

    public function updateStatus(UpdateApplicationStatusRequest $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        if ($application->status === 'withdrawn') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot change the status of a withdrawn application',
                'data' => null,
            ], 409);
        }

        $application->status = $request->validated('status');
        $application->reviewed_at = now();
        $application->save();

        return response()->json([
            'success' => true,
            'message' => 'Application status updated successfully',
            'data' => $application->fresh(['opportunity', 'studentProfile.user', 'cv']),
        ]);
    }
}
