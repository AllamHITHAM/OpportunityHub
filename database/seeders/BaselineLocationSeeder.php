<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Phase O8.2: the starter canonical Location Catalog -- the major West
 * Bank and Gaza cities this app's Students/Organizations actually operate
 * in, so the catalog doesn't depend entirely on manual Admin entry from a
 * completely empty table. Deterministic and idempotent -- safe to run any
 * number of times, mirroring `BaselineSkillSeeder`'s own shape exactly.
 *
 * Also wired into DatabaseSeeder::run(), for the same reason
 * BaselineSkillSeeder is: re-running the whole DatabaseSeeder never
 * duplicates or destroys a Location row.
 */
class BaselineLocationSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const LOCATIONS = [
        'Nablus',
        'Ramallah',
        'Jenin',
        'Hebron',
        'Bethlehem',
        'Jerusalem',
        'Gaza',
        'Tulkarm',
        'Qalqilya',
        'Salfit',
        'Tubas',
        'Jericho',
    ];

    public function run(): void
    {
        foreach (self::LOCATIONS as $canonicalName) {
            // firstOrCreate keyed on the exact `canonical_name` (matching
            // the `locations.canonical_name` unique constraint) -- an
            // existing manually- or previously-seeded row is left
            // completely untouched, never overwritten, never duplicated.
            Location::firstOrCreate(['canonical_name' => $canonicalName]);
        }
    }
}
