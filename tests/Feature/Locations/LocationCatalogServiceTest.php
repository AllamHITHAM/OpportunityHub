<?php

namespace Tests\Feature\Locations;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Services\LocationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recommendation Accuracy Patch -- the typed-search-or-add resolution
 * service. Comparison is always by normalized canonical name or a known
 * alias, never a raw/fuzzy string match; a location is only ever created
 * when nothing at all matches.
 */
class LocationCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_by_name_matches_canonical_name_case_insensitively(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $service = app(LocationCatalogService::class);

        $found = $service->findByNameOrAlias('nablus');

        $this->assertSame($nablus->id, $found?->id);
    }

    public function test_find_by_name_matches_a_known_alias(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        LocationAlias::create([
            'location_id' => $nablus->id,
            'alias' => 'نابلس',
            'normalized_alias' => 'نابلس',
        ]);
        $service = app(LocationCatalogService::class);

        $found = $service->findByNameOrAlias('نابلس');

        $this->assertSame($nablus->id, $found?->id);
    }

    public function test_find_by_name_returns_null_when_nothing_matches(): void
    {
        Location::create(['canonical_name' => 'Nablus']);
        $service = app(LocationCatalogService::class);

        $this->assertNull($service->findByNameOrAlias('Some City'));
    }

    public function test_find_or_create_reuses_the_existing_canonical_location(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $service = app(LocationCatalogService::class);

        $result = $service->findOrCreate('Nablus');

        $this->assertFalse($result['created']);
        $this->assertSame($nablus->id, $result['location']->id);
        $this->assertSame(1, Location::count());
    }

    public function test_find_or_create_reuses_via_a_known_alias(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        LocationAlias::create([
            'location_id' => $nablus->id,
            'alias' => 'Nablus, Palestine',
            'normalized_alias' => 'nablus, palestine',
        ]);
        $service = app(LocationCatalogService::class);

        $result = $service->findOrCreate('Nablus, Palestine');

        $this->assertFalse($result['created']);
        $this->assertSame($nablus->id, $result['location']->id);
        $this->assertSame(1, Location::count());
    }

    public function test_find_or_create_creates_a_brand_new_location_when_nothing_matches(): void
    {
        $service = app(LocationCatalogService::class);

        $result = $service->findOrCreate('Some City');

        $this->assertTrue($result['created']);
        $this->assertSame('Some City', $result['location']->canonical_name);
        $this->assertSame(1, Location::count());
    }

    public function test_find_or_create_never_creates_a_duplicate_normalized_canonical_name(): void
    {
        $service = app(LocationCatalogService::class);

        $first = $service->findOrCreate('Some City');
        $second = $service->findOrCreate('  some   city  ');

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['location']->id, $second['location']->id);
        $this->assertSame(1, Location::count());
    }

    public function test_find_or_create_trims_whitespace_before_creating(): void
    {
        $service = app(LocationCatalogService::class);

        $result = $service->findOrCreate('  Some City  ');

        $this->assertSame('Some City', $result['location']->canonical_name);
    }

    public function test_two_different_cities_are_never_treated_as_the_same_location(): void
    {
        $service = app(LocationCatalogService::class);

        $first = $service->findOrCreate('Nablus');
        $second = $service->findOrCreate('Ramallah');

        $this->assertNotSame($first['location']->id, $second['location']->id);
        $this->assertSame(2, Location::count());
    }
}
