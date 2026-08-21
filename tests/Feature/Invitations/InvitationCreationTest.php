<?php

namespace Tests\Feature\Invitations;

use App\Models\Application;
use App\Models\Invitation;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-3: `POST /organization/invitations` -- Flow B's invite step.
 * Never creates an `Application`, never calls `MatchingService`. Every
 * duplicate/conflict rule is enforced by a single
 * `unique(['opportunity_id', 'student_id'])` constraint on `invitations`
 * (see the migration's own doc comment) plus an explicit already-applied
 * pre-check, so this file exercises both paths.
 */
class InvitationCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_invites_a_valid_student_to_its_own_open_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
            'message' => 'We would love to have you apply.',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('invitations', [
            'opportunity_id' => $opportunity->id,
            'student_id' => $student->id,
            'status' => 'pending',
            'message' => 'We would love to have you apply.',
        ]);
    }

    public function test_message_is_optional(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201);
    }

    public function test_student_and_admin_are_denied(): void
    {
        $student = $this->studentWithProfile();
        $opportunity = $this->opportunityFor($this->approvedOrganization());

        Sanctum::actingAs($student->user);
        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(403);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(403);
    }

    public function test_another_organizations_opportunity_is_denied(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($orgA->user);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunityB->id,
        ]);

        $response->assertStatus(404)->assertJsonPath('message', 'Opportunity not found');
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_a_closed_opportunity_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['status' => 'closed']);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This opportunity is not open for invitations');
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_a_draft_opportunity_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['status' => 'draft']);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(422);
    }

    public function test_a_duplicate_pending_invitation_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'An invitation already exists for this student and opportunity');
        $this->assertDatabaseCount('invitations', 1);
    }

    public function test_re_inviting_an_already_accepted_invitation_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $opportunity->invitations()->create(['student_id' => $student->id, 'status' => 'accepted']);

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(409);
    }

    public function test_re_inviting_after_a_decline_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $opportunity->invitations()->create(['student_id' => $student->id, 'status' => 'declined']);

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(409);
    }

    public function test_an_already_applied_student_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $cv = $student->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/a.pdf']);
        Application::create([
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'This student has already applied to this opportunity');
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_a_suspended_student_is_rejected_safely(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile(userStatus: 'suspended');

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ]);

        $response->assertStatus(404)->assertJsonPath('message', 'Student not found');
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_a_nonexistent_student_id_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => 999999,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(422);
    }

    public function test_sending_an_invitation_never_creates_an_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_the_student_receives_an_in_app_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->user->id,
            'type' => 'opportunity',
            'title' => 'Invitation to Apply',
        ]);
    }

    // -----------------------------------------------------------------
    // Phase 8B-3.2: major-eligibility guard.
    // -----------------------------------------------------------------

    public function test_an_eligible_student_can_be_invited(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Engineering', 'Computer Science', 'Software Engineering']);
        $student = $this->studentWithProfile(major: 'Computer Science');

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201);

        $this->assertDatabaseCount('invitations', 1);
    }

    public function test_a_student_matching_the_second_eligible_major_can_be_invited(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Engineering', 'Computer Science', 'Software Engineering']);
        $student = $this->studentWithProfile(major: 'Software Engineering');

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201);
    }

    public function test_an_ineligible_student_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Science']);
        $student = $this->studentWithProfile(major: 'Fine Arts');

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Student major is not eligible for this opportunity');
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_a_rejected_invitation_sends_no_in_app_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Computer Science']);
        $student = $this->studentWithProfile(major: 'Fine Arts');

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_legacy_field_of_study_eligibility_applies_to_invitations_too(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Civil Engineering']);
        $ineligible = $this->studentWithProfile(major: 'Fine Arts');

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/invitations', [
            'student_id' => $ineligible->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror ApplicationRankingTest's own conventions.
    // -----------------------------------------------------------------

    private function opportunityWithMajors(object $org, array $majors): Opportunity
    {
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);

        foreach ($majors as $major) {
            $opportunity->eligibleMajorRecords()->create([
                'major_name' => $major,
                'normalized_major_name' => \App\Support\MajorNormalizer::normalize($major),
            ]);
        }

        return $opportunity->fresh();
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

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

    private function studentWithProfile(string $userStatus = 'active', ?string $major = null): StudentProfile
    {
        $user = User::factory()->create(['role' => 'student', 'status' => $userStatus]);

        return StudentProfile::create(['user_id' => $user->id, 'major' => $major]);
    }
}
