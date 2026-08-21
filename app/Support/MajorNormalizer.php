<?php

namespace App\Support;

/**
 * The single normalization rule used everywhere a Student's `major` must
 * be compared against an Opportunity's eligible majors (or legacy
 * `field_of_study`) for equivalence (Phase 8B-3.2).
 *
 * Deliberately minimal, mirroring `SkillNameNormalizer` exactly -- trim,
 * lowercase, collapse internal whitespace -- and nothing fuzzier (no
 * stripping of punctuation, no abbreviation expansion, no
 * similarity/semantic matching), so "Civil Engineering" and
 * "civil engineering" normalize together but "Civil Eng." does not
 * silently merge with "Civil Engineering".
 */
class MajorNormalizer
{
    public static function normalize(string $name): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($name)) ?? '';

        return mb_strtolower($collapsed);
    }
}
