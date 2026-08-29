<?php

namespace Tests\Feature\Opportunities;

use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Opportunity Requirements Integrity Patch -- Required Skills validation
 * (mirrors `OpportunityEligibleMajorsTest`'s own shape for Eligible
 * Majors) and historical-data compatibility for BOTH requirements: an
 * Opportunity created before this patch, with zero eligible majors and/or
 * zero required skills, must remain fully readable and editable, and is
 * only ever asked to add at least one when the Organization explicitly
 * sends that field on an update.
 */
class OpportunityRequiredSkillsValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_with_zero_required_skills_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $payload = $this->opportunityPayload();
        unset($payload['skills']);
        $response = $this->postJson('/api/organization/opportunities', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['skills']);
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_creating_with_skills_sent_as_an_empty_array_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            '/api/organization/opportunities',
            $this->opportunityPayload(['skills' => []]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['skills']);
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_one_required_skill_succeeds(): void
    {
        $org = $this->approvedOrganization();
        $skillId = Skill::firstOrCreate(['name' => 'PHP'])->id;
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'skills' => [['skill_id' => $skillId, 'is_required' => true]],
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseCount('opportunity_skills', 1);
    }

    public function test_multiple_required_skills_persist(): void
    {
        $org = $this->approvedOrganization();
        $autoCad = Skill::create(['name' => 'AutoCAD']);
        $revit = Skill::create(['name' => 'Revit']);
        $quantitySurveying = Skill::create(['name' => 'Quantity Surveying']);
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'skills' => [
                ['skill_id' => $autoCad->id, 'is_required' => true],
                ['skill_id' => $revit->id, 'is_required' => true],
                ['skill_id' => $quantitySurveying->id, 'is_required' => false],
            ],
        ]));

        $response->assertStatus(201);
        $opportunityId = $response->json('data.id');
        $this->assertDatabaseCount('opportunity_skills', 3);
        $names = collect($response->json('data.opportunity_skills'))->pluck('skill.name')->sort()->values()->all();
        $this->assertSame(['AutoCAD', 'Quantity Surveying', 'Revit'], $names);
        $this->assertDatabaseHas('opportunity_skills', [
            'opportunity_id' => $opportunityId,
            'skill_id' => $revit->id,
            'is_required' => true,
        ]);
    }

    public function test_an_invalid_skill_id_is_rejected_on_create(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'skills' => [['skill_id' => 999999, 'is_required' => true]],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['skills.0.skill_id']);
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_update_syncs_the_skills_set(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());
        $mysql = Skill::create(['name' => 'MySQL']);
        $opportunity->opportunitySkills()->create(['skill_id' => $mysql->id, 'is_required' => true]);
        $laravel = Skill::create(['name' => 'Laravel']);

        Sanctum::actingAs($org->user);
        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['skills' => [['skill_id' => $laravel->id, 'is_required' => true]]]),
        );

        $response->assertStatus(200);
        $this->assertDatabaseMissing('opportunity_skills', ['skill_id' => $mysql->id]);
        $this->assertDatabaseHas('opportunity_skills', ['opportunity_id' => $opportunity->id, 'skill_id' => $laravel->id]);
        $this->assertDatabaseCount('opportunity_skills', 1);
    }

    public function test_update_with_skills_sent_as_an_empty_array_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());
        $mysql = Skill::create(['name' => 'MySQL']);
        $opportunity->opportunitySkills()->create(['skill_id' => $mysql->id, 'is_required' => true]);

        Sanctum::actingAs($org->user);
        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['skills' => []]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['skills']);
        $this->assertDatabaseCount('opportunity_skills', 1);
    }

    public function test_update_without_the_skills_key_leaves_existing_skills_untouched(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());
        $mysql = Skill::create(['name' => 'MySQL']);
        $opportunity->opportunitySkills()->create(['skill_id' => $mysql->id, 'is_required' => true]);

        Sanctum::actingAs($org->user);
        $payload = $this->opportunityPayload();
        unset($payload['skills']);
        $response = $this->putJson("/api/organization/opportunities/{$opportunity->id}", $payload);

        $response->assertStatus(200);
        $this->assertDatabaseCount('opportunity_skills', 1);
        $this->assertDatabaseHas('opportunity_skills', ['opportunity_id' => $opportunity->id, 'skill_id' => $mysql->id]);
    }

    // -----------------------------------------------------------------
    // Historical compatibility -- existing rows predating this patch.
    // -----------------------------------------------------------------

    public function test_a_historical_opportunity_with_zero_required_skills_remains_readable(): void
    {
        $org = $this->approvedOrganization();
        // Direct Eloquent create() -- bypasses the new HTTP validation
        // entirely, exactly like a real pre-patch database row.
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200)->assertJsonPath('data.opportunity_skills', []);
    }

    public function test_a_historical_opportunity_with_zero_eligible_majors_remains_readable(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200)->assertJsonPath('data.eligible_majors', []);
    }

    public function test_a_historical_opportunity_with_zero_requirements_remains_listable(): void
    {
        $org = $this->approvedOrganization();
        $org->profile->opportunities()->create($this->opportunityAttributes());

        Sanctum::actingAs($org->user);
        $response = $this->getJson('/api/organization/opportunities');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_editing_an_unrelated_field_on_a_historical_opportunity_with_zero_requirements_still_succeeds(): void
    {
        // Section 4's core guarantee: touching a field OTHER than
        // eligible_majors/skills on a historical zero-requirement
        // Opportunity must never be blocked -- the new rule only engages
        // when the Organization actually sends one of those two keys.
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());

        Sanctum::actingAs($org->user);
        $payload = $this->opportunityPayload(['title' => 'Updated Title']);
        unset($payload['eligible_majors'], $payload['skills']);
        $response = $this->putJson("/api/organization/opportunities/{$opportunity->id}", $payload);

        $response->assertStatus(200)->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_publishing_a_historical_opportunity_by_actually_setting_majors_requires_at_least_one(): void
    {
        // "saving/publishing it under the new rules" -- once the
        // Organization chooses to touch eligible_majors on a historical
        // Opportunity, it must satisfy the same >=1 rule as a new one.
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());

        Sanctum::actingAs($org->user);
        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['eligible_majors' => []]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['eligible_majors']);
    }

    // -----------------------------------------------------------------
    // Field of Study never substitutes for Eligible Majors.
    // -----------------------------------------------------------------

    public function test_field_of_study_does_not_substitute_for_eligible_majors_in_recommendation_eligibility(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes([
            'field_of_study' => 'Civil Engineering',
        ]));
        // No eligibleMajorRecords -- field_of_study alone must never gate
        // eligibility; an unrestricted (no explicit majors) Opportunity
        // remains open to any major, matching OpportunityEligibilityService.
        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        StudentProfile::create(['user_id' => $studentUser->id, 'major' => 'Totally Unrelated Major']);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
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

    private function opportunityAttributes(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides);
    }

    private function opportunityPayload(array $overrides = []): array
    {
        $skillId = Skill::firstOrCreate(['name' => 'PHP'])->id;

        return array_merge($this->opportunityAttributes(), [
            'eligible_majors' => ['Computer Science'],
            'skills' => [['skill_id' => $skillId, 'is_required' => true]],
        ], $overrides);
    }
}
