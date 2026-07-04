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

        $profile = $user->studentProfile()->create($request->validated());

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

        $profile->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Student profile updated successfully',
            'data' => $profile,
        ]);
    }
}
