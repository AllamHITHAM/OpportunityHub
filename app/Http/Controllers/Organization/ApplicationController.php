<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
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
        })->with(['opportunity', 'studentProfile', 'cv'])->get();

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

        $applications = $opportunity->applications()->with(['studentProfile', 'cv'])->get();

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
            'data' => $application->load(['opportunity', 'studentProfile', 'cv']),
        ]);
    }
}
