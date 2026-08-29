<?php

namespace Tests\Feature\Opportunities;

use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-3.2: Opportunity create/update handling of `eligible_majors` --
 * persistence, deduplication/normalization, sync-on-update, response
 * shape, and backward compatibility with the pre-existing single
 * `field_of_study` column.
 */
class OpportunityEligibleMajorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_majors_persist_on_create(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'eligible_majors' => ['Computer Engineering', 'Computer Science', 'Software Engineering'],
        ]));

        $response->assertStatus(201);
        $opportunityId = $response->json('data.id');

        $this->assertDatabaseCount('opportunity_eligible_majors', 3);
        $this->assertSame(
            ['Computer Engineering', 'Computer Science', 'Software Engineering'],
            $response->json('data.eligible_majors'),
        );
        $this->assertDatabaseHas('opportunity_eligible_majors', [
            'opportunity_id' => $opportunityId,
            'major_name' => 'Computer Science',
            'normalized_major_name' => 'computer science',
        ]);
    }

    public function test_a_case_and_whitespace_duplicate_creates_only_one_row(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'eligible_majors' => ['Civil Engineering', 'civil engineering', '  Civil   Engineering  '],
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseCount('opportunity_eligible_majors', 1);
        $this->assertSame(['Civil Engineering'], $response->json('data.eligible_majors'));
    }

    public function test_a_blank_entry_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'eligible_majors' => ['Computer Science', '   '],
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['eligible_majors.1']);
        $this->assertDatabaseCount('opportunity_eligible_majors', 0);
    }

    public function test_more_than_ten_majors_is_rejected(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'eligible_majors' => array_map(fn ($i) => "Major {$i}", range(1, 11)),
        ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['eligible_majors']);
    }

    public function test_eligible_majors_is_required_on_create(): void
    {
        // Opportunity Requirements Integrity Patch: supersedes the
        // previous Phase 8B-3.2 decision -- every NEW Opportunity must
        // declare at least one Eligible Major.
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $payload = $this->opportunityPayload();
        unset($payload['eligible_majors']);
        $response = $this->postJson('/api/organization/opportunities', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['eligible_majors']);
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_eligible_majors_sent_as_an_empty_array_is_rejected_on_create(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson(
            '/api/organization/opportunities',
            $this->opportunityPayload(['eligible_majors' => []]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['eligible_majors']);
        $this->assertDatabaseCount('opportunities', 0);
    }

    public function test_update_syncs_the_majors_set(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Civil Engineering',
            'normalized_major_name' => 'civil engineering',
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['eligible_majors' => ['Architecture', 'Industrial Engineering']]),
        );

        $response->assertStatus(200);
        $this->assertSame(['Architecture', 'Industrial Engineering'], $response->json('data.eligible_majors'));
        $this->assertDatabaseMissing('opportunity_eligible_majors', ['major_name' => 'Civil Engineering']);
        $this->assertDatabaseCount('opportunity_eligible_majors', 2);
    }

    public function test_update_with_an_explicit_empty_list_is_rejected(): void
    {
        // Opportunity Requirements Integrity Patch: an Opportunity that
        // already has at least one Eligible Major can no longer be
        // cleared down to zero -- `eligible_majors` may be omitted
        // (leaves the existing set untouched) but never sent empty.
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Civil Engineering',
            'normalized_major_name' => 'civil engineering',
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->opportunityPayload(['eligible_majors' => []]),
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['eligible_majors']);
        $this->assertDatabaseCount('opportunity_eligible_majors', 1);
        $this->assertDatabaseHas('opportunity_eligible_majors', ['major_name' => 'Civil Engineering']);
    }

    public function test_update_without_the_key_leaves_existing_majors_untouched(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes());
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Civil Engineering',
            'normalized_major_name' => 'civil engineering',
        ]);

        Sanctum::actingAs($org->user);
        $payload = $this->opportunityPayload();
        unset($payload['eligible_majors']);
        $response = $this->putJson("/api/organization/opportunities/{$opportunity->id}", $payload);

        $response->assertStatus(200);
        $this->assertSame(['Civil Engineering'], $response->json('data.eligible_majors'));
        $this->assertDatabaseCount('opportunity_eligible_majors', 1);
    }

    public function test_an_existing_legacy_field_of_study_opportunity_is_unaffected(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create($this->opportunityAttributes([
            'field_of_study' => 'Computer Science',
        ]));

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.field_of_study', 'Computer Science')
            ->assertJsonPath('data.eligible_majors', []);
    }

    public function test_normalized_values_are_never_exposed_in_the_response(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->opportunityPayload([
            'eligible_majors' => ['Civil Engineering'],
        ]));

        $response->assertStatus(201);
        $this->assertStringNotContainsString('normalized_major_name', $response->getContent());
        $this->assertStringNotContainsString('civil engineering', $response->getContent());
    }

    // -----------------------------------------------------------------
    // Helpers.
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
            // Opportunity Requirements Integrity Patch: required to create
            // through the real endpoint -- most tests in this file are
            // about `eligible_majors` specifically, so this default keeps
            // them from also having to think about Skills.
            'skills' => [['skill_id' => $skillId, 'is_required' => true]],
        ], $overrides);
    }
}
