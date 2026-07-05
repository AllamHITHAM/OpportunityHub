<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationAnalysisController extends Controller
{
    public function __construct(private readonly MatchingService $matchingService)
    {
    }

    public function analyze(Application $application, Request $request): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        $result = $this->matchingService->analyze($application);

        $application->match_score = $result['overall_match_score'];
        $application->save();

        return response()->json([
            'success' => true,
            'message' => 'Application analysis generated successfully',
            'data' => $result,
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

        if ($application->match_score === null) {
            return response()->json([
                'success' => false,
                'message' => 'Application analysis not found',
                'data' => null,
            ], 404);
        }

        $result = $this->matchingService->analyze($application);
        $result['overall_match_score'] = $application->match_score;

        return response()->json([
            'success' => true,
            'message' => 'Application analysis retrieved successfully',
            'data' => $result,
        ]);
    }
}
