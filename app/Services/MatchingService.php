<?php

namespace App\Services;

use App\Models\Application;

/**
 * Phase 8A-2: deterministic applicant/opportunity matching, v1.1.
 *
 * Base weights: Skills 60, Field/Major 20, Experience 20. A factor is
 * "scoreable" only when its required input actually exists (never a fake
 * placeholder). When a factor is unavailable, its weight is proportionally
 * redistributed across the remaining scoreable factors so the final score
 * always normalizes to 0-100. If literally nothing is scoreable, the
 * overall score is a genuine 0.00 (no measurable match), not null -- this
 * keeps `analyze()`'s contract of always returning a real, persistable
 * float, matching how `ApplicationAnalysisController::analyze()` already
 * unconditionally saves `overall_match_score`.
 */
class MatchingService
{
    private const EXPERIENCE_LEVEL_YEARS = [
        'no_experience' => 0,
        'junior' => 1,
        'mid' => 3,
        'senior' => 6,
        'expert' => 10,
    ];

    private const SKILLS_WEIGHT = 60;
    private const FIELD_WEIGHT = 20;
    private const EXPERIENCE_WEIGHT = 20;

    public function analyze(Application $application): array
    {
        $application->loadMissing([
            'opportunity.opportunitySkills.skill',
            'studentProfile.studentSkills.skill',
        ]);

        $skills = $this->analyzeSkills($application);
        $field = $this->analyzeField($application);
        $experienceScore = $this->analyzeExperience($application);

        $overallScore = $this->weightedAverage([
            ['score' => $skills['score'], 'weight' => self::SKILLS_WEIGHT],
            ['score' => $field['score'], 'weight' => self::FIELD_WEIGHT],
            ['score' => $experienceScore, 'weight' => self::EXPERIENCE_WEIGHT],
        ]);

        return [
            'overall_match_score' => $overallScore,
            'skills_match_score' => $skills['score'],
            'field_match_score' => $field['score'],
            'experience_match_score' => $experienceScore,
            'strengths' => array_merge($skills['strengths'], $field['strengths']),
            'weaknesses' => array_merge($skills['weaknesses'], $field['weaknesses']),
            'recommendation' => $this->buildRecommendation($overallScore),
        ];
    }

    /**
     * Weighted average over only the scoreable (non-null) components --
     * each unavailable component's weight is simply excluded from the
     * denominator, which is exactly proportional redistribution. Returns
     * 0.0 (a real, calculated "no measurable match" answer) if nothing at
     * all was scoreable, since division by a zero total weight is undefined.
     */
    private function weightedAverage(array $components): float
    {
        $totalWeight = 0;
        $weightedSum = 0.0;

        foreach ($components as $component) {
            if ($component['score'] === null) {
                continue;
            }

            $totalWeight += $component['weight'];
            $weightedSum += $component['score'] * $component['weight'];
        }

        if ($totalWeight === 0) {
            return 0.0;
        }

        return round($weightedSum / $totalWeight, 2);
    }

    private function analyzeSkills(Application $application): array
    {
        $opportunitySkills = $application->opportunity->opportunitySkills;
        $studentSkillIds = $application->studentProfile->studentSkills->pluck('skill_id')->all();

        $requiredSkills = $opportunitySkills->where('is_required', true);
        $preferredSkills = $opportunitySkills->where('is_required', false);

        // Required skills count double towards the score, preferred skills count once.
        $totalWeight = ($requiredSkills->count() * 2) + $preferredSkills->count();

        if ($totalWeight === 0) {
            // The opportunity has no required/preferred skills defined at
            // all -- there is nothing to score against, so this factor is
            // unavailable (its weight redistributes to whichever other
            // factors ARE scoreable), never a fake "100, trivially
            // satisfied" the way an empty requirement list silently
            // scored before this phase.
            return ['score' => null, 'strengths' => [], 'weaknesses' => []];
        }

        $requiredMatchedCount = $requiredSkills->whereIn('skill_id', $studentSkillIds)->count();
        $preferredMatchedCount = $preferredSkills->whereIn('skill_id', $studentSkillIds)->count();
        $matchedWeight = ($requiredMatchedCount * 2) + $preferredMatchedCount;

        $score = round(($matchedWeight / $totalWeight) * 100, 2);

        $strengths = [];
        $weaknesses = [];

        foreach ($requiredSkills as $requirement) {
            $name = $requirement->skill->name;

            if (in_array($requirement->skill_id, $studentSkillIds, true)) {
                $strengths[] = "Matches required skill: {$name}";
            } else {
                $weaknesses[] = "Missing required skill: {$name}";
            }
        }

        foreach ($preferredSkills as $requirement) {
            $name = $requirement->skill->name;

            if (in_array($requirement->skill_id, $studentSkillIds, true)) {
                $strengths[] = "Matches preferred skill: {$name}";
            } else {
                $weaknesses[] = "Missing preferred skill: {$name}";
            }
        }

        return [
            'score' => $score,
            'strengths' => $strengths,
            'weaknesses' => $weaknesses,
        ];
    }

    /**
     * Compares `studentProfile.major` against `opportunity.field_of_study`
     * after normalizing both (trim, collapse internal whitespace,
     * lowercase). An exact match, or one value containing the other as a
     * whole word-boundary-anchored segment (e.g. "Civil Engineering"
     * inside "Civil Engineering and Construction"), scores 100; any other
     * pair of real values scores 0. This is deliberately not fuzzy/
     * Levenshtein/NLP matching -- a boundary-anchored substring check
     * avoids trivial false positives (e.g. "Art" inside "Part-time Arts
     * Program") while staying simple and deterministic.
     *
     * If either side is null/blank, the factor is unavailable (not a
     * zero) and its weight redistributes.
     */
    private function analyzeField(Application $application): array
    {
        $major = $this->normalizeForComparison($application->studentProfile->major);
        $fieldOfStudy = $this->normalizeForComparison($application->opportunity->field_of_study);

        if ($major === null || $fieldOfStudy === null) {
            return ['score' => null, 'strengths' => [], 'weaknesses' => []];
        }

        $matches = $major === $fieldOfStudy
            || $this->containsAsWholeSegment($major, $fieldOfStudy)
            || $this->containsAsWholeSegment($fieldOfStudy, $major);

        if ($matches) {
            return [
                'score' => 100.0,
                'strengths' => ["Matches field of study: {$application->opportunity->field_of_study}"],
                'weaknesses' => [],
            ];
        }

        return [
            'score' => 0.0,
            'strengths' => [],
            'weaknesses' => ["Field of study does not match: expected {$application->opportunity->field_of_study}"],
        ];
    }

    private function normalizeForComparison(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $value)));

        return $normalized === '' ? null : $normalized;
    }

    private function containsAsWholeSegment(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/(^|\s)'.preg_quote($needle, '/').'(\s|$)/', $haystack);
    }

    private function analyzeExperience(Application $application): ?float
    {
        $experienceLevel = $application->opportunity->experience_level;

        if ($experienceLevel === null || !array_key_exists($experienceLevel, self::EXPERIENCE_LEVEL_YEARS)) {
            // Defensive only -- `experience_level` is a required,
            // non-nullable column on Opportunity, so this branch is not
            // reachable through normal opportunity creation today. Kept
            // so a future schema relaxation (or a legacy/malformed row)
            // degrades to "unavailable" rather than silently mis-scoring
            // against an assumed 0-year requirement.
            return null;
        }

        $requiredYears = self::EXPERIENCE_LEVEL_YEARS[$experienceLevel];

        if ($requiredYears === 0) {
            return 100.0;
        }

        $studentYears = (float) ($application->studentProfile->studentSkills->max('years_of_experience') ?? 0);

        return round(min($studentYears / $requiredYears, 1) * 100, 2);
    }

    private function buildRecommendation(float $overallScore): string
    {
        if ($overallScore >= 75) {
            return 'Strong candidate, recommended for interview.';
        }

        if ($overallScore >= 50) {
            return 'Moderate match, consider reviewing manually.';
        }

        return 'Low match, may not meet requirements.';
    }
}
