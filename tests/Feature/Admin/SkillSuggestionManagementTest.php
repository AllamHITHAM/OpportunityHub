<?php

namespace Tests\Feature\Admin;

use App\Models\Skill;
use App\Models\SkillSuggestion;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-6.1: `GET /api/admin/skill-suggestions`,
 * `PUT /api/admin/skill-suggestions/{suggestion}/approve`,
 * `PUT /api/admin/skill-suggestions/{suggestion}/reject`. The AI-side
 * creation of suggestions is covered by AiSkillExtractionServiceTest --
 * this file covers only the Admin review surface.
 */
class SkillSuggestionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_pending_suggestions(): void
    {
        $admin = $this->adminUser();
        $this->pendingSuggestion('Primavera P6');
        $this->pendingSuggestion('Tekla Structures');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/skill-suggestions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_the_list_never_includes_already_reviewed_suggestions(): void
    {
        $admin = $this->adminUser();
        $this->pendingSuggestion('Primavera P6');
        $reviewed = $this->pendingSuggestion('Already Approved');
        $reviewed->update(['status' => 'approved']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/skill-suggestions');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_a_non_admin_role_is_denied_the_list(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/admin/skill-suggestions');

        $response->assertStatus(403);
    }

    public function test_a_non_admin_role_is_denied_approval(): void
    {
        $suggestion = $this->pendingSuggestion('Primavera P6');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/approve");

        $response->assertStatus(403);
        $this->assertDatabaseHas('skill_suggestions', ['id' => $suggestion->id, 'status' => 'pending']);
    }

    public function test_an_unauthenticated_request_is_denied(): void
    {
        $suggestion = $this->pendingSuggestion('Primavera P6');

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/approve");

        $response->assertStatus(401);
    }

    public function test_approving_creates_a_new_skill_and_links_it(): void
    {
        $admin = $this->adminUser();
        $suggestion = $this->pendingSuggestion('Primavera P6');
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/approve");

        $response->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $suggestion->refresh();
        $this->assertSame('approved', $suggestion->status);
        $this->assertNotNull($suggestion->approved_skill_id);
        $this->assertDatabaseHas('skills', ['id' => $suggestion->approved_skill_id, 'name' => 'Primavera P6']);
    }

    public function test_approving_reuses_an_equivalent_skill_that_appeared_after_the_suggestion(): void
    {
        $admin = $this->adminUser();
        $suggestion = $this->pendingSuggestion('Primavera P6');
        // The baseline seeder or another Admin created an equivalent Skill
        // (same normalized name, different casing) after the suggestion
        // was raised but before this one is reviewed.
        $existingSkill = Skill::create(['name' => 'primavera p6']);
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/approve");

        $response->assertStatus(200);
        $suggestion->refresh();
        $this->assertSame($existingSkill->id, $suggestion->approved_skill_id);
        $this->assertSame(1, Skill::whereRaw('LOWER(name) = ?', ['primavera p6'])->count());
    }

    public function test_rejecting_creates_no_skill(): void
    {
        $admin = $this->adminUser();
        $suggestion = $this->pendingSuggestion('Made Up Skill');
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/reject");

        $response->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $suggestion->refresh();
        $this->assertSame('rejected', $suggestion->status);
        $this->assertNull($suggestion->approved_skill_id);
        $this->assertDatabaseMissing('skills', ['name' => 'Made Up Skill']);
    }

    public function test_approving_an_already_approved_suggestion_is_a_safe_no_op(): void
    {
        $admin = $this->adminUser();
        $suggestion = $this->pendingSuggestion('Primavera P6');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/approve")->assertStatus(200);
        $skillCountAfterFirstApproval = Skill::count();

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/approve");

        $response->assertStatus(409);
        $this->assertSame($skillCountAfterFirstApproval, Skill::count());
    }

    public function test_rejecting_an_already_rejected_suggestion_is_a_safe_no_op(): void
    {
        $admin = $this->adminUser();
        $suggestion = $this->pendingSuggestion('Made Up Skill');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/reject")->assertStatus(200);

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/reject");

        $response->assertStatus(409);
        $this->assertDatabaseMissing('skills', ['name' => 'Made Up Skill']);
    }

    public function test_rejecting_an_already_approved_suggestion_does_not_undo_the_approval(): void
    {
        $admin = $this->adminUser();
        $suggestion = $this->pendingSuggestion('Primavera P6');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/approve")->assertStatus(200);

        $response = $this->putJson("/api/admin/skill-suggestions/{$suggestion->id}/reject");

        $response->assertStatus(409);
        $suggestion->refresh();
        $this->assertSame('approved', $suggestion->status);
        $this->assertNotNull($suggestion->approved_skill_id);
    }

    public function test_there_is_no_ai_auto_approval_a_suggestion_starts_and_stays_pending(): void
    {
        $suggestion = $this->pendingSuggestion('Primavera P6');

        $this->assertSame('pending', $suggestion->status);
        $this->assertNull($suggestion->approved_skill_id);
    }

    public function test_there_is_no_ai_auto_skill_creation_a_pending_suggestion_creates_no_skill(): void
    {
        $this->pendingSuggestion('Primavera P6');

        $this->assertDatabaseMissing('skills', ['name' => 'Primavera P6']);
    }

    private function pendingSuggestion(string $name): SkillSuggestion
    {
        return SkillSuggestion::create([
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'source' => 'ai_cv',
            'status' => 'pending',
        ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
