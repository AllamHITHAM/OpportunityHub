<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase O8.2 — `GET /api/locations`, the shared canonical Location
 * Catalog both a Student (available work locations) and an Organization
 * (an Opportunity's location) pick from.
 */
class LocationCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_can_fetch_the_location_catalog(): void
    {
        Location::create(['canonical_name' => 'Ramallah']);
        Location::create(['canonical_name' => 'Nablus']);

        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/locations');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_the_catalog_is_ordered_alphabetically_by_canonical_name(): void
    {
        Location::create(['canonical_name' => 'Ramallah']);
        Location::create(['canonical_name' => 'Bethlehem']);
        Location::create(['canonical_name' => 'Nablus']);

        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/locations');

        $this->assertSame(
            ['Bethlehem', 'Nablus', 'Ramallah'],
            collect($response->json('data'))->pluck('canonical_name')->all(),
        );
    }

    public function test_an_organization_can_also_fetch_the_location_catalog(): void
    {
        Location::create(['canonical_name' => 'Jenin']);

        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/locations')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_the_catalog_exposes_only_id_canonical_name_and_alias_names(): void
    {
        Location::create(['canonical_name' => 'Jenin']);

        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/locations');

        // `alias_names` (Recommendation Accuracy Patch) is the only
        // addition -- plain alias text, never the raw `aliases` relation
        // or its internal `normalized_alias` comparison value.
        $this->assertSame(['id', 'canonical_name', 'alias_names'], array_keys($response->json('data.0')));
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/locations')->assertStatus(401);
    }
}
