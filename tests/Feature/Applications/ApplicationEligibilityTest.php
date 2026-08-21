<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\MajorNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-3.2: `POST /api/opportunities/{opportunity}/apply` enforces the
 * exact same major-eligibility guard `Organization\InvitationController::store()`
 * does, via the shared `OpportunityEligibilityService` -- an Organization
 * inviting an ineligible Student was already blocked; this proves a direct
 * Apply can no longer admit one either, closing that inconsistency.
 */
class ApplicationEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_eligible_student_can_apply(): void
    {
        $student = $this->studentWithProfileAndCv('Computer Science');
        $opportunity = $this->opportunityWithMajors(['Computer Engineering', 'Computer Science', 'Software Engineering']);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_a_student_matching_the_second_eligible_major_can_apply(): void
    {
        $student = $this->studentWithProfileAndCv('Software Engineering');
        $opportunity = $this->opportunityWithMajors(['Computer Engineering', 'Computer Science', 'Software Engineering']);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);
    }

    public function test_an_ineligible_student_gets_a_controlled_rejection(): void
    {
        $student = $this->studentWithProfileAndCv('Fine Arts');
        $opportunity = $this->opportunityWithMajors(['Computer Science']);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Your major is not eligible for this opportunity');
    }

    public function test_an_ineligible_apply_creates_no_application(): void
    {
        $student = $this->studentWithProfileAndCv('Fine Arts');
        $opportunity = $this->opportunityWithMajors(['Computer Science']);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_an_ineligible_apply_calculates_no_match_score(): void
    {
        $student = $this->studentWithProfileAndCv('Fine Arts');
        $opportunity = $this->opportunityWithMajors(['Computer Science']);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('applications', ['opportunity_id' => $opportunity->id]);
    }

    public function test_an_ineligible_apply_sends_no_notification(): void
    {
        $student = $this->studentWithProfileAndCv('Fine Arts');
        $opportunity = $this->opportunityWithMajors(['Computer Science']);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_legacy_field_of_study_fallback_allows_a_matching_student_to_apply(): void
    {
        $student = $this->studentWithProfileAndCv('civil engineering');
        $opportunity = $this->openOpportunity(['field_of_study' => 'Civil Engineering']);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);
    }

    public function test_legacy_field_of_study_fallback_rejects_a_mismatched_student(): void
    {
        $student = $this->studentWithProfileAndCv('Fine Arts');
        $opportunity = $this->openOpportunity(['field_of_study' => 'Civil Engineering']);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('applications', 0);
    }

    public function test_an_unrestricted_opportunity_accepts_any_major(): void
    {
        $student = $this->studentWithProfileAndCv('Fine Arts');
        $opportunity = $this->openOpportunity(['field_of_study' => null]);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);
    }

    public function test_an_unrestricted_opportunity_accepts_a_student_with_no_major(): void
    {
        $student = $this->studentWithProfileAndCv(null);
        $opportunity = $this->openOpportunity(['field_of_study' => null]);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror StudentApplicationTest's own conventions.
    // -----------------------------------------------------------------

    private function studentWithProfileAndCv(?string $major): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id, 'major' => $major]);
        $cv = $profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/my-cv.pdf']);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function openOpportunity(array $overrides = []): Opportunity
    {
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        $organizationProfile = OrganizationProfile::create([
            'user_id' => $organizationUser->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $organizationProfile->approval_status = 'approved';
        $organizationProfile->save();

        return $organizationProfile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    private function opportunityWithMajors(array $majors): Opportunity
    {
        $opportunity = $this->openOpportunity(['field_of_study' => null]);

        foreach ($majors as $major) {
            $opportunity->eligibleMajorRecords()->create([
                'major_name' => $major,
                'normalized_major_name' => MajorNormalizer::normalize($major),
            ]);
        }

        return $opportunity->fresh();
    }
}
