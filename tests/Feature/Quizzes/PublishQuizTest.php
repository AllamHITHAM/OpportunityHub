<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6B-1: PUT /api/organization/quizzes/{quiz}/publish
 */
class PublishQuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_quiz_with_a_question_publishes(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/quizzes/{$quiz->id}/publish");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Quiz published successfully')
            ->assertJsonPath('data.status', 'published');
    }

    public function test_publishing_sets_quiz_status_to_published(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);

        $this->assertDatabaseHas('quizzes', [
            'id' => $quiz->id,
            'status' => 'published',
        ]);
    }

    public function test_publishing_sets_assessment_status_to_scheduled(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());
        $this->assertSame('pending', $quiz->assessment->status);

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);

        $this->assertDatabaseHas('assessments', [
            'id' => $quiz->assessment_id,
            'status' => 'scheduled',
        ]);
    }

    public function test_publishing_leaves_application_status_at_in_assessment(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());
        $application = $quiz->assessment->application;
        $this->assertSame('in_assessment', $application->fresh()->status);

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'in_assessment',
        ]);
    }

    public function test_zero_question_publish_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/quizzes/{$quiz->id}/publish");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A quiz must have at least one question before it can be published');

        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'status' => 'draft']);
    }

    public function test_an_already_published_quiz_cannot_be_published_again(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());
        $quiz->status = 'published';
        $quiz->save();

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/quizzes/{$quiz->id}/publish");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only draft quizzes can be published');
    }

    public function test_wrong_owner_cannot_publish(): void
    {
        $orgA = $this->approvedOrganization();
        $quiz = $this->quizFor($orgA);
        $quiz->questions()->create($this->mcqAttributes());

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->putJson("/api/organization/quizzes/{$quiz->id}/publish");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');

        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id, 'status' => 'draft']);
    }

    public function test_guest_receives_401(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());

        $response = $this->putJson("/api/organization/quizzes/{$quiz->id}/publish");

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_student_receives_403(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());

        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($studentUser);

        $response = $this->putJson("/api/organization/quizzes/{$quiz->id}/publish");

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
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

    private function applicationFor(Opportunity $opportunity, string $status = 'shortlisted'): Application
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

    private function quizFor(object $org): Quiz
    {
        $application = $this->applicationFor($this->opportunityFor($org), 'shortlisted');

        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'pending',
            'result' => null,
        ]);

        $application->status = 'in_assessment';
        $application->save();

        return $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'instructions' => 'Choose the best answer.',
            'time_limit_minutes' => 30,
            'passing_score' => 70,
            'status' => 'draft',
        ]);
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
}
