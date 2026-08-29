<?php

namespace Tests\Feature\Organization;

use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OpportunitySkillTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_list_skills_for_its_own_opportunity(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $skill = Skill::create(['name' => 'Laravel']);

        $opportunity->opportunitySkills()->create([
            'skill_id' => $skill->id,
            'is_required' => true,
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/skills");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_organization_can_add_a_skill_to_its_own_opportunity(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $skill = Skill::create(['name' => 'PHP']);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/opportunities/{$opportunity->id}/skills", [
            'skill_id' => $skill->id,
            'is_required' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('opportunity_skills', [
            'opportunity_id' => $opportunity->id,
            'skill_id' => $skill->id,
        ]);
    }

    public function test_organization_cannot_add_the_same_skill_twice(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $skill = Skill::create(['name' => 'JavaScript']);

        $opportunity->opportunitySkills()->create([
            'skill_id' => $skill->id,
            'is_required' => true,
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/opportunities/{$opportunity->id}/skills", [
            'skill_id' => $skill->id,
            'is_required' => false,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This skill has already been added to this opportunity');

        $this->assertDatabaseCount('opportunity_skills', 1);
    }

    public function test_organization_can_delete_a_skill_from_its_own_opportunity(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $skill = Skill::create(['name' => 'Docker']);

        $opportunitySkill = $opportunity->opportunitySkills()->create([
            'skill_id' => $skill->id,
            'is_required' => true,
        ]);

        Sanctum::actingAs($org->user);

        $response = $this->deleteJson("/api/organization/opportunities/{$opportunity->id}/skills/{$opportunitySkill->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('opportunity_skills', ['id' => $opportunitySkill->id]);
    }

    public function test_organization_cannot_manage_skills_for_another_organizations_opportunity(): void
    {
        $orgA = $this->organizationWithProfile();
        $opportunity = $orgA->profile->opportunities()->create($this->validOpportunityPayload());
        $skill = Skill::create(['name' => 'Kubernetes']);

        $orgB = $this->organizationWithProfile();
        Sanctum::actingAs($orgB->user);

        $this->getJson("/api/organization/opportunities/{$opportunity->id}/skills")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Opportunity not found');

        $this->postJson("/api/organization/opportunities/{$opportunity->id}/skills", [
            'skill_id' => $skill->id,
            'is_required' => true,
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Opportunity not found');

        $this->assertDatabaseCount('opportunity_skills', 0);
    }

    // -----------------------------------------------------------------
    // Phase O8.2: the Skill Catalog + bulk sync -- Create/Edit
    // Opportunity's Required Skills multi-select, always by canonical
    // `skills.id`, never free text.
    // -----------------------------------------------------------------

    public function test_organization_can_fetch_the_skill_catalog(): void
    {
        Skill::create(['name' => 'Laravel']);
        Skill::create(['name' => 'PHP']);

        $org = $this->organizationWithProfile();
        Sanctum::actingAs($org->user);

        $this->getJson('/api/organization/skills/catalog')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_syncing_skills_replaces_the_entire_set_in_one_call(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $laravel = Skill::create(['name' => 'Laravel']);
        $php = Skill::create(['name' => 'PHP']);
        $mysql = Skill::create(['name' => 'MySQL']);

        // Pre-existing selection this call must fully replace.
        $opportunity->opportunitySkills()->create(['skill_id' => $mysql->id, 'is_required' => true]);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/opportunities/{$opportunity->id}/skills", [
            'skills' => [
                ['skill_id' => $laravel->id, 'is_required' => true],
                ['skill_id' => $php->id, 'is_required' => false],
            ],
        ]);

        $response->assertStatus(200)->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('opportunity_skills', [
            'opportunity_id' => $opportunity->id,
            'skill_id' => $laravel->id,
            'is_required' => true,
        ]);
        $this->assertDatabaseHas('opportunity_skills', [
            'opportunity_id' => $opportunity->id,
            'skill_id' => $php->id,
            'is_required' => false,
        ]);
        $this->assertDatabaseMissing('opportunity_skills', [
            'opportunity_id' => $opportunity->id,
            'skill_id' => $mysql->id,
        ]);
    }

    public function test_syncing_an_empty_set_clears_all_skills(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $skill = Skill::create(['name' => 'Laravel']);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/opportunities/{$opportunity->id}/skills", [
            'skills' => [],
        ]);

        $response->assertStatus(200)->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('opportunity_skills', 0);
    }

    public function test_syncing_skills_rejects_a_nonexistent_skill_id(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());

        Sanctum::actingAs($org->user);

        $this->putJson("/api/organization/opportunities/{$opportunity->id}/skills", [
            'skills' => [['skill_id' => 99999, 'is_required' => true]],
        ])->assertStatus(422);

        $this->assertDatabaseCount('opportunity_skills', 0);
    }

    public function test_syncing_skills_for_another_organizations_opportunity_is_blocked(): void
    {
        $orgA = $this->organizationWithProfile();
        $opportunity = $orgA->profile->opportunities()->create($this->validOpportunityPayload());
        $skill = Skill::create(['name' => 'Laravel']);

        $orgB = $this->organizationWithProfile();
        Sanctum::actingAs($orgB->user);

        $this->putJson("/api/organization/opportunities/{$opportunity->id}/skills", [
            'skills' => [['skill_id' => $skill->id, 'is_required' => true]],
        ])
            ->assertStatus(404)
            ->assertJsonPath('message', 'Opportunity not found');

        $this->assertDatabaseCount('opportunity_skills', 0);
    }

    private function organizationWithProfile(string $approvalStatus = 'approved'): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);

        $profile->approval_status = $approvalStatus;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function validOpportunityPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Software Engineer',
            'description' => 'A great opportunity.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides);
    }
}
