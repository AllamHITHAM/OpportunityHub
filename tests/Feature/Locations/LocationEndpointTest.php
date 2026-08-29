<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recommendation Accuracy Patch -- `GET /locations` (now with each row's
 * known alias names, for a Flutter typed-search picker to filter locally)
 * and the new `POST /locations` typed-search-or-add endpoint.
 */
class LocationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_includes_each_locations_alias_names(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        LocationAlias::create([
            'location_id' => $nablus->id,
            'alias' => 'نابلس',
            'normalized_alias' => 'نابلس',
        ]);
        Sanctum::actingAs($this->studentUser());

        $response = $this->getJson('/api/locations');

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('canonical_name', 'Nablus');
        $this->assertSame(['نابلس'], $row['alias_names']);
    }

    public function test_store_reuses_an_existing_canonical_location(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/locations', ['name' => 'Nablus']);

        $response->assertStatus(200);
        $this->assertSame($nablus->id, $response->json('data.id'));
        $this->assertSame(1, Location::count());
    }

    public function test_store_reuses_via_a_known_alias(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        LocationAlias::create([
            'location_id' => $nablus->id,
            'alias' => 'نابلس',
            'normalized_alias' => 'نابلس',
        ]);
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/locations', ['name' => 'نابلس']);

        $response->assertStatus(200);
        $this->assertSame($nablus->id, $response->json('data.id'));
        $this->assertSame(1, Location::count());
    }

    public function test_store_creates_a_new_canonical_location_when_nothing_matches(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/locations', ['name' => 'Some City']);

        $response->assertStatus(201);
        $this->assertSame('Some City', $response->json('data.canonical_name'));
        $this->assertSame(1, Location::count());
    }

    public function test_store_never_creates_a_duplicate_for_the_same_normalized_name(): void
    {
        Sanctum::actingAs($this->studentUser());

        $this->postJson('/api/locations', ['name' => 'Some City'])->assertStatus(201);
        $second = $this->postJson('/api/locations', ['name' => '  some   city  '])->assertStatus(200);

        $this->assertSame(1, Location::count());
        $this->assertSame('Some City', $second->json('data.canonical_name'));
    }

    public function test_store_rejects_an_empty_name(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/locations', ['name' => '']);

        $response->assertStatus(422);
        $this->assertSame(0, Location::count());
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/locations', ['name' => 'Some City']);

        $response->assertStatus(401);
    }

    private function studentUser(): User
    {
        return User::factory()->create(['role' => 'student', 'status' => 'active']);
    }
}
