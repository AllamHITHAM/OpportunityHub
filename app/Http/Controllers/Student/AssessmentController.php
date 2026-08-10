<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\HidesInternalInterviewFields;
use App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    use HidesInternalInterviewFields;
    use HidesInternalQuestionFields;

    public function index(Request $request): JsonResponse
    {
        $studentId = $request->user()->studentProfile->id;

        $assessments = Assessment::whereHas('application', function ($query) use ($studentId) {
            $query->where('student_id', $studentId);
        })->with([
            // The same fully-populated Application shape every other
            // student-facing endpoint returns (see ApplicationController) --
            // `cv` is required by the Flutter client's ApplicationModel.
            // `studentProfile.user` is deliberately not included here: it's
            // the student's own identity, never nested back to them on
            // student-facing responses (see ApplicationModel's docs).
            'application.opportunity',
            'application.cv',
            'interview',
            // As of Phase 6B-3 -- `correct_answer` is stripped from every
            // nested question below, the same way `interview`'s
            // organization-internal fields already are.
            'quiz.questions',
        ])->get();

        $assessments->each(function (Assessment $assessment) {
            $this->hideInternalInterviewFields($assessment->interview);
            $this->hideInternalQuestionFields($assessment->quiz);
        });

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

        $assessment->load(['application.opportunity', 'application.cv', 'interview', 'quiz.questions']);
        $this->hideInternalInterviewFields($assessment->interview);
        $this->hideInternalQuestionFields($assessment->quiz);

        return response()->json([
            'success' => true,
            'message' => 'Assessment retrieved successfully',
            'data' => $assessment,
        ]);
    }
}
