<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentProfileRequest;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use App\Http\Requests\Student\UploadStudentProfilePhotoRequest;
use App\Services\ImageStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    public function __construct(private readonly ImageStorageService $images)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found',
                'data' => null,
            ], 404);
        }

        // Phase O8.2: eager-loaded and serialized directly -- unlike
        // `educationVerification`, a Location Catalog row has no internal-
        // only field to hide, so this needs none of that relation's
        // hidden-relation/derived-attribute dance.
        $profile->load([
            'availableLocations:id,canonical_name',
            'currentLocation:id,canonical_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Student profile retrieved successfully',
            'data' => $profile,
        ]);
    }

    public function store(StoreStudentProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile already exists',
                'data' => null,
            ], 409);
        }

        $data = $request->validated();
        $availableLocationIds = $data['available_location_ids'] ?? null;
        unset($data['available_location_ids']);

        $profile = $user->studentProfile()->create($data);

        if ($availableLocationIds !== null) {
            $profile->availableLocations()->sync($availableLocationIds);
        }

        $profile->load([
            'availableLocations:id,canonical_name',
            'currentLocation:id,canonical_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Student profile created successfully',
            'data' => $profile,
        ], 201);
    }

    public function update(UpdateStudentProfileRequest $request): JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found',
                'data' => null,
            ], 404);
        }

        $data = $request->validated();
        // Phase O8.2: `array_key_exists`, not `isset`/`??` -- an
        // explicitly-sent empty array is a real "clear my available
        // locations" instruction, distinct from the key being absent
        // entirely (which leaves the existing selection untouched). Same
        // convention `OpportunityController::update()` already uses for
        // `eligible_majors`.
        $hasAvailableLocationIds = array_key_exists('available_location_ids', $data);
        $availableLocationIds = $data['available_location_ids'] ?? null;
        unset($data['available_location_ids']);

        $profile->update($data);

        if ($hasAvailableLocationIds) {
            $profile->availableLocations()->sync($availableLocationIds);
        }

        $profile->load([
            'availableLocations:id,canonical_name',
            'currentLocation:id,canonical_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Student profile updated successfully',
            'data' => $profile,
        ]);
    }

    /**
     * Uploads (or replaces) the Student's Profile Photo -- mirrors
     * `Organization\OrganizationProfileController::uploadLogo()` exactly:
     * a real, server-validated multipart image upload (see
     * `UploadStudentProfilePhotoRequest`), a fresh random filename,
     * stored on the public disk via `ImageStorageService`. The
     * previously-stored photo file, if any, is deleted only *after* the
     * new one is safely persisted to the profile -- so a mid-request
     * failure can never leave the profile pointing at a file that no
     * longer exists.
     */
    public function uploadPhoto(UploadStudentProfilePhotoRequest $request): JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found',
                'data' => null,
            ], 404);
        }

        $oldPhotoPath = $profile->profile_image;

        $storedPath = $this->images->store($request->file('photo'), "student-profile-photos/{$profile->id}");
        $profile->update(['profile_image' => $storedPath]);

        $this->images->delete($oldPhotoPath);

        $profile->load([
            'availableLocations:id,canonical_name',
            'currentLocation:id,canonical_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated successfully',
            'data' => $profile,
        ]);
    }

    /**
     * Removes the Student's Profile Photo, reverting to the existing
     * initials fallback -- deletes the managed file and clears the
     * column. A no-op (still 200, still returns the profile) when no
     * photo was set. Mirrors
     * `Organization\OrganizationProfileController::removeLogo()` exactly.
     */
    public function removePhoto(Request $request): JsonResponse
    {
        $profile = $request->user()->studentProfile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found',
                'data' => null,
            ], 404);
        }

        $this->images->delete($profile->profile_image);
        $profile->update(['profile_image' => null]);

        $profile->load([
            'availableLocations:id,canonical_name',
            'currentLocation:id,canonical_name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed successfully',
            'data' => $profile,
        ]);
    }
}
