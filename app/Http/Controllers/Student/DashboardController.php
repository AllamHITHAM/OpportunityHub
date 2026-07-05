<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        $applications = $studentProfile->applications();

        $data = [
            'total_applications' => (clone $applications)->count(),
            'pending_applications' => (clone $applications)->where('status', 'pending')->count(),
            'reviewed_applications' => (clone $applications)->where('status', 'reviewed')->count(),
            'shortlisted_applications' => (clone $applications)->where('status', 'shortlisted')->count(),
            'accepted_applications' => (clone $applications)->where('status', 'accepted')->count(),
            'rejected_applications' => (clone $applications)->where('status', 'rejected')->count(),
            'total_cvs' => $studentProfile->cvs()->count(),
            'total_skills' => $studentProfile->studentSkills()->count(),
            'total_interviews' => Interview::whereHas('application', function ($query) use ($studentProfile) {
                $query->where('student_id', $studentProfile->id);
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Dashboard statistics retrieved successfully',
            'data' => $data,
        ]);
    }
}
