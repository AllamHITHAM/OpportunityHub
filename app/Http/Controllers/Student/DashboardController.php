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
            // Phase 6C-4: makes the final Offer funnel visible alongside
            // the existing accepted/rejected terminal counts -- see
            // docs/BUSINESS_RULES.md section 5 for what each status means.
            'offer_sent_applications' => (clone $applications)->where('status', 'offer_sent')->count(),
            'accepted_applications' => (clone $applications)->where('status', 'accepted')->count(),
            'rejected_applications' => (clone $applications)->where('status', 'rejected')->count(),
            'total_cvs' => $studentProfile->cvs()->count(),
            'total_skills' => $studentProfile->studentSkills()->count(),
            // `Interview` has no direct `application()` *relation* (only a
            // read-only `application` Attribute accessor for JSON
            // serialization -- see Interview.php) since the Phase 4A-1
            // Assessment retarget. The real, queryable chain is
            // `interview -> assessment -> application`.
            'total_interviews' => Interview::whereHas('assessment.application', function ($query) use ($studentProfile) {
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
