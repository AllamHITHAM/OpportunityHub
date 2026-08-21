<?php

namespace Tests\Feature\Invitations;

use App\Models\Invitation;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-3: `GET /student/invitations`,
 * `PUT /student/invitations/{invitation}/accept`,
 * `PUT /student/invitations/{invitation}/decline` -- the Student's side of
 * Flow B. Student consent is the hard rule this file exists to prove:
 * declining (and, just as importantly, accepting) never creates an
 * `Application` by itself.
 */
class StudentInvitationResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_sees_only_their_own_invitations(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $studentA = $this->studentWithProfile();
        $studentB = $this->studentWithProfile();
        $opportunity->invitations()->create(['student_id' => $studentA->id]);
        $opportunity->invitations()->create(['student_id' => $studentB->id]);

        Sanctum::actingAs($studentA->user);

        $response = $this->getJson('/api/student/invitations');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_invitation_list_includes_organization_and_opportunity_context(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['title' => 'Backend Developer']);
        $student = $this->studentWithProfile();
        $opportunity->invitations()->create([
            'student_id' => $student->id,
            'message' => 'Please apply!',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/invitations');

        $response->assertStatus(200);
        $this->assertSame('pending', $response->json('data.0.status'));
        $this->assertSame('Backend Developer', $response->json('data.0.opportunity.title'));
        $this->assertSame('Hiring Co', $response->json('data.0.opportunity.organization_profile.organization_name'));
        $this->assertSame('Please apply!', $response->json('data.0.message'));
        $this->assertNotNull($response->json('data.0.created_at'));
    }

    public function test_accepting_a_pending_invitation(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $response = $this->putJson("/api/student/invitations/{$invitation->id}/accept");

        $response->assertStatus(200)->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'status' => 'accepted']);
    }

    public function test_declining_a_pending_invitation(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $response = $this->putJson("/api/student/invitations/{$invitation->id}/decline");

        $response->assertStatus(200)->assertJsonPath('data.status', 'declined');
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'status' => 'declined']);
    }

    public function test_declining_never_creates_an_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $this->putJson("/api/student/invitations/{$invitation->id}/decline")->assertStatus(200);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_accepting_never_creates_an_application_by_itself(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $this->putJson("/api/student/invitations/{$invitation->id}/accept")->assertStatus(200);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_accepting_never_calculates_a_match_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $this->putJson("/api/student/invitations/{$invitation->id}/accept")->assertStatus(200);

        $this->assertDatabaseMissing('applications', ['opportunity_id' => $opportunity->id]);
    }

    public function test_a_repeated_accept_is_safe(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $this->putJson("/api/student/invitations/{$invitation->id}/accept")->assertStatus(200);
        $this->putJson("/api/student/invitations/{$invitation->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This invitation has already been responded to');
    }

    public function test_a_repeated_decline_is_safe(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $this->putJson("/api/student/invitations/{$invitation->id}/decline")->assertStatus(200);
        $this->putJson("/api/student/invitations/{$invitation->id}/decline")->assertStatus(409);
    }

    public function test_cannot_accept_after_already_declining(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);

        $this->putJson("/api/student/invitations/{$invitation->id}/decline")->assertStatus(200);
        $this->putJson("/api/student/invitations/{$invitation->id}/accept")->assertStatus(409);
    }

    public function test_wrong_owner_gets_a_404_on_accept(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $owner = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $owner->id]);

        $intruder = $this->studentWithProfile();
        Sanctum::actingAs($intruder->user);

        $response = $this->putJson("/api/student/invitations/{$invitation->id}/accept");

        $response->assertStatus(404)->assertJsonPath('message', 'Invitation not found');
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'status' => 'pending']);
    }

    public function test_wrong_owner_gets_a_404_on_decline(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $owner = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $owner->id]);

        $intruder = $this->studentWithProfile();
        Sanctum::actingAs($intruder->user);

        $this->putJson("/api/student/invitations/{$invitation->id}/decline")->assertStatus(404);
    }

    public function test_organization_and_admin_are_denied(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($org->user);
        $this->getJson('/api/student/invitations')->assertStatus(403);
        $this->putJson("/api/student/invitations/{$invitation->id}/accept")->assertStatus(403);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/student/invitations')->assertStatus(403);
    }

    public function test_organization_is_notified_on_accept(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['title' => 'Backend Developer']);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);
        $this->putJson("/api/student/invitations/{$invitation->id}/accept")->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $org->user->id,
            'type' => 'opportunity',
            'title' => 'Invitation Accepted',
        ]);
    }

    public function test_organization_is_notified_on_decline(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['title' => 'Backend Developer']);
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($student->user);
        $this->putJson("/api/student/invitations/{$invitation->id}/decline")->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $org->user->id,
            'type' => 'opportunity',
            'title' => 'Invitation Declined',
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror ApplicationRankingTest's own conventions.
    // -----------------------------------------------------------------

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

    private function studentWithProfile(): StudentProfile
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);

        return StudentProfile::create(['user_id' => $user->id]);
    }
}
