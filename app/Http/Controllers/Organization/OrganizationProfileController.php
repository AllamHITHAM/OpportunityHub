<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateOrganizationProfileRequest;
use App\Http\Requests\Organization\UploadOrganizationLogoRequest;
use App\Services\ImageStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationProfileController extends Controller
{
    public function __construct(private readonly ImageStorageService $images)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->organizationProfile?->loadMissing('locationRecord');

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
            'data' => $profile->fresh('locationRecord'),
        ]);
    }

    /**
     * Uploads (or replaces) the Company Logo -- real, server-validated
     * multipart image upload (see `UploadOrganizationLogoRequest`), a
     * fresh random filename, stored on the public disk via
     * `ImageStorageService`. The previously-stored logo file, if any, is
     * deleted only *after* the new one is safely persisted to the
     * profile -- so a mid-request failure can never leave the profile
     * pointing at a file that no longer exists.
     */
    public function uploadLogo(UploadOrganizationLogoRequest $request): JsonResponse
    {
        $profile = $request->user()->organizationProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Organization profile not found',
                'data' => null,
            ], 404);
        }

        $oldLogoPath = $profile->logo;

        $storedPath = $this->images->store($request->file('logo'), "organization-logos/{$profile->id}");
        $profile->update(['logo' => $storedPath]);

        $this->images->delete($oldLogoPath);

        return response()->json([
            'success' => true,
            'message' => 'Company logo updated successfully',
            'data' => $profile->fresh('locationRecord'),
        ]);
    }

    /**
     * Removes the Company Logo, reverting to the existing initials
     * fallback -- deletes the managed file and clears the column. A
     * no-op (still 200, still returns the profile) when no logo was set.
     */
    public function removeLogo(Request $request): JsonResponse
    {
        $profile = $request->user()->organizationProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Organization profile not found',
                'data' => null,
            ], 404);
        }

        $this->images->delete($profile->logo);
        $profile->update(['logo' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Company logo removed successfully',
            'data' => $profile->fresh('locationRecord'),
        ]);
    }
}
