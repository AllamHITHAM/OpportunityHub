<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_list_applications_for_its_own_opportunities(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/applications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $application->id);
    }

    public function test_organization_can_view_an_application_for_its_own_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $application->id);
    }

    public function test_organization_cannot_view_an_application_belonging_to_another_organizations_opportunity(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application not found');
    }

    public function test_organization_can_update_application_status(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'shortlisted',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'shortlisted');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'shortlisted',
        ]);
    }

    public function test_organization_cannot_update_status_of_another_organizations_application(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $application = $this->applicationFor($opportunity);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'accepted',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Application not found');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'pending',
        ]);
    }

    public function test_organization_can_update_application_status_to_accepted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'accepted',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    public function test_organization_can_update_application_status_to_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'rejected',
        ]);
    }

    /**
     * `in_assessment` must only ever be reached through a real
     * Assessment-creation workflow (AssessmentService::transitionToInAssessment()),
     * never fabricated directly by an organization.
     */
    public function test_manual_status_update_cannot_set_in_assessment(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $application->status = 'shortlisted';
        $application->save();

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'in_assessment',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'shortlisted',
        ]);
        $this->assertDatabaseCount('assessments', 0);
    }

    /**
     * Closes the previously-documented gap where an organization could set
     * `interview_scheduled` through this endpoint with no real Assessment
     * behind it. Existing rows may still legitimately hold this value (see
     * ApplicationStatusMigrationTest for legacy-data coverage) -- only this
     * endpoint's accepted input has changed.
     */
    public function test_manual_status_update_cannot_set_interview_scheduled(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $application->status = 'shortlisted';
        $application->save();

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'interview_scheduled',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'shortlisted',
        ]);
        $this->assertDatabaseCount('assessments', 0);
    }

    public function test_invalid_application_status_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'not-a-real-status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'pending',
        ]);
    }

    public function test_updating_status_to_reviewed_sets_reviewed_at(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);

        $this->assertNull($application->reviewed_at);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'reviewed',
        ]);

        $response->assertStatus(200);

        $application->refresh();
        $this->assertNotNull($application->reviewed_at);
    }

    public function test_organization_can_list_applications_by_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($org, ['title' => 'Opportunity A']);
        $opportunityB = $this->opportunityFor($org, ['title' => 'Opportunity B']);

        $applicationA = $this->applicationFor($opportunityA);
        $this->applicationFor($opportunityB);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunityA->id}/applications");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $applicationA->id);
    }

    public function test_organization_applications_list_includes_nested_student_user_identity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $studentUser = $application->studentProfile->user;

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/applications');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.student_profile.user.id', $studentUser->id)
            ->assertJsonPath('data.0.student_profile.user.name', $studentUser->name)
            ->assertJsonPath('data.0.student_profile.user.email', $studentUser->email)
            ->assertJsonMissingPath('data.0.student_profile.user.password')
            ->assertJsonMissingPath('data.0.student_profile.user.remember_token');
    }

    public function test_opportunity_applicants_list_includes_nested_student_user_identity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $studentUser = $application->studentProfile->user;

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/applications");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.student_profile.user.id', $studentUser->id)
            ->assertJsonPath('data.0.student_profile.user.name', $studentUser->name)
            ->assertJsonPath('data.0.student_profile.user.email', $studentUser->email)
            ->assertJsonMissingPath('data.0.student_profile.user.password')
            ->assertJsonMissingPath('data.0.student_profile.user.remember_token');
    }

    public function test_application_details_includes_nested_student_user_identity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $studentUser = $application->studentProfile->user;

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.student_profile.user.id', $studentUser->id)
            ->assertJsonPath('data.student_profile.user.name', $studentUser->name)
            ->assertJsonPath('data.student_profile.user.email', $studentUser->email)
            ->assertJsonMissingPath('data.student_profile.user.password')
            ->assertJsonMissingPath('data.student_profile.user.remember_token');
    }

    public function test_status_update_response_includes_nested_student_user_identity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity);
        $studentUser = $application->studentProfile->user;

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'reviewed',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.student_profile.user.id', $studentUser->id)
            ->assertJsonPath('data.student_profile.user.name', $studentUser->name)
            ->assertJsonPath('data.student_profile.user.email', $studentUser->email)
            ->assertJsonMissingPath('data.student_profile.user.password')
            ->assertJsonMissingPath('data.student_profile.user.remember_token');
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

    private function applicationFor(Opportunity $opportunity): Application
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

        return Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);
    }
}
