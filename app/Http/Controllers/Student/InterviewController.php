<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $studentId = $request->user()->studentProfile->id;

        $interviews = Interview::whereHas('application', function ($query) use ($studentId) {
            $query->where('student_id', $studentId);
        })->with('application.opportunity')->get();

        return response()->json([
            'success' => true,
            'message' => 'Interviews retrieved successfully',
            'data' => $interviews,
        ]);
    }
}
