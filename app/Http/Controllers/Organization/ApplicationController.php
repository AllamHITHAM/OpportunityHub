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

        // Phase 6C-4: once an Offer exists, the Application/Offer lifecycle
        // is owned entirely by OfferService (sendOffer/acceptOffer/
        // declineOffer) -- this generic endpoint must never independently
        // move the Application again, in either direction. Without this
        // guard an organization could, e.g., reject an application whose
        // Offer is still `sent`, producing the impossible combination
        // Offer.status=sent + Application.status=rejected. v1 has no Offer
        // cancel/rescind workflow, so this is a hard block, not a
        // conditional one -- see docs/BUSINESS_RULES.md section 7b.
        if ($application->offer()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This application already has an offer; its status can only change through the offer accept/decline endpoints.',
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
