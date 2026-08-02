<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
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
        $assessment->loadMissing('application.opportunity');

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
