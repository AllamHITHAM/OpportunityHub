<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentProfileRequest;
use App\Http\Requests\Student\UpdateStudentProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
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
}
