<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 6B-1: GET /api/organization/assessments/{assessment}/quiz
 */
class ShowQuizTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_sees_the_quiz_for_its_own_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        [$assessment, $quiz] = $this->quizAssessmentFor($application);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}/quiz");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $quiz->id)
            ->assertJsonPath('data.title', 'Backend Fundamentals')
            ->assertJsonPath('data.passing_score', 70);
    }

    /**
     * Privacy design regression (organization side): the organization
     * authored this answer key itself, so `correct_answer` is intentionally
     * present here -- this is the documented exposure boundary. See
     * tests/Unit/Models/QuestionPrivacyTest.php for the corresponding "must
     * never reach a student" invariant.
     */
    public function test_organization_quiz_response_includes_correct_answer(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        [$assessment, $quiz] = $this->quizAssessmentFor($application);
        $quiz->questions()->create([
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}/quiz");

        $response->assertStatus(200)
            ->assertJsonPath('data.questions.0.correct_answer', 'Paris')
            ->assertJsonPath('data.questions.0.options', ['Paris', 'London', 'Berlin']);
    }

    public function test_returns_null_data_for_an_owned_assessment_with_no_quiz(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}/quiz");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    }

    public function test_organization_cannot_see_the_quiz_for_another_organizations_assessment(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        [$assessment] = $this->quizAssessmentFor($application);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}/quiz");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Assessment not found');
    }

    public function test_guest_receives_401(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, 'shortlisted');
        [$assessment] = $this->quizAssessmentFor($application);

        $response = $this->getJson("/api/organization/assessments/{$assessment->id}/quiz");

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

    private function applicationFor(Opportunity $opportunity, string $status = 'pending'): Application
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

    /**
     * @return array{0: \App\Models\Assessment, 1: \App\Models\Quiz}
     */
    private function quizAssessmentFor(Application $application): array
    {
        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'pending',
            'result' => null,
        ]);

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'instructions' => 'Choose the best answer.',
            'time_limit_minutes' => 30,
            'passing_score' => 70,
            'status' => 'draft',
        ]);

        return [$assessment, $quiz];
    }
}
