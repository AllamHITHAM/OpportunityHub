<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Support\LocationNormalizer;
use Illuminate\Database\Seeder;

/**
 * Recommendation Accuracy Patch: real alternate names for
 * `BaselineLocationSeeder`'s starter West Bank/Gaza cities, so the typed
 * location search genuinely resolves common alternate spellings and the
 * Arabic name to the same canonical Location -- not just an empty alias
 * table waiting on manual Admin entry. Deterministic and idempotent
 * (keyed on `normalized_alias`'s unique constraint via `firstOrCreate`),
 * mirroring `BaselineLocationSeeder`'s own shape exactly. Requires
 * `BaselineLocationSeeder` to have already run (skips any city it can't
 * find rather than failing).
 */
class BaselineLocationAliasSeeder extends Seeder
{
    /**
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'Nablus' => ['Nablus City', 'Nablus, Palestine', 'نابلس'],
        'Ramallah' => ['Ramallah City', 'Ramallah, Palestine', 'رام الله'],
        'Jenin' => ['Jenin City', 'Jenin, Palestine', 'جنين'],
        'Hebron' => ['Hebron City', 'Hebron, Palestine', 'الخليل'],
        'Bethlehem' => ['Bethlehem City', 'Bethlehem, Palestine', 'بيت لحم'],
        'Jerusalem' => ['Jerusalem City', 'Jerusalem, Palestine', 'القدس'],
        'Gaza' => ['Gaza City', 'Gaza, Palestine', 'غزة'],
        'Tulkarm' => ['Tulkarm City', 'Tulkarm, Palestine', 'طولكرم'],
        'Qalqilya' => ['Qalqilya City', 'Qalqilya, Palestine', 'قلقيلية'],
        'Salfit' => ['Salfit City', 'Salfit, Palestine', 'سلفيت'],
        'Tubas' => ['Tubas City', 'Tubas, Palestine', 'طوباس'],
        'Jericho' => ['Jericho City', 'Jericho, Palestine', 'أريحا'],
    ];

    public function run(): void
    {
        foreach (self::ALIASES as $canonicalName => $aliases) {
            $location = Location::where('canonical_name', $canonicalName)->first();
            if ($location === null) {
                continue;
            }

            foreach ($aliases as $alias) {
                LocationAlias::firstOrCreate(
                    ['normalized_alias' => LocationNormalizer::normalize($alias)],
                    ['location_id' => $location->id, 'alias' => $alias],
                );
            }
        }
    }
}
