<?php

namespace Tests\Feature\Organization;

use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Company Profile Polish phase: safe permanent deletion of a closed
 * Opportunity. Covers both the tightened generic
 * `DELETE /organization/opportunities/{opportunity}` (now also blocked
 * by Invitations/a shared Quiz Template, not just Applications -- both
 * previously cascade-deleted silently) and the new, closed-only
 * `DELETE /organization/opportunities/{opportunity}/closed` the Company
 * Profile's "Closed Opportunities" cleanup UI actually calls.
 */
class OpportunitySafeDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_view_its_closed_opportunities_via_the_list_endpoint(): void
    {
        $org = $this->approvedOrganization();
        $org->profile->opportunities()->create($this->validOpportunityPayload(['status' => 'closed']));
        $org->profile->opportunities()->create($this->validOpportunityPayload(['status' => 'open']));

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $statuses = collect($response->json('data'))->pluck('status')->sort()->values();
        $this->assertSame(['closed', 'open'], $statuses->all());
    }

    public function test_a_closed_opportunity_never_appears_on_the_public_student_profile(): void
    {
        $org = $this->approvedOrganization();
        $org->profile->opportunities()->create($this->validOpportunityPayload(['status' => 'closed']));
        $org->profile->opportunities()->create($this->validOpportunityPayload(['status' => 'open']));

        $response = $this->getJson("/api/opportunities?organization_id={$org->profile->id}");

        $response->assertStatus(200);
        $titles = collect($response->json('data.data'))->pluck('status');
        $this->assertTrue($titles->every(fn ($status) => $status === 'open'));
    }

    public function test_another_organization_cannot_view_or_delete_a_closed_opportunity(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $opportunity = $orgA->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );

        Sanctum::actingAs($orgB->user);

        $this->getJson("/api/organization/opportunities/{$opportunity->id}")->assertStatus(404);
        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")->assertStatus(404);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_a_closed_opportunity_with_zero_dependencies_can_be_permanently_deleted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('opportunities', ['id' => $opportunity->id]);
    }

    public function test_an_open_opportunity_cannot_be_deleted_through_the_closed_cleanup_path(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'open']),
        );

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")
            ->assertStatus(409);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_a_draft_opportunity_cannot_be_deleted_through_the_closed_cleanup_path(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'draft']),
        );

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")
            ->assertStatus(409);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_a_closed_opportunity_with_an_application_cannot_be_hard_deleted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );
        $student = $this->studentWithProfile();
        $cv = $student->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/a.pdf']);
        $application = $opportunity->applications()->create(['student_id' => $student->id, 'cv_id' => $cv->id]);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete an opportunity that has recruitment history');

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('applications', ['id' => $application->id]);
    }

    public function test_a_closed_opportunity_with_an_invitation_but_no_application_cannot_be_hard_deleted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")
            ->assertStatus(409);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
    }

    public function test_a_closed_opportunity_with_a_shared_quiz_template_cannot_be_hard_deleted(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );
        $quiz = $opportunity->quizTemplate()->create(['title' => 'Screening Quiz', 'passing_score' => 70]);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")
            ->assertStatus(409);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id]);
    }

    /**
     * The same tightened dependency check applies to the pre-existing
     * generic destroy endpoint too -- an Invitation or shared Quiz
     * Template used to cascade-delete silently there (their FKs are
     * `cascadeOnDelete()`) whenever an Opportunity had zero Applications.
     */
    public function test_the_generic_destroy_endpoint_is_also_blocked_by_an_invitation(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $student = $this->studentWithProfile();
        $invitation = $opportunity->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}")->assertStatus(409);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
    }

    public function test_the_generic_destroy_endpoint_is_also_blocked_by_a_shared_quiz_template(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $quiz = $opportunity->quizTemplate()->create(['title' => 'Screening Quiz', 'passing_score' => 70]);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}")->assertStatus(409);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id]);
    }

    public function test_a_student_cannot_delete_any_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($student);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")->assertStatus(403);
        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}")->assertStatus(403);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_an_unauthenticated_request_cannot_delete_any_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")->assertStatus(401);
        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}")->assertStatus(401);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    /**
     * A blocked deletion attempt never partially/silently removes any
     * dependent record -- Application, Invitation, and Quiz Template all
     * survive a rejected delete attempt against the same Opportunity.
     */
    public function test_a_rejected_deletion_leaves_every_dependent_record_completely_untouched(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed']),
        );
        $student = $this->studentWithProfile();
        $cv = $student->cvs()->create(['title' => 'CV', 'file_path' => 'cvs/a.pdf']);
        $application = $opportunity->applications()->create(['student_id' => $student->id, 'cv_id' => $cv->id]);
        $invitedStudent = $this->studentWithProfile(name: 'Someone Else');
        $invitation = $opportunity->invitations()->create(['student_id' => $invitedStudent->id]);
        $quiz = $opportunity->quizTemplate()->create(['title' => 'Screening Quiz', 'passing_score' => 70]);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/closed")->assertStatus(409);

        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->assertDatabaseHas('invitations', ['id' => $invitation->id]);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id]);
    }

    /**
     * `can_delete` (the client-facing signal) is exposed truthfully and
     * agrees exactly with what the backend would actually allow.
     */
    public function test_can_delete_reflects_the_real_backend_rule(): void
    {
        $org = $this->approvedOrganization();
        $deletable = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed'], 'A'),
        );
        $withHistory = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['status' => 'closed'], 'B'),
        );
        $student = $this->studentWithProfile();
        $withHistory->invitations()->create(['student_id' => $student->id]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/opportunities');
        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertTrue($byId[$deletable->id]['can_delete']);
        $this->assertFalse($byId[$withHistory->id]['can_delete']);
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function studentWithProfile(string $name = 'Jane Student'): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active', 'name' => $name]);

        return \App\Models\StudentProfile::create(['user_id' => $user->id]);
    }

    private function validOpportunityPayload(array $overrides = [], string $titleSuffix = ''): array
    {
        $skillId = Skill::firstOrCreate(['name' => 'PHP'])->id;

        return array_merge([
            'title' => 'Software Engineer'.($titleSuffix !== '' ? " {$titleSuffix}" : ''),
            'description' => 'A great opportunity.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides);
    }
}
