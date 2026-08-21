<?php

namespace App\Support;

/**
 * The single normalization rule used everywhere a skill name must be
 * compared for equivalence (Phase 8A-6.1): AI-to-catalog mapping, pending
 * skill-suggestion dedup, and Admin approval duplicate checks.
 *
 * Deliberately minimal -- trim, lowercase, collapse internal whitespace --
 * and nothing fuzzier (no stripping of spaces/punctuation, no
 * Levenshtein/similarity matching), so "AutoCAD" and "autocad" normalize
 * together but "Auto CAD" does not silently merge with "AutoCAD".
 */
class SkillNameNormalizer
{
    public static function normalize(string $name): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }
}
