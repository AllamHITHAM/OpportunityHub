<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationProfile = $request->user()->organizationProfile;
        $organizationId = $organizationProfile->id;

        $opportunities = $organizationProfile->opportunities();
        $applications = Application::whereHas('opportunity', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        });
        // `Interview` has no direct `application()` *relation* (only a
        // read-only `application` Attribute accessor for JSON
        // serialization -- see Interview.php) since the Phase 4A-1
        // Assessment retarget. The real, queryable chain is
        // `interview -> assessment -> application -> opportunity`.
        $interviews = Interview::whereHas('assessment.application.opportunity', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        });

        $data = [
            'total_opportunities' => (clone $opportunities)->count(),
            'open_opportunities' => (clone $opportunities)->where('status', 'open')->count(),
            'closed_opportunities' => (clone $opportunities)->where('status', 'closed')->count(),
            'draft_opportunities' => (clone $opportunities)->where('status', 'draft')->count(),
            'total_applications' => (clone $applications)->count(),
            'pending_applications' => (clone $applications)->where('status', 'pending')->count(),
            'shortlisted_applications' => (clone $applications)->where('status', 'shortlisted')->count(),
            // Phase 6C-4: makes the final Offer funnel visible alongside
            // the existing accepted/rejected terminal counts -- see
            // docs/BUSINESS_RULES.md section 5 for what each status means.
            'offer_sent_applications' => (clone $applications)->where('status', 'offer_sent')->count(),
            'accepted_applications' => (clone $applications)->where('status', 'accepted')->count(),
            'rejected_applications' => (clone $applications)->where('status', 'rejected')->count(),
            'total_interviews' => (clone $interviews)->count(),
            'completed_interviews' => (clone $interviews)->where('status', 'completed')->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics retrieved successfully',
            'data' => $data,
        ]);
    }
}
