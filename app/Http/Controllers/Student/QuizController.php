<?php

namespace App\Http\Controllers\Student;

use App\Exceptions\QuizAlreadySubmittedException;
use App\Exceptions\QuizAttemptNotStartedException;
use App\Exceptions\QuizTimeLimitExpiredException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Student\Concerns\HidesInternalQuestionFields;
use App\Http\Requests\Student\SubmitQuizRequest;
use App\Models\Application;
use App\Models\Assessment;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Student-side Quiz taking: viewing a published quiz (with `correct_answer`
 * stripped from every question -- see `HidesInternalQuestionFields`),
 * starting the one attempt Quiz v1 allows, and submitting it for immediate
 * server-side auto-grading. No retake, no manual-grading, and no
 * answer-review endpoint exist -- see docs/BUSINESS_RULES.md.
 */
class QuizController extends Controller
{
    use HidesInternalQuestionFields;

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * `GET /student/assessments/{assessment}/quiz`. Deliberately returns
     * the same 404 for "not your application", "not a quiz assessment",
     * "no quiz yet", and "quiz still draft" -- never revealing which case
     * applies, the same "prefer not revealing" posture
     * `Organization\QuizController::show()` already takes for its own
     * not-found case.
     */
    public function show(Assessment $assessment, Request $request): JsonResponse
    {
        $assessment->loadMissing('application');
        $studentId = $request->user()->studentProfile->id;

        if ($assessment->application->student_id !== $studentId) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($assessment->type !== 'quiz') {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        $quiz = $assessment->quiz()->with('questions')->first();

        if ($quiz === null || $quiz->status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        $this->hideInternalQuestionFields($quiz);

        return response()->json([
            'success' => true,
            'message' => 'Quiz retrieved successfully',
            'data' => $quiz,
        ]);
    }

    /**
     * `POST /student/quizzes/{quiz}/start`. Idempotent for an in-progress
     * attempt: a second call returns the same attempt as-is (`started_at`
     * is never reset, no extra time is granted) rather than creating a
     * duplicate or erroring -- this is what lets the Flutter app safely
     * re-open/refresh mid-attempt. A submitted attempt can never be
     * restarted (Quiz v1 has no retakes).
     */
    public function start(Quiz $quiz, Request $request): JsonResponse
    {
        $application = $this->ownedApplicationFor($quiz, $request);

        if ($application === null) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        if ($quiz->status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        $existing = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('application_id', $application->id)
            ->first();

        if ($existing !== null) {
            if ($existing->submitted_at !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Quiz has already been submitted',
                    'data' => null,
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Quiz attempt resumed',
                'data' => $existing,
            ]);
        }

        try {
            $attempt = DB::transaction(function () use ($quiz, $application) {
                $created = QuizAttempt::create([
                    'quiz_id' => $quiz->id,
                    'application_id' => $application->id,
                    'started_at' => now(),
                ]);

                $quiz->assessment->status = 'in_progress';
                $quiz->assessment->save();

                return $created;
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateAttemptViolation($e)) {
                // Lost a concurrent race to start the same quiz -- the
                // other request's attempt is exactly as valid as the one
                // this request would have created, so return it rather
                // than surfacing a raw conflict for what is, from the
                // student's point of view, a successful "resume".
                return response()->json([
                    'success' => true,
                    'message' => 'Quiz attempt resumed',
                    'data' => QuizAttempt::where('quiz_id', $quiz->id)
                        ->where('application_id', $application->id)
                        ->firstOrFail(),
                ]);
            }

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Quiz started successfully',
            'data' => $attempt,
        ], 201);
    }

    /**
     * `POST /student/quizzes/{quiz}/submit`. Grades entirely server-side
     * from each question's own `correct_answer` -- the request body only
     * ever supplies `question_id`/`answer` pairs (see `SubmitQuizRequest`),
     * never points/score/correctness. Locks the attempt row for the
     * duration of the grading transaction so a genuine concurrent
     * double-submit is rejected the same way a simple repeat request is.
     */
    public function submit(SubmitQuizRequest $request, Quiz $quiz): JsonResponse
    {
        $application = $this->ownedApplicationFor($quiz, $request);

        if ($application === null) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        $quiz->loadMissing('questions');

        try {
            $attempt = DB::transaction(function () use ($quiz, $application, $request) {
                $locked = QuizAttempt::where('quiz_id', $quiz->id)
                    ->where('application_id', $application->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    throw new QuizAttemptNotStartedException();
                }

                if ($locked->submitted_at !== null) {
                    throw new QuizAlreadySubmittedException();
                }

                if ($quiz->time_limit_minutes !== null) {
                    $deadline = $locked->started_at->copy()->addMinutes($quiz->time_limit_minutes);
                    if (now()->greaterThan($deadline)) {
                        throw new QuizTimeLimitExpiredException();
                    }
                }

                $submitted = collect($request->validated('answers'))
                    ->keyBy(fn (array $answer) => (int) $answer['question_id']);

                $totalPoints = $quiz->questions->sum('points');
                $earnedPoints = 0;
                $normalizedAnswers = [];

                foreach ($quiz->questions as $question) {
                    $answerValue = $submitted[$question->id]['answer'] ?? null;
                    $normalizedAnswers[] = [
                        'question_id' => $question->id,
                        'answer' => $answerValue,
                    ];

                    if ($answerValue === $question->correct_answer) {
                        $earnedPoints += $question->points;
                    }
                }

                // Guards against division by zero -- SubmitQuizRequest
                // already requires every question to be answered, and
                // question creation already requires points >= 1, so a
                // real quiz can never actually have zero total points; this
                // is defense-in-depth, not a reachable v1 scenario.
                $score = $totalPoints > 0
                    ? (int) round($earnedPoints / $totalPoints * 100)
                    : 0;

                $locked->answers = $normalizedAnswers;
                $locked->score = $score;
                $locked->submitted_at = now();
                $locked->save();

                $assessment = $quiz->assessment;
                $assessment->status = 'completed';
                $assessment->result = $score >= $quiz->passing_score ? 'passed' : 'failed';
                $assessment->completed_at = now();
                $assessment->save();

                // Phase 7A-2: only reached on a genuine first submission --
                // every early-exit above (not started, already submitted,
                // time limit expired) throws before this point, so exactly
                // one pair of notifications is created per real submit.
                $studentUser = $application->studentProfile->user;
                $organizationUser = $application->opportunity->organizationProfile->user;
                $opportunityTitle = $application->opportunity->title;

                $this->notifications->notifyQuizResultAvailable(
                    $studentUser,
                    $opportunityTitle,
                    $application->id,
                );
                $this->notifications->notifyQuizCompleted(
                    $organizationUser,
                    $studentUser->name,
                    $opportunityTitle,
                    $application->id,
                );

                return $locked;
            });
        } catch (QuizAttemptNotStartedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        } catch (QuizAlreadySubmittedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 409);
        } catch (QuizTimeLimitExpiredException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quiz submitted successfully',
            'data' => $attempt,
        ]);
    }

    /**
     * Resolves the authenticated student's own application for [quiz], or
     * `null` if the quiz doesn't belong to one of their applications --
     * every caller turns that into an identical 404, never revealing
     * whether the quiz exists at all for a student who doesn't own it.
     */
    private function ownedApplicationFor(Quiz $quiz, Request $request): ?Application
    {
        $quiz->loadMissing('assessment.application');
        $studentId = $request->user()->studentProfile->id;
        $application = $quiz->assessment->application;

        return $application->student_id === $studentId ? $application : null;
    }

    /**
     * Narrowly confirms a QueryException is the
     * `quiz_attempts_quiz_id_application_id_unique` violation -- the
     * concurrency-race counterpart to the pre-check in `start()` -- before
     * treating it as "someone already started this quiz". Any other
     * integrity-constraint failure is rethrown untouched.
     */
    private function isDuplicateAttemptViolation(QueryException $e): bool
    {
        if ($e->getCode() !== '23000') {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'quiz_attempts_quiz_id_application_id_unique')
            || str_contains($message, 'quiz_attempts.quiz_id');
    }
}
