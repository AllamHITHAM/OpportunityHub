<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6B-3: POST /api/student/quizzes/{quiz}/start
 */
class StartQuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_a_quiz_creates_an_attempt(): void
    {
        [$student, , $quiz] = $this->publishedQuizScenario();

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/student/quizzes/{$quiz->id}/start");

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quiz started successfully')
            ->assertJsonPath('data.quiz_id', $quiz->id);

        $this->assertDatabaseCount('quiz_attempts', 1);
    }

    public function test_started_at_is_set(): void
    {
        [$student, , $quiz] = $this->publishedQuizScenario();

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/student/quizzes/{$quiz->id}/start");

        $this->assertNotNull($response->json('data.started_at'));
    }

    public function test_assessment_status_moves_to_in_progress(): void
    {
        [$student, $assessment, $quiz] = $this->publishedQuizScenario();

        Sanctum::actingAs($student->user);

        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);

        $this->assertDatabaseHas('assessments', [
            'id' => $assessment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_application_status_remains_in_assessment(): void
    {
        [$student, $assessment, $quiz] = $this->publishedQuizScenario();
        $applicationId = $assessment->application_id;

        Sanctum::actingAs($student->user);

        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'status' => 'in_assessment',
        ]);
    }

    public function test_repeated_start_returns_the_same_unsubmitted_attempt(): void
    {
        [$student, , $quiz] = $this->publishedQuizScenario();

        Sanctum::actingAs($student->user);

        $first = $this->postJson("/api/student/quizzes/{$quiz->id}/start")->json('data');
        $second = $this->postJson("/api/student/quizzes/{$quiz->id}/start");

        $second->assertStatus(200)
            ->assertJsonPath('data.id', $first['id'])
            ->assertJsonPath('data.started_at', $first['started_at']);

        $this->assertDatabaseCount('quiz_attempts', 1);
    }

    public function test_a_submitted_attempt_cannot_restart(): void
    {
        [$student, $assessment, $quiz] = $this->publishedQuizScenario();
        $application = $assessment->application;
        $quiz->attempts()->create([
            'application_id' => $application->id,
            'started_at' => now()->subMinutes(5),
            'answers' => [],
            'score' => 100,
            'submitted_at' => now(),
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/student/quizzes/{$quiz->id}/start");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz has already been submitted');

        $this->assertDatabaseCount('quiz_attempts', 1);
    }

    public function test_wrong_student_is_denied(): void
    {
        [, , $quiz] = $this->publishedQuizScenario();

        $otherStudent = $this->studentWithProfileAndCv();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->postJson("/api/student/quizzes/{$quiz->id}/start");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');

        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    public function test_draft_quiz_is_denied(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [, $quiz] = $this->quizFor($application, 'draft');
        $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/student/quizzes/{$quiz->id}/start");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');

        $this->assertDatabaseCount('quiz_attempts', 0);
    }

    public function test_guest_receives_401(): void
    {
        [, , $quiz] = $this->publishedQuizScenario();

        $response = $this->postJson("/api/student/quizzes/{$quiz->id}/start");

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
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
    private function quizFor(Application $application, string $status = 'draft'): array
    {
        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => $status === 'published' ? 'scheduled' : 'pending',
            'result' => null,
        ]);

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'passing_score' => 70,
            'status' => $status,
        ]);

        return [$assessment, $quiz];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcqAttributes(): array
    {
        return [
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
            'points' => 1,
            'position' => 0,
        ];
    }

    /**
     * @return array{0: object, 1: Assessment, 2: Quiz}
     */
    private function publishedQuizScenario(): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);
        [$assessment, $quiz] = $this->quizFor($application, 'published');
        $quiz->questions()->create($this->mcqAttributes());

        return [$student, $assessment->fresh('application'), $quiz];
    }
}
