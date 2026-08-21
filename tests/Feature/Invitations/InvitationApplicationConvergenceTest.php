<?php

namespace Tests\Feature\Invitations;

use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-3: the end-to-end proof that Flow B (Organization invites ->
 * Student accepts -> Student applies) converges into the exact same
 * pipeline Flow A (Student applies directly) already uses -- same
 * `Student\ApplicationController::store()` endpoint, same `MatchingService`
 * call, same organization-facing ranked applicant list, same
 * shortlist/reject workflow. No second recruitment pipeline exists.
 */
class InvitationApplicationConvergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_flow_b_converges_into_the_normal_application_pipeline(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        // 1. Organization sends an invitation.
        Sanctum::actingAs($org->user);
        $invite = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $invite->assertStatus(201);
        $invitationId = $invite->json('data.id');

        // 2. Student accepts the invitation -- no Application exists yet.
        Sanctum::actingAs($student->user);
        $this->putJson("/api/student/invitations/{$invitationId}/accept")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseCount('applications', 0);

        // 3. Student completes the Application through the existing Apply
        // flow -- the exact same endpoint Flow A uses.
        $apply = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);
        $apply->assertStatus(201);
        $applicationId = $apply->json('data.id');

        // 4. The Application has a real, auto-calculated match_score --
        // never exposed to the student, but present in the database.
        $this->assertDatabaseHas('applications', [
            'id' => $applicationId,
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $this->assertNotNull(\App\Models\Application::find($applicationId)->match_score);
        $apply->assertJsonMissingPath('data.match_score');

        // 5. The organization sees it in its normal, ranked applicant list.
        Sanctum::actingAs($org->user);
        $list = $this->getJson('/api/organization/applications');
        $list->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($applicationId, $list->json('data.0.id'));
        $this->assertNotNull($list->json('data.0.match_score'));

        // 6. Normal workflow status rules apply from here on -- shortlist
        // works exactly as it does for a Flow A application.
        $shortlist = $this->putJson(
            "/api/organization/applications/{$applicationId}/status",
            ['status' => 'shortlisted'],
        );
        $shortlist->assertStatus(200)->assertJsonPath('data.status', 'shortlisted');
    }

    public function test_a_student_who_already_applied_can_still_accept_the_invitation(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($org->user);
        $invite = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $invitationId = $invite->json('data.id');

        // The student applies independently before responding.
        Sanctum::actingAs($student->user);
        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        // Accepting afterwards still just records consent -- no second
        // Application is attempted.
        $accept = $this->putJson("/api/student/invitations/{$invitationId}/accept");
        $accept->assertStatus(200)->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseCount('applications', 1);
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

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $cv = $profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/my-cv.pdf']);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }
}
