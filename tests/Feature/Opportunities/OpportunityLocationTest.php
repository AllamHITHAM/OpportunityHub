<?php

namespace Tests\Feature\Opportunities;

use App\Models\Location;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase O8.2 — an Opportunity's own location now references the canonical
 * Location Catalog by ID. The legacy free-text `location` column is
 * preserved for historical rows (never rewritten) and kept in sync,
 * additively, whenever a new/edited Opportunity sets `location_id` — a
 * forward migration, not a destructive rewrite.
 */
class OpportunityLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_opportunity_with_a_location_id_mirrors_the_canonical_name(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->payload([
            'work_mode' => 'onsite',
            'location_id' => $nablus->id,
        ]));

        $response->assertStatus(201);
        $this->assertSame($nablus->id, $response->json('data.location_id'));
        $this->assertSame('Nablus', $response->json('data.location'));
        $this->assertDatabaseHas('opportunities', [
            'id' => $response->json('data.id'),
            'location_id' => $nablus->id,
            'location' => 'Nablus',
        ]);
    }

    public function test_a_remote_opportunity_needs_no_location(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->payload(['work_mode' => 'remote']));

        $response->assertStatus(201);
        $this->assertNull($response->json('data.location_id'));
        $this->assertNull($response->json('data.location'));
    }

    public function test_a_nonexistent_location_id_is_rejected_on_create(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/opportunities', $this->payload(['location_id' => 99999]))
            ->assertStatus(422);
    }

    public function test_free_text_location_is_no_longer_accepted_on_create(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/opportunities', $this->payload([
            'location' => 'Nablus, Palestine',
        ]));

        $response->assertStatus(201);
        // The raw string is silently ignored (not a validated field) --
        // never persisted as-is.
        $this->assertNull($response->json('data.location'));
    }

    public function test_updating_the_location_id_changes_the_mirrored_name(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(array_merge($this->baseAttributes(), [
            'work_mode' => 'onsite',
            'location_id' => $nablus->id,
            'location' => 'Nablus',
        ]));
        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->payload(['work_mode' => 'onsite', 'location_id' => $ramallah->id]),
        );

        $response->assertStatus(200);
        $this->assertSame('Ramallah', $response->json('data.location'));
        $this->assertSame($ramallah->id, $response->json('data.location_id'));
    }

    public function test_updating_location_id_to_null_clears_the_mirrored_name(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(array_merge($this->baseAttributes(), [
            'work_mode' => 'onsite',
            'location_id' => $nablus->id,
            'location' => 'Nablus',
        ]));
        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->payload(['work_mode' => 'remote', 'location_id' => null]),
        );

        $response->assertStatus(200);
        $this->assertNull($response->json('data.location'));
        $this->assertNull($response->json('data.location_id'));
    }

    public function test_a_historical_opportunitys_free_text_location_is_never_rewritten(): void
    {
        // Simulates an Opportunity created before this phase: a real
        // free-text `location`, no `location_id` at all.
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create(array_merge($this->baseAttributes(), [
            'location' => 'Nablus City',
        ]));

        Sanctum::actingAs($org->user);

        // An unrelated edit (e.g. just the title) that never mentions
        // location_id at all must leave the historical string exactly as
        // it was -- never silently cleared or rewritten.
        $response = $this->putJson(
            "/api/organization/opportunities/{$opportunity->id}",
            $this->payload(['title' => 'Updated Title']),
        );

        $response->assertStatus(200);
        $this->assertSame('Nablus City', $response->json('data.location'));
        $this->assertNull($response->json('data.location_id'));
        $this->assertDatabaseHas('opportunities', [
            'id' => $opportunity->id,
            'location' => 'Nablus City',
            'location_id' => null,
        ]);
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

    private function baseAttributes(): array
    {
        return [
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ];
    }

    private function payload(array $overrides = []): array
    {
        $skillId = Skill::firstOrCreate(['name' => 'PHP'])->id;

        return array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            // Opportunity Requirements Integrity Patch: required to
            // create through the real endpoint -- this file is about
            // `location`/`location_id` specifically.
            'eligible_majors' => ['Computer Science'],
            'skills' => [['skill_id' => $skillId, 'is_required' => true]],
        ], $overrides);
    }
}
