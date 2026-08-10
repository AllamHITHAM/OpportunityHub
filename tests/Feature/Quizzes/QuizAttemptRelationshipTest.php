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
use Tests\TestCase;

/**
 * Phase 6B-3: migration/model coverage for `quiz_attempts` -- mirrors
 * QuizRelationshipTest's structure and conventions.
 */
class QuizAttemptRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_quiz_attempts_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('quiz_attempts'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('quiz_attempts', [
            'id', 'quiz_id', 'application_id', 'answers', 'score',
            'started_at', 'submitted_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_quiz_has_many_attempts(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $this->assertInstanceOf(QuizAttempt::class, $quiz->attempts->first());
        $this->assertSame($attempt->id, $quiz->attempts->first()->id);
    }

    public function test_attempt_belongs_to_quiz(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $this->assertInstanceOf(Quiz::class, $attempt->quiz);
        $this->assertSame($quiz->id, $attempt->quiz->id);
    }

    public function test_application_has_many_quiz_attempts(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $this->assertSame($attempt->id, $application->quizAttempts->first()->id);
    }

    public function test_attempt_belongs_to_application(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $this->assertInstanceOf(Application::class, $attempt->application);
        $this->assertSame($application->id, $attempt->application->id);
    }

    public function test_quiz_id_and_application_id_pair_is_unique(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);
    }

    public function test_deleting_a_quiz_cascades_to_its_attempts(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);
        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $quiz->delete();

        $this->assertDatabaseMissing('quiz_attempts', ['id' => $attempt->id]);
    }

    public function test_deleting_an_application_cascades_to_its_quiz_attempts(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);
        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $application->delete();

        $this->assertDatabaseMissing('quiz_attempts', ['id' => $attempt->id]);
    }

    public function test_answers_cast_to_an_array(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
            'answers' => [['question_id' => 1, 'answer' => 'Paris']],
            'score' => 100,
            'submitted_at' => now(),
        ]);

        $this->assertIsArray($attempt->fresh()->answers);
        $this->assertSame('Paris', $attempt->fresh()->answers[0]['answer']);
    }

    public function test_score_started_at_and_submitted_at_cast_correctly(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
            'score' => 80,
            'submitted_at' => now(),
        ]);

        $fresh = $attempt->fresh();
        $this->assertIsInt($fresh->score);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->started_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->submitted_at);
    }

    public function test_answers_and_score_and_submitted_at_are_nullable(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now(),
        ]);

        $fresh = $attempt->fresh();
        $this->assertNull($fresh->answers);
        $this->assertNull($fresh->score);
        $this->assertNull($fresh->submitted_at);
    }

    /**
     * `started_at` must never silently change on a later `save()` -- see
     * the migration's own doc comment about the MySQL
     * `explicit_defaults_for_timestamp=OFF` footgun this guards against.
     * Grading (Student\QuizController::submit()) saves the same row again
     * to set `answers`/`score`/`submitted_at`, so this is a direct
     * regression guard for that exact scenario.
     */
    public function test_started_at_does_not_change_on_a_later_save(): void
    {
        $application = $this->applicationFor($this->opportunityFor($this->approvedOrganization()));
        $quiz = $this->publishedQuizFor($application);

        $startedAt = now()->subMinutes(10);
        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => $startedAt,
        ]);

        $attempt->score = 90;
        $attempt->submitted_at = now();
        $attempt->save();

        $this->assertSame(
            $startedAt->toDateTimeString(),
            $attempt->fresh()->started_at->toDateTimeString(),
        );
    }

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

    private function applicationFor(Opportunity $opportunity, string $status = 'in_assessment'): Application
    {
        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $studentProfile = StudentProfile::create(['user_id' => $studentUser->id]);

        $cv = $studentProfile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        $application = Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    private function publishedQuizFor(Application $application): Quiz
    {
        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'passing_score' => 70,
            'status' => 'published',
        ]);

        $quiz->questions()->create([
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
            'points' => 1,
            'position' => 0,
        ]);

        return $quiz;
    }
}
