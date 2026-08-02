<?php

namespace Tests\Feature\Assessments;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_list_only_their_own_assessments(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $assessment = $this->assessmentFor($application);

        $otherStudent = $this->studentWithProfileAndCv();
        $otherApplication = $this->applicationForStudent($opportunity, $otherStudent, 'shortlisted');
        $this->assessmentFor($otherApplication);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/assessments');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assessment->id)
            ->assertJsonPath('data.0.application.id', $application->id)
            ->assertJsonPath('data.0.interview.id', $assessment->interview->id);
    }

    public function test_student_with_no_assessments_sees_an_empty_list(): void
    {
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/assessments');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_student_can_view_their_own_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $assessment = $this->assessmentFor($application);

        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $assessment->id)
            ->assertJsonPath('data.application.id', $application->id);
    }

    public function test_student_cannot_view_another_students_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $owner = $this->studentWithProfileAndCv();
        $ownerApplication = $this->applicationForStudent($opportunity, $owner, 'shortlisted');
        $assessment = $this->assessmentFor($ownerApplication);

        $otherStudent = $this->studentWithProfileAndCv();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->getJson("/api/student/assessments/{$assessment->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Assessment not found');
    }

    public function test_guest_cannot_view_student_assessments(): void
    {
        $response = $this->getJson('/api/student/assessments');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_organization_role_cannot_access_student_assessment_routes(): void
    {
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        Sanctum::actingAs($organizationUser);

        $response = $this->getJson('/api/student/assessments');

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

    private function applicationForStudent(Opportunity $opportunity, object $student, string $status = 'pending'): Application
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

    private function assessmentFor(Application $application): Assessment
    {
        $assessment = $application->assessment()->create([
            'type' => 'interview',
            'status' => 'scheduled',
            'result' => null,
        ]);

        $assessment->interview()->create([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3),
        ]);

        return $assessment->fresh('interview');
    }
}
