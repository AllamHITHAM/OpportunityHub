<?php

namespace Tests\Feature\Interviews;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentInterviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_list_only_their_own_interviews(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $interview = $application->interview()->create($this->validInterviewPayload());

        $otherStudent = $this->studentWithProfileAndCv();
        $otherApplication = $this->applicationForStudent($opportunity, $otherStudent, 'shortlisted');
        $otherApplication->interview()->create($this->validInterviewPayload());

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $interview->id);
    }

    public function test_student_cannot_see_another_students_interviews(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        $owner = $this->studentWithProfileAndCv();
        $ownerApplication = $this->applicationForStudent($opportunity, $owner, 'shortlisted');
        $ownerApplication->interview()->create($this->validInterviewPayload());

        $otherStudent = $this->studentWithProfileAndCv();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_organization_cannot_access_student_interview_routes(): void
    {
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        Sanctum::actingAs($organizationUser);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_student_interview_response_includes_application_and_opportunity_details(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['title' => 'Backend Developer']);

        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');
        $application->interview()->create($this->validInterviewPayload());

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/interviews');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.application.id', $application->id)
            ->assertJsonPath('data.0.application.opportunity.title', 'Backend Developer');
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

    private function validInterviewPayload(array $overrides = []): array
    {
        return array_merge([
            'interview_type' => 'phone',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
        ], $overrides);
    }
}
