<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrganizationApprovalRequest;
use App\Models\OrganizationProfile;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    public function index(): JsonResponse
    {
        $organizations = OrganizationProfile::with('user')->get();

        return response()->json([
            'success' => true,
            'message' => 'Organizations retrieved successfully',
            'data' => $organizations,
        ]);
    }

    public function show(OrganizationProfile $organizationProfile): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Organization retrieved successfully',
            'data' => $organizationProfile->load('user'),
        ]);
    }

    public function updateApproval(UpdateOrganizationApprovalRequest $request, OrganizationProfile $organizationProfile): JsonResponse
    {
        $organizationProfile->approval_status = $request->validated('approval_status');
        $organizationProfile->save();

        return response()->json([
            'success' => true,
            'message' => 'Organization approval status updated successfully',
            'data' => $organizationProfile->fresh('user'),
        ]);
    }
}
