<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $studentId = $request->user()->studentProfile->id;

        $assessments = Assessment::whereHas('application', function ($query) use ($studentId) {
            $query->where('student_id', $studentId);
        })->with(['application', 'interview'])->get();

        return response()->json([
            'success' => true,
            'message' => 'Assessments retrieved successfully',
            'data' => $assessments,
        ]);
    }

    public function show(Assessment $assessment, Request $request): JsonResponse
    {
        $studentId = $request->user()->studentProfile->id;

        if ($assessment->application->student_id !== $studentId) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Assessment retrieved successfully',
            'data' => $assessment->load(['application', 'interview']),
        ]);
    }
}
