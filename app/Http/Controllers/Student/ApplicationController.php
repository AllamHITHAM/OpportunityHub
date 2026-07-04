<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\ApplyToOpportunityRequest;
use App\Models\Opportunity;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ApplicationController extends Controller
{
    public function store(ApplyToOpportunityRequest $request, Opportunity $opportunity): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        if ($opportunity->status !== 'open' || $opportunity->organizationProfile?->approval_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        if (
            $opportunity->application_deadline &&
            now()->startOfDay()->gt(Carbon::parse($opportunity->application_deadline)->startOfDay())
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Application deadline has passed',
                'data' => null,
            ], 422);
        }

        if ($studentProfile->cvs()->doesntExist()) {
            return response()->json([
                'success' => false,
                'message' => 'You must upload at least one CV before applying',
                'data' => null,
            ], 422);
        }

        $alreadyApplied = $studentProfile->applications()
            ->where('opportunity_id', $opportunity->id)
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'success' => false,
                'message' => 'You have already applied to this opportunity',
                'data' => null,
            ], 409);
        }

        try {
            $application = $studentProfile->applications()->create([
                ...$request->validated(),
                'opportunity_id' => $opportunity->id,
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'You have already applied to this opportunity',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully',
            'data' => $application->load(['opportunity', 'cv']),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $applications = $request->user()->studentProfile->applications()->with(['opportunity', 'cv'])->get();

        return response()->json([
            'success' => true,
            'message' => 'Applications retrieved successfully',
            'data' => $applications,
        ]);
    }
}
