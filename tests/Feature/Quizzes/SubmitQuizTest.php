<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6B-3: POST /api/student/quizzes/{quiz}/submit -- auto-grading,
 * status/result transitions, and submission validation together (mirrors
 * StoreAssessmentTest's convention of covering success + validation in one
 * file per resource-creation endpoint).
 */
class SubmitQuizTest extends TestCase
{
    use RefreshDatabase;

    // ---- D. Grading ----------------------------------------------------

    public function test_all_correct_scores_100(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quiz submitted successfully');
        // Phase 10A.4A: `score` is no longer revealed in the submit
        // response until a ready Organization decision exists (see
        // ResultReleaseTest) -- grading correctness is asserted directly
        // against the persisted attempt instead.
        $this->assertSame(
            100,
            QuizAttempt::where('quiz_id', $scenario['quiz']->id)->value('score'),
        );
    }

    public function test_partial_score_is_computed_correctly(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'False'],
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            50,
            QuizAttempt::where('quiz_id', $scenario['quiz']->id)->value('score'),
        );
    }

    public function test_all_incorrect_scores_zero(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'London'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'False'],
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            0,
            QuizAttempt::where('quiz_id', $scenario['quiz']->id)->value('score'),
        );
    }

    public function test_exact_rounding_behavior_rounds_half_up(): void
    {
        // 8 equal-weight true_false questions, 1 correct -> 12.5% exactly,
        // which PHP's round() (round-half-away-from-zero) rounds to 13.
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, 'published');

        $questions = [];
        for ($i = 0; $i < 8; $i++) {
            $questions[] = $quiz->questions()->create([
                'prompt' => "Question {$i}",
                'type' => 'true_false',
                'correct_answer' => 'True',
                'points' => 1,
                'position' => $i,
            ]);
        }

        $scenario = ['student' => $student, 'assessment' => $assessment->fresh('application'), 'quiz' => $quiz];
        $this->start($scenario);

        $answers = [];
        foreach ($questions as $index => $question) {
            $answers[] = [
                'question_id' => $question->id,
                'answer' => $index === 0 ? 'True' : 'False',
            ];
        }

        $response = $this->submit($scenario, $answers);

        $response->assertStatus(200);
        $this->assertSame(
            13,
            QuizAttempt::where('quiz_id', $scenario['quiz']->id)->value('score'),
        );
    }

    public function test_score_meeting_passing_threshold_passes(): void
    {
        $scenario = $this->twoQuestionScenario(passingScore: 50);
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'False'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('assessments', [
            'id' => $scenario['assessment']->id,
            'result' => 'passed',
        ]);
    }

    public function test_score_below_passing_threshold_fails(): void
    {
        $scenario = $this->twoQuestionScenario(passingScore: 60);
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'London'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('assessments', [
            'id' => $scenario['assessment']->id,
            'result' => 'failed',
        ]);
    }

    public function test_assessment_status_becomes_completed(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('assessments', [
            'id' => $scenario['assessment']->id,
            'status' => 'completed',
        ]);
    }

    public function test_completed_at_is_set(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);

        $assessment = $scenario['assessment']->fresh();
        $this->assertNotNull($assessment->completed_at);
    }

    public function test_attempt_score_and_answers_are_stored(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);

        $attempt = QuizAttempt::where('quiz_id', $scenario['quiz']->id)->firstOrFail();
        $this->assertSame(100, $attempt->score);
        $this->assertNotNull($attempt->submitted_at);
        $this->assertCount(2, $attempt->answers);
    }

    public function test_application_remains_in_assessment_after_grading(): void
    {
        $scenario = $this->twoQuestionScenario();
        $applicationId = $scenario['assessment']->application_id;
        $this->start($scenario);

        $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'status' => 'in_assessment',
        ]);
    }

    // ---- E. Validation ---------------------------------------------------

    public function test_missing_answers_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->postAsStudent($scenario, []);

        $response->assertStatus(422)->assertJsonValidationErrors(['answers']);
    }

    public function test_duplicate_question_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['mcq']->id, 'answer' => 'London'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('answers.1.question_id', $response->json('errors'));
    }

    public function test_foreign_question_id_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => 999999, 'answer' => 'True'],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('answers.1.question_id', $response->json('errors'));
    }

    public function test_an_unanswered_quiz_question_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('answers', $response->json('errors'));
    }

    public function test_invalid_mcq_option_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Madrid'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('answers.0.answer', $response->json('errors'));
    }

    public function test_invalid_true_false_answer_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'Maybe'],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('answers.1.answer', $response->json('errors'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function trueFalseCasingVariants(): array
    {
        return [
            'lowercase true' => ['true', 'True'],
            'uppercase FALSE' => ['FALSE', 'False'],
        ];
    }

    #[DataProvider('trueFalseCasingVariants')]
    public function test_true_false_answer_is_canonicalized_before_grading(string $submitted, string $expectedStored): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => $submitted],
        ]);

        $response->assertStatus(200);
        $attempt = QuizAttempt::where('quiz_id', $scenario['quiz']->id)->firstOrFail();
        $stored = collect($attempt->answers)->firstWhere('question_id', $scenario['tf']->id);
        $this->assertSame($expectedStored, $stored['answer']);
    }

    public function test_double_submit_returns_409(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);
        $answers = [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ];
        $this->submit($scenario, $answers)->assertStatus(200);

        $response = $this->submit($scenario, $answers);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz has already been submitted');
    }

    public function test_submit_without_start_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario();

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Start the quiz before submitting.');

        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    public function test_expired_time_limit_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario(timeLimitMinutes: 30);
        $this->start($scenario);

        $attempt = QuizAttempt::where('quiz_id', $scenario['quiz']->id)->firstOrFail();
        $attempt->started_at = now()->subMinutes(31);
        $attempt->save();

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz time limit has expired');

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $attempt->id,
            'submitted_at' => null,
        ]);
    }

    public function test_submission_within_the_time_limit_succeeds(): void
    {
        $scenario = $this->twoQuestionScenario(timeLimitMinutes: 30);
        $this->start($scenario);

        $attempt = QuizAttempt::where('quiz_id', $scenario['quiz']->id)->firstOrFail();
        $attempt->started_at = now()->subMinutes(29);
        $attempt->save();

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(200);
    }

    public function test_no_time_limit_quiz_never_expires(): void
    {
        $scenario = $this->twoQuestionScenario(timeLimitMinutes: null);
        $this->start($scenario);

        $attempt = QuizAttempt::where('quiz_id', $scenario['quiz']->id)->firstOrFail();
        $attempt->started_at = now()->subDays(30);
        $attempt->save();

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ]);

        $response->assertStatus(200);
    }

    public function test_wrong_student_is_denied(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $otherStudent = $this->studentWithProfileAndCv();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/submit", [
            'answers' => [
                ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
                ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
            ],
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');
    }

    public function test_client_supplied_points_and_score_are_ignored(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->start($scenario);

        $response = $this->submit($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'London', 'points' => 999, 'score' => 100],
            ['question_id' => $scenario['tf']->id, 'answer' => 'False', 'correct_answer' => 'False'],
        ]);

        // Both answers are wrong -- the client's own bogus points/score/
        // correct_answer fields are never read, only `question_id`/`answer`.
        $response->assertStatus(200);
        $this->assertSame(
            0,
            QuizAttempt::where('quiz_id', $scenario['quiz']->id)->value('score'),
        );
    }

    // ---- Helpers -----------------------------------------------------

    private function approvedOrganization(): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function opportunityFor(object $org, array $overrides = []): Opportunity
    {
        return $org->profile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        $cv = $profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function applicationForStudent(Opportunity $opportunity, object $student, string $status = 'in_assessment'): Application
    {
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    /**
     * @return array{0: Assessment, 1: Quiz}
     */
    private function quizFor(Application $application, string $status, int $passingScore = 50, ?int $timeLimitMinutes = null): array
    {
        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => $status === 'published' ? 'scheduled' : 'pending',
            'result' => null,
        ]);

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'time_limit_minutes' => $timeLimitMinutes,
            'passing_score' => $passingScore,
            'status' => $status,
        ]);

        return [$assessment, $quiz];
    }

    /**
     * A published quiz with one 1-point multiple_choice question
     * ("Paris") and one 1-point true_false question ("True") -- total 2
     * points, so scores land on clean 0/50/100 values.
     *
     * @return array{student: object, assessment: Assessment, quiz: Quiz, mcq: \App\Models\Question, tf: \App\Models\Question}
     */
    private function twoQuestionScenario(int $passingScore = 50, ?int $timeLimitMinutes = null): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, 'published', $passingScore, $timeLimitMinutes);

        $mcq = $quiz->questions()->create([
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
            'points' => 1,
            'position' => 0,
        ]);

        $tf = $quiz->questions()->create([
            'prompt' => 'The sky is blue.',
            'type' => 'true_false',
            'correct_answer' => 'True',
            'points' => 1,
            'position' => 1,
        ]);

        return [
            'student' => $student,
            'assessment' => $assessment->fresh('application'),
            'quiz' => $quiz,
            'mcq' => $mcq,
            'tf' => $tf,
        ];
    }

    private function start(array $scenario): void
    {
        Sanctum::actingAs($scenario['student']->user);
        $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/start")->assertStatus(201);
    }

    private function submit(array $scenario, array $answers): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($scenario['student']->user);

        return $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/submit", [
            'answers' => $answers,
        ]);
    }

    private function postAsStudent(array $scenario, array $body): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($scenario['student']->user);

        return $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/submit", $body);
    }
}
