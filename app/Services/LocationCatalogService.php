<?php

namespace App\Services;

use App\Models\Location;
use App\Models\LocationAlias;
use App\Support\LocationNormalizer;
use Illuminate\Database\QueryException;

/**
 * The single backend authority for resolving a typed location name to one
 * canonical [Location] (Recommendation Accuracy Patch) -- used by the
 * typed-search-or-add flow on Student "Current Location"/"Available Work
 * Locations". Comparison is always by `LocationNormalizer::normalize()`
 * against either `locations.canonical_name` or a known `location_aliases`
 * row, never a raw/fuzzy string match -- "Nablus", "Nablus City", and
 * "نابلس" all resolve to the same [Location] only because an alias row
 * says so, never because they merely look similar.
 */
class LocationCatalogService
{
    /**
     * Finds an existing [Location] whose canonical name or a known alias
     * normalizes to [$name]. Returns `null` when nothing matches -- this is
     * never guessed or fuzzy-widened into a "closest" result.
     */
    public function findByNameOrAlias(string $name): ?Location
    {
        $normalized = LocationNormalizer::normalize($name);

        if ($normalized === '') {
            return null;
        }

        $location = Location::query()
            ->get(['id', 'canonical_name'])
            ->first(fn (Location $candidate) => LocationNormalizer::normalize($candidate->canonical_name) === $normalized);

        if ($location !== null) {
            return $location;
        }

        $alias = LocationAlias::where('normalized_alias', $normalized)->first();

        return $alias?->location;
    }

    /**
     * Resolves [$name] to a canonical [Location], reusing an existing one
     * (by canonical name or alias) whenever possible and creating a brand
     * new canonical row only when nothing at all matches. Never creates a
     * duplicate: a second call with the same (or an equivalent, per
     * [findByNameOrAlias]) name always returns the same row.
     *
     * @return array{location: Location, created: bool}
     */
    public function findOrCreate(string $name): array
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        $existing = $this->findByNameOrAlias($trimmed);
        if ($existing !== null) {
            return ['location' => $existing, 'created' => false];
        }

        try {
            $location = Location::create(['canonical_name' => $trimmed]);

            return ['location' => $location, 'created' => true];
        } catch (QueryException $exception) {
            // A concurrent request created the exact same canonical_name
            // between our lookup and this insert (the `canonical_name`
            // unique constraint rejected us) -- never surface that as a
            // failure, just re-resolve to the row that won the race.
            $winner = $this->findByNameOrAlias($trimmed);
            if ($winner !== null) {
                return ['location' => $winner, 'created' => false];
            }

            throw $exception;
        }
    }
}
