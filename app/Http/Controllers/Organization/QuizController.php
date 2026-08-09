<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreQuestionRequest;
use App\Http\Requests\Organization\UpdateQuestionRequest;
use App\Models\Assessment;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Organization-only Quiz authoring: viewing a quiz, adding/updating/
 * deleting its questions while still a draft, and publishing it. There is
 * no Student endpoint here or anywhere yet -- attempt/submission is a later
 * phase (see docs/BUSINESS_RULES.md).
 */
class QuizController extends Controller
{
    /**
     * The quiz (if any) belonging to a specific assessment the organization
     * owns. Mirrors `AssessmentController::showForApplication()`'s
     * null-data convention rather than 404ing when the assessment exists
     * but has no quiz (e.g. `type=interview`).
     */
    public function show(Assessment $assessment, Request $request): JsonResponse
    {
        $assessment->loadMissing('application.opportunity');

        if ($assessment->application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Assessment not found',
                'data' => null,
            ], 404);
        }

        $quiz = $assessment->quiz()->with('questions')->first();

        return response()->json([
            'success' => true,
            'message' => $quiz === null
                ? 'This assessment has no quiz yet'
                : 'Quiz retrieved successfully',
            'data' => $quiz,
        ]);
    }

    public function storeQuestion(StoreQuestionRequest $request, Quiz $quiz): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Published quizzes cannot be modified',
                'data' => null,
            ], 422);
        }

        $question = $quiz->questions()->create($this->questionAttributes($request));

        return response()->json([
            'success' => true,
            'message' => 'Question added successfully',
            'data' => $question,
        ], 201);
    }

    public function updateQuestion(UpdateQuestionRequest $request, Quiz $quiz, Question $question): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($question->quiz_id !== $quiz->id) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Published quizzes cannot be modified',
                'data' => null,
            ], 422);
        }

        $question->update($this->questionAttributes($request));

        return response()->json([
            'success' => true,
            'message' => 'Question updated successfully',
            'data' => $question->fresh(),
        ]);
    }

    public function destroyQuestion(Quiz $quiz, Question $question, Request $request): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($question->quiz_id !== $quiz->id) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Published quizzes cannot be modified',
                'data' => null,
            ], 422);
        }

        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Every question already had to pass `StoreQuestionRequest`/
     * `UpdateQuestionRequest` validation to exist at all, so a question
     * count of at least one is the only "structurally valid" check left to
     * make here -- there is no way for an invalid question to be in the
     * database by the time this runs.
     */
    public function publish(Quiz $quiz, Request $request): JsonResponse
    {
        if (! $this->ownsQuiz($quiz, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Only draft quizzes can be published',
                'data' => null,
            ], 422);
        }

        if ($quiz->questions()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'A quiz must have at least one question before it can be published',
                'data' => null,
            ], 422);
        }

        DB::transaction(function () use ($quiz) {
            $quiz->status = 'published';
            $quiz->save();

            // Application.status is left untouched -- it is already
            // `in_assessment` from quiz creation and stays there; only the
            // Assessment's own generic lifecycle advances here.
            $quiz->assessment->status = 'scheduled';
            $quiz->assessment->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Quiz published successfully',
            'data' => $quiz->fresh('questions')->load('assessment.application'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function questionAttributes(StoreQuestionRequest|UpdateQuestionRequest $request): array
    {
        $data = $request->validated();
        $data['points'] = $data['points'] ?? 1;
        $data['position'] = $data['position'] ?? 0;

        // true_false never stores options -- its two choices are fixed and
        // never held per-row (see Question model docblock) -- regardless of
        // whatever the client submitted for that field.
        if ($data['type'] === 'true_false') {
            $data['options'] = null;
        }

        return $data;
    }

    private function ownsQuiz(Quiz $quiz, Request $request): bool
    {
        $quiz->loadMissing('assessment.application.opportunity');

        return $quiz->assessment->application->opportunity->organization_id
            === $request->user()->organizationProfile->id;
    }
}
