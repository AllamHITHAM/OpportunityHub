<?php

namespace App\Support;

/**
 * The single normalization rule used everywhere a typed location name must
 * be compared against the canonical Location Catalog for equivalence
 * (Recommendation Accuracy Patch) -- mirrors `MajorNormalizer`/
 * `SkillNameNormalizer` exactly: trim, lowercase (`mb_strtolower`, so
 * non-Latin scripts like Arabic are left correctly untouched rather than
 * mangled by a byte-wise `strtolower`), collapse internal whitespace, and
 * nothing fuzzier -- no diacritic stripping, no transliteration, no
 * similarity/geographic matching. Two different real cities must never be
 * silently treated as the same place; this only ever collapses genuinely
 * equivalent spellings/casing/spacing of the *same* typed text.
 */
class LocationNormalizer
{
    public static function normalize(string $name): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }
}
