<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\HidesInternalApplicationFields;
use App\Http\Controllers\Student\Concerns\HidesInternalInterviewFields;
use App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields;
use App\Http\Controllers\Student\Concerns\HidesUnreleasedQuizResult;
use App\Models\Assessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    use HidesInternalApplicationFields;
    use HidesInternalInterviewFields;
    use HidesInternalQuestionFields;
    use HidesUnreleasedQuizResult;

    public function index(Request $request): JsonResponse
    {
        $studentId = $request->user()->studentProfile->id;

        $assessments = Assessment::whereHas('application', function ($query) use ($studentId) {
            $query->where('student_id', $studentId);
        })
            // Phase 10A.4A: a staged decision's follow-up Assessment (e.g.
            // an "Advance to Interview" Interview created ahead of its
            // origin Quiz's release) must never appear here before that
            // origin is actually released -- see
            // `Assessment::isPendingDecisionRelease()`. A plain Assessment
            // with no `origin_assessment_id` at all (every pre-10A.4A row,
            // and every directly-created Interview -- Path A) is always
            // included, unaffected.
            ->where(function ($query) {
                $query->whereNull('origin_assessment_id')
                    ->orWhereHas(
                        'originAssessment',
                        fn ($originQuery) => $originQuery->whereNotNull('result_released_at'),
                    );
            })
            ->orderBy('created_at')->orderBy('id')->with([
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
            // Phase 10A.4B: resolves `quiz` for an Assessment referencing a
            // shared Opportunity Quiz template before anything below reads
            // it -- a no-op for every legacy Assessment.
            $assessment->withResolvedQuizRelation();
            $this->hideInternalInterviewFields($assessment->interview);
            $this->attachAvailabilityAndGateQuestions($assessment);
            $this->hideInternalApplicationFields($assessment->application);
            $this->hideUnreleasedQuizResult($assessment);
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

        // Phase 10A.4A: the same "not found" response a wrong-owner request
        // gets -- never a distinct error that would reveal a staged
        // decision's follow-up Assessment exists at all before its origin
        // is released.
        $assessment->loadMissing('originAssessment');
        if ($assessment->isPendingDecisionRelease()) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        $assessment->load(['application.opportunity', 'application.cv', 'interview', 'quiz.questions']);
        // Phase 10A.4B: see the identical call in index().
        $assessment->withResolvedQuizRelation();
        $this->hideInternalInterviewFields($assessment->interview);
        $this->attachAvailabilityAndGateQuestions($assessment);
        $this->hideInternalApplicationFields($assessment->application);
        $this->hideUnreleasedQuizResult($assessment);

        return response()->json([
            'success' => true,
            'message' => 'Assessment retrieved successfully',
            'data' => $assessment,
        ]);
    }

    /**
     * Phase 10A.4B addendum: mirrors `Student\QuizController::show()`'s own
     * gating so this generic endpoint -- which also nests `quiz.questions`
     * -- can never leak question text before a shared-quiz candidate's
     * frozen `available_at`. `available_at`/`due_at` are attached as
     * dynamic attributes (there is no `quiz.available_at` column; these are
     * per-candidate values that live on the Assessment) so the client has a
     * single place to read them regardless of which endpoint it called.
     * `available_at` is `null` for every legacy quiz and for a candidate
     * never advanced through `AssessmentService::advanceToSharedQuiz()`, in
     * which case `Assessment::isUpcoming()` is always false and no
     * gating applies -- unchanged, pre-addendum behavior.
     */
    private function attachAvailabilityAndGateQuestions(Assessment $assessment): void
    {
        $quiz = $assessment->quiz;
        if ($quiz === null) {
            return;
        }

        $quiz->available_at = $assessment->available_at;
        $quiz->due_at = $assessment->due_at;

        if ($assessment->isUpcoming()) {
            $quiz->setRelation('questions', collect());
        } else {
            $this->hideInternalQuestionFields($quiz);
        }
    }
}
