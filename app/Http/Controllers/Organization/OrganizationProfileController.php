<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->organizationProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Organization profile not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organization profile retrieved successfully',
            'data' => $profile,
        ]);
    }

    public function update(UpdateOrganizationProfileRequest $request): JsonResponse
    {
        $profile = $request->user()->organizationProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Organization profile not found',
                'data' => null,
            ], 404);
        }

        $profile->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Organization profile updated successfully',
            'data' => $profile,
        ]);
    }
}
