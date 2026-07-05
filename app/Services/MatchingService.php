<?php

namespace App\Services;

use App\Models\Application;

class MatchingService
{
    /**
     * Rough "years of experience" thresholds per opportunity experience level,
     * used only as a heuristic until student_profiles has a real experience field.
     */
    private const EXPERIENCE_LEVEL_YEARS = [
        'no_experience' => 0,
        'junior' => 1,
        'mid' => 3,
        'senior' => 6,
        'expert' => 10,
    ];

    /**
     * Fixed neutral placeholder until student_profiles has a structured education_level field.
     */
    private const EDUCATION_MATCH_PLACEHOLDER = 50.0;

    public function analyze(Application $application): array
    {
        $application->loadMissing([
            'opportunity.opportunitySkills.skill',
            'studentProfile.studentSkills.skill',
        ]);

        $skills = $this->analyzeSkills($application);
        $experienceScore = $this->analyzeExperience($application);
        $educationScore = self::EDUCATION_MATCH_PLACEHOLDER;

        $overallScore = round(
            ($skills['score'] * 0.625) +
            ($experienceScore * 0.25) +
            ($educationScore * 0.125),
            2
        );

        return [
            'overall_match_score' => $overallScore,
            'skills_match_score' => $skills['score'],
            'education_match_score' => $educationScore,
            'experience_match_score' => $experienceScore,
            'strengths' => $skills['strengths'],
            'weaknesses' => $skills['weaknesses'],
            'recommendation' => $this->buildRecommendation($overallScore),
        ];
    }

    private function analyzeSkills(Application $application): array
    {
        $opportunitySkills = $application->opportunity->opportunitySkills;
        $studentSkillIds = $application->studentProfile->studentSkills->pluck('skill_id')->all();

        $requiredSkills = $opportunitySkills->where('is_required', true);
        $preferredSkills = $opportunitySkills->where('is_required', false);

        $requiredMatchedCount = $requiredSkills->whereIn('skill_id', $studentSkillIds)->count();
        $preferredMatchedCount = $preferredSkills->whereIn('skill_id', $studentSkillIds)->count();

        // Required skills count double towards the score, preferred skills count once.
        $totalWeight = ($requiredSkills->count() * 2) + $preferredSkills->count();
        $matchedWeight = ($requiredMatchedCount * 2) + $preferredMatchedCount;

        $score = $totalWeight > 0
            ? round(($matchedWeight / $totalWeight) * 100, 2)
            : 100.0;

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

    private function analyzeExperience(Application $application): float
    {
        $requiredYears = self::EXPERIENCE_LEVEL_YEARS[$application->opportunity->experience_level] ?? 0;

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
