<?php

namespace Tests\Feature\Organization;

use App\Models\Application;
use App\Models\CV;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-1: Organization-facing application responses expose the
 * applicant's education-verification *status only*
 * (`student_profile.education_verification_status`) -- never the document
 * path, rejection reason, or Admin reviewer id. Mirrors
 * `ApplicationSkillEvidenceTest`'s conventions.
 */
class ApplicationEducationVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_not_submitted_applicant_shows_not_submitted(): void
    {
        $student = $this->studentWithProfile();
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.student_profile.education_verification_status', 'not_submitted');
    }

    public function test_a_pending_applicant_shows_pending(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'pending');
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.student_profile.education_verification_status', 'pending');
    }

    public function test_a_verified_applicant_shows_verified(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'verified');
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.student_profile.education_verification_status', 'verified');
    }

    public function test_a_rejected_applicant_shows_rejected(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'rejected');
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.student_profile.education_verification_status', 'rejected');
    }

    public function test_the_applications_index_also_includes_the_status(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'verified');
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson('/api/organization/applications');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.student_profile.education_verification_status', 'verified');
    }

    public function test_the_opportunity_scoped_applicants_list_also_includes_the_status(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'verified');
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/opportunities/{$application->opportunity_id}/applications");

        $response->assertStatus(200)
            ->assertJsonPath('data.0.student_profile.education_verification_status', 'verified');
    }

    public function test_another_organizations_applicant_is_still_inaccessible(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'verified');
        $application = $this->applicationFor($student);

        $otherOrg = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        OrganizationProfile::create([
            'user_id' => $otherOrg->id,
            'organization_name' => 'Other Co',
            'organization_type' => 'company',
        ]);
        Sanctum::actingAs($otherOrg);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(404);
    }

    public function test_no_document_path_is_ever_leaked(): void
    {
        $student = $this->studentWithProfile();
        $verification = $this->verificationFor($student, 'verified');
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)->assertJsonMissingPath('data.student_profile.education_verification');
        $this->assertStringNotContainsString($verification->document_path, $response->getContent());
        $this->assertStringNotContainsString('document_path', $response->getContent());
    }

    public function test_no_admin_reviewer_id_is_ever_leaked(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'rejected', reviewerId: $this->admin()->id);
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('reviewed_by_admin_id', $response->getContent());
    }

    public function test_no_rejection_reason_is_ever_leaked(): void
    {
        $student = $this->studentWithProfile();
        $this->verificationFor($student, 'rejected', reason: 'A very specific rejection reason.');
        $application = $this->applicationFor($student);
        Sanctum::actingAs($application->opportunity->organizationProfile->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200);
        $this->assertStringNotContainsString('A very specific rejection reason.', $response->getContent());
        $this->assertStringNotContainsString('rejection_reason', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function verificationFor(object $student, string $status, ?string $reason = null, ?int $reviewerId = null)
    {
        return $student->profile->educationVerification()->create([
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc Computer Science',
            'document_path' => "education-verifications/{$student->profile->id}/proof.pdf",
            'status' => $status,
            'rejection_reason' => $reason,
            'submitted_at' => now(),
            'reviewed_at' => $status === 'pending' ? null : now(),
            'reviewed_by_admin_id' => $reviewerId,
        ]);
    }

    private function applicationFor(object $student): Application
    {
        $org = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        $organizationProfile = OrganizationProfile::create([
            'user_id' => $org->id,
            'organization_name' => 'Acme',
            'organization_type' => 'company',
        ]);
        $opportunity = $organizationProfile->opportunities()->create([
            'title' => 'Civil Engineer',
            'description' => 'Role',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);

        $cv = CV::create([
            'student_id' => $student->profile->id,
            'title' => 'My CV',
            'file_path' => "cvs/{$student->profile->id}/irrelevant.pdf",
        ]);

        return Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }
}
