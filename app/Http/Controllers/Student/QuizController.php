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
use App\Services\QuizResultReleaseService;
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
 *
 * Grading (`Assessment.result`) and result *release* (Student visibility
 * of that result -- Phase 10A.2) are deliberately separate: `submit()`
 * always grades synchronously, but only releases immediately when
 * `Quiz.result_release_mode` says to (or a past-due scheduled time) --
 * see `QuizResultReleaseService`. `score`/`result` are hidden from every
 * response this controller returns until released (`HidesInternalQuestionFields`'s
 * sibling concern -- see `hideUnreleasedQuizResult()` below).
 */
class QuizController extends Controller
{
    use HidesInternalQuestionFields;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly QuizResultReleaseService $resultRelease,
    ) {
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

        // Phase 10A.4B: `resolvedQuiz()` -- not the bare legacy `quiz`
        // relation -- since this Assessment may reference an Opportunity's
        // shared Quiz template instead of owning a private one.
        $quiz = $assessment->quiz_id !== null
            ? $assessment->sharedQuiz()->with('questions')->first()
            : $assessment->quiz()->with('questions')->first();

        if ($quiz === null || $quiz->status !== 'published') {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not found',
                'data' => null,
            ], 404);
        }

        // Phase 10A.4B addendum: `available_at`/`due_at` live on the
        // Assessment (candidate-specific), not the Quiz -- attached here as
        // extra response attributes so the Student's quiz screen can render
        // the real schedule without a second request, the same established
        // pattern `QuizModel.assessmentStatus` already uses for the publish
        // response. `null`/`null` for a legacy Assessment -- no gating,
        // exactly the pre-addendum behavior.
        $quiz->available_at = $assessment->available_at;
        $quiz->due_at = $assessment->due_at;

        if ($assessment->isUpcoming()) {
            // Never leak question text/options before the candidate's own
            // window opens -- not just `correct_answer` (already always
            // stripped below), the entire question list.
            $quiz->setRelation('questions', collect());
        } else {
            $this->hideInternalQuestionFields($quiz);
        }

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

        // Phase 10A.4B: the Assessment to move to `in_progress` -- this
        // candidate's own, whether it privately owns `$quiz` (legacy) or
        // merely references it as an Opportunity's shared template. See
        // `assessmentFor()`'s own doc comment.
        $assessment = $this->assessmentFor($quiz, $application);

        // Phase 10A.4B addendum: only gates a genuinely *new* attempt --
        // the resume branch above already returned before this point, so a
        // student who started before their window closed can always come
        // back and see (and still attempt to submit) what they already
        // began; `submit()` independently enforces the real effective
        // cutoff regardless. `available_at`/`due_at` are `null` for every
        // legacy/ad-hoc Assessment, so neither check is ever reachable
        // there -- exactly the pre-addendum behavior.
        if ($assessment->isUpcoming()) {
            return response()->json([
                'success' => false,
                'message' => 'This assessment is not available yet.',
                'data' => null,
            ], 422);
        }
        if ($assessment->isPastDue()) {
            return response()->json([
                'success' => false,
                'message' => 'The submission deadline for this assessment has passed.',
                'data' => null,
            ], 422);
        }

        try {
            $attempt = DB::transaction(function () use ($quiz, $application, $assessment) {
                $created = QuizAttempt::create([
                    'quiz_id' => $quiz->id,
                    'application_id' => $application->id,
                    'started_at' => now(),
                ]);

                $assessment->status = 'in_progress';
                $assessment->save();

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
        // Phase 10A.4B: this candidate's own Assessment -- see
        // `assessmentFor()`'s own doc comment.
        $assessment = $this->assessmentFor($quiz, $application);

        try {
            $attempt = DB::transaction(function () use ($quiz, $application, $request, $assessment) {
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

                // Phase 10A.4B addendum: the real cutoff is whichever of
                // the two independent constraints comes first -- the
                // candidate's own personal timer (`time_limit_minutes`
                // after they personally started), and the shared quiz's
                // candidate-specific submission deadline (`due_at`, `null`
                // for a legacy Assessment). A due-at-bound cutoff gets a
                // more truthful message than the personal-timer one, since
                // "time limit expired" would be misleading when the real
                // cause is the overall deadline having passed instead.
                $personalTimerDeadline = $quiz->time_limit_minutes !== null
                    ? $locked->started_at->copy()->addMinutes($quiz->time_limit_minutes)
                    : null;

                $effectiveDeadline = $assessment->due_at;
                $dueAtIsBinding = $effectiveDeadline !== null;
                if ($personalTimerDeadline !== null
                    && ($effectiveDeadline === null || $personalTimerDeadline->lt($effectiveDeadline))) {
                    $effectiveDeadline = $personalTimerDeadline;
                    $dueAtIsBinding = false;
                }

                if ($effectiveDeadline !== null && now()->greaterThan($effectiveDeadline)) {
                    throw new QuizTimeLimitExpiredException($dueAtIsBinding
                        ? 'The submission deadline for this assessment has passed.'
                        : 'Quiz time limit has expired');
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

                // Phase 10A.2, revised Phase 10A.4A: attempt release right
                // after grading, but `attemptRelease()` now also requires a
                // ready Organization next-step decision -- see
                // `QuizResultReleaseService`'s own doc comment. In practice
                // this is a no-op at submit time unless the Organization
                // somehow already staged a decision before the Student even
                // submitted; the common case (decide-after-submit) releases
                // later, from the decision-completion action or the
                // scheduled job.
                $this->resultRelease->attemptRelease($assessment);

                // Organization-facing "a student completed the quiz" is
                // unconditional -- unrelated to Student result visibility.
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

        // `$assessment` is the same in-memory instance mutated (and, if
        // applicable, released) inside the transaction above -- never
        // reveal `score` here unless it was actually released just now.
        if (! $assessment->isResultReleased()) {
            $attempt->makeHidden(['score']);
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
     *
     * **Phase 10A.4B**: a legacy Quiz still has exactly one owning
     * Assessment (`quiz->assessment`), resolved as before. A *shared*
     * template (`opportunity_id` set) has none of its own -- instead, many
     * candidates' Assessments each reference it via `quiz_id`, so ownership
     * is resolved the other direction: find *this* student's own Assessment
     * among them. See [assessmentFor] for the matching resolution `start()`/
     * `submit()` use once they already have the Application back from here.
     */
    private function ownedApplicationFor(Quiz $quiz, Request $request): ?Application
    {
        $studentId = $request->user()->studentProfile->id;

        if ($quiz->opportunity_id !== null) {
            $assessment = Assessment::where('quiz_id', $quiz->id)
                ->whereHas('application', fn ($q) => $q->where('student_id', $studentId))
                ->with('application')
                ->first();

            return $assessment?->application;
        }

        $quiz->loadMissing('assessment.application');
        $application = $quiz->assessment->application;

        return $application->student_id === $studentId ? $application : null;
    }

    /**
     * The specific candidate Assessment [$application] uses for [quiz] --
     * the legacy, single owning Assessment for an ad-hoc Quiz, or the one
     * among possibly many candidates' Assessments that matches
     * [$application] for a shared template. Always non-null when called
     * with an [$application] [ownedApplicationFor] itself just returned,
     * since both resolve the exact same underlying relationship.
     */
    private function assessmentFor(Quiz $quiz, Application $application): Assessment
    {
        if ($quiz->opportunity_id !== null) {
            return Assessment::where('quiz_id', $quiz->id)
                ->where('application_id', $application->id)
                ->firstOrFail();
        }

        $quiz->loadMissing('assessment');

        return $quiz->assessment;
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
