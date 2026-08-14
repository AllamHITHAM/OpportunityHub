<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\HidesInternalApplicationFields;
use App\Http\Controllers\Student\Concerns\HidesInternalInterviewFields;
use App\Models\Interview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterviewController extends Controller
{
    use HidesInternalApplicationFields;
    use HidesInternalInterviewFields;

    public function index(Request $request): JsonResponse
    {
        $studentId = $request->user()->studentProfile->id;

        $interviews = Interview::whereHas('assessment.application', function ($query) use ($studentId) {
            $query->where('student_id', $studentId);
        })->with('assessment.application.opportunity')->get();

        $interviews->each(function (Interview $interview) {
            $this->hideInternalInterviewFields($interview);
            // Hides `match_score` on the same Application instance the
            // nested `assessment.application` *and* the backward-compat
            // top-level `application` accessor both resolve to (see
            // `Interview::application()` -- it reads
            // `$this->assessment?->application`, the identical loaded
            // relation instance), so one call covers both serialized keys.
            $this->hideInternalApplicationFields($interview->assessment->application);
        });

        return response()->json([
            'success' => true,
            'message' => 'Interviews retrieved successfully',
            'data' => $interviews,
        ]);
    }
}
