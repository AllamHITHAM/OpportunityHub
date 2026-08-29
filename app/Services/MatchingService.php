<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\StudentProfile;
use App\Support\MajorNormalizer;

/**
 * Phase 8A-2: deterministic applicant/opportunity matching, v2.0
 * ("Candidate Opportunity Preferences + Final Recommendation Match
 * Formula").
 *
 * **Final, product-approved weights (do not alter without a new explicit
 * product decision):**
 *
 * - On-site / Hybrid: Required Skills 60%, Major 25%, Location 15%.
 * - Remote: Required Skills 70%, Major 30%, Location NOT CONSIDERED (never
 *   computed, never part of the denominator, never displayed as `0`).
 *
 * **Experience is completely removed from this formula** (previously 20%
 * of the v1.1-v1.3 weight table). `opportunity.experience_level` is left
 * in the database, untouched, for backward compatibility, but is never
 * read by this class any more, never excludes a candidate, and never
 * appears in `match_breakdown`.
 *
 * **Field/Major history, reaffirmed**: `opportunity.field_of_study`
 * remains completely unused by this class (see the Opportunity Academic
 * Matching Cleanup and Recommendation Match: Major Must Contribute to
 * Total Score, both superseded in weight but not in reasoning by this
 * phase) -- [analyzeMajor] below compares only against the canonical
 * `eligibleMajorRecords`, never `field_of_study`.
 *
 * **Location is new to this formula** (Candidate Opportunity Preferences
 * + Final Recommendation Match Formula) -- [analyzeLocation] compares the
 * Opportunity's canonical `location_id` against the Student's own
 * `availableLocations`, the same canonical IDs
 * `OpportunityEligibilityService::isLocationEligible()` already gates
 * Recommended Candidates with. For Remote, Location is excluded from the
 * weighted average entirely -- not scored as `0`, not redistributed away
 * as "unavailable", simply never computed or included at all, so it can
 * never contribute a phantom `0/15` to a Remote Match.
 *
 * **A factor is "scoreable" only when its required input actually
 * exists** (never a fake placeholder). When a factor is unavailable
 * (Major: the Opportunity has no `eligibleMajorRecords` configured;
 * Location: the Opportunity is On-site/Hybrid but has no `location_id`
 * configured), its nominal weight is proportionally redistributed across
 * the remaining scoreable factors for *that* Student/Opportunity pair --
 * the same technique every prior version of this formula has used for a
 * missing factor. If literally nothing is scoreable, the overall score is
 * a genuine 0.00 (no measurable match), not null.
 *
 * **Every returned factor contribution is expressed in the same points
 * that sum to `overall_match_score`** (never a raw 0-100 factor score the
 * client would have to multiply by a weight itself) -- `overall_match_score`
 * is literally the sum of the already-rounded per-factor contributions
 * below, by construction, so a client displaying each contribution next
 * to the total can never see numbers that fail to add up. Flutter never
 * computes a Match score itself; it only ever displays the numbers this
 * class returns.
 *
 * Phase O8.1: the actual scoring logic (`score()` and its private helpers
 * below) takes a plain `StudentProfile`/`Opportunity` pair, not an
 * `Application` -- [analyze] is now a thin wrapper that unpacks an
 * `Application`'s two relations and delegates to [scoreCandidate], the
 * same method `Organization\OpportunityRecommendationController` uses to
 * rank real candidates *before* an Application exists. This is
 * deliberate: recommending candidates for an Opportunity must reuse the
 * exact same formula an actual Application would later be scored with,
 * without ever inserting a throwaway Application row just to compute a
 * number. One formula, two entry points -- never a second, drifting
 * implementation.
 */
class MatchingService
{
    /**
     * Nominal weights per Opportunity `work_mode` -- the exact,
     * product-approved values from this phase's spec. Never altered
     * dynamically; only proportionally redistributed per-candidate when a
     * factor is genuinely unscoreable (see this class's own doc comment).
     */
    private const WORK_MODE_WEIGHTS = [
        'remote' => ['skills' => 70, 'major' => 30],
        'onsite' => ['skills' => 60, 'major' => 25, 'location' => 15],
        'hybrid' => ['skills' => 60, 'major' => 25, 'location' => 15],
    ];

    public function analyze(Application $application): array
    {
        $application->loadMissing([
            'opportunity.opportunitySkills.skill',
            'opportunity.eligibleMajorRecords',
            'studentProfile.studentSkills.skill',
            'studentProfile.availableLocations',
        ]);

        return $this->score($application->studentProfile, $application->opportunity);
    }

    /**
     * The same Student <-> Opportunity match [analyze] computes, without
     * requiring (or creating) an `Application` row -- used to rank
     * candidates for an Opportunity's real recommendations before any of
     * them have applied. Loads the same relations [analyze] does, so a
     * caller passing in freshly-fetched models never needs to preload
     * anything itself.
     */
    public function scoreCandidate(StudentProfile $studentProfile, Opportunity $opportunity): array
    {
        $studentProfile->loadMissing(['studentSkills.skill', 'availableLocations']);
        $opportunity->loadMissing(['opportunitySkills.skill', 'eligibleMajorRecords']);

        return $this->score($studentProfile, $opportunity);
    }

    private function score(StudentProfile $studentProfile, Opportunity $opportunity): array
    {
        $workMode = $opportunity->work_mode;
        $weights = self::WORK_MODE_WEIGHTS[$workMode] ?? self::WORK_MODE_WEIGHTS['onsite'];

        $skills = $this->analyzeSkills($studentProfile, $opportunity);
        $majorScore = $this->analyzeMajor($studentProfile, $opportunity);

        // Remote: Location is never computed at all -- not null, not 0,
        // simply absent from $components below, so it can never enter the
        // weighted average or be redistributed into.
        $components = [
            'skills' => ['score' => $skills['score'], 'weight' => $weights['skills']],
            'major' => ['score' => $majorScore, 'weight' => $weights['major']],
        ];
        $locationScore = null;
        if ($workMode !== 'remote') {
            $locationScore = $this->analyzeLocation($studentProfile, $opportunity);
            $components['location'] = ['score' => $locationScore, 'weight' => $weights['location']];
        }

        [$contributions, $overallScore] = $this->weightedContributions($components);

        return [
            'overall_match_score' => $overallScore,
            'skills_match_score' => $skills['score'],
            'skills_weight' => $components['skills']['weight'],
            'skills_contribution' => $contributions['skills'],
            'major_match_score' => $majorScore,
            'major_weight' => $components['major']['weight'],
            'major_contribution' => $contributions['major'],
            'location_match_score' => $locationScore,
            'location_weight' => $components['location']['weight'] ?? null,
            'location_contribution' => $contributions['location'] ?? null,
            'strengths' => $skills['strengths'],
            'weaknesses' => $skills['weaknesses'],
            'recommendation' => $this->buildRecommendation($overallScore),
            // Recommendation Accuracy Patch: the real required-skill counts
            // and names behind $skills['score'] -- see analyzeSkills().
            'required_skills_total' => $skills['required_total'],
            'required_skills_matched' => $skills['required_matched'],
            'matched_required_skills' => $skills['matched_required_names'],
            'missing_required_skills' => $skills['missing_required_names'],
        ];
    }

    /**
     * Genuinely compares the Student's canonical major against the
     * Opportunity's canonical `eligibleMajorRecords` -- never
     * `opportunity.field_of_study` (deprecated, see this class's own doc
     * comment and `Opportunity::$fillable`'s `@deprecated` note).
     *
     * - Opportunity has no `eligibleMajorRecords` configured (rule B,
     *   unrestricted): there is nothing to compare against, so this
     *   factor is unavailable (`null`, weight redistributes) -- not a
     *   fake neutral value.
     * - Opportunity has `eligibleMajorRecords` configured (rule A,
     *   restricted) and the Student's major normalize-matches one of
     *   them: `100.0`. Every candidate reaching this method through the
     *   normal Recommended-Candidates/new-Application path is in this
     *   branch, since `OpportunityEligibilityService::isStudentEligible()`
     *   already required the identical match as a pre-scoring gate.
     * - Opportunity has `eligibleMajorRecords` configured and the
     *   Student's major is blank, or doesn't match any of them: `0.0`, a
     *   real "no match", not "unavailable". Defensive-only through the
     *   gated paths above; reachable through a stale Application
     *   recalculated after the Student's major changed post-submission.
     */
    private function analyzeMajor(StudentProfile $studentProfile, Opportunity $opportunity): ?float
    {
        $eligibleMajors = $opportunity->eligibleMajorRecords;

        if ($eligibleMajors->isEmpty()) {
            return null;
        }

        if ($studentProfile->major === null || trim($studentProfile->major) === '') {
            return 0.0;
        }

        $normalizedTargets = $eligibleMajors->pluck('normalized_major_name');
        $matches = $normalizedTargets->contains(MajorNormalizer::normalize($studentProfile->major));

        return $matches ? 100.0 : 0.0;
    }

    /**
     * Genuinely compares the Opportunity's canonical `location_id`
     * against the Student's own `availableLocations` -- canonical
     * Location Catalog IDs only, never raw string equality. Only ever
     * called for On-site/Hybrid Opportunities -- Remote never reaches
     * this method at all (see [score]).
     *
     * - Opportunity has no `location_id` configured (a legacy/unconfigured
     *   On-site or Hybrid Opportunity): nothing to compare against, so
     *   this factor is unavailable (`null`, weight redistributes) --
     *   mirroring `OpportunityEligibilityService::isLocationEligible()`'s
     *   identical "unrestricted" treatment of the same case.
     * - Opportunity has a real `location_id` and it appears in the
     *   Student's `availableLocations`: `100.0`. Every candidate reaching
     *   this method through the normal Recommended-Candidates path is in
     *   this branch, since `isLocationEligible()` already required the
     *   identical match as a pre-scoring gate there.
     * - Opportunity has a real `location_id` absent from the Student's
     *   `availableLocations`: `0.0`, a real "no match". Defensive-only
     *   through Recommended Candidates (the gate excludes this case
     *   before scoring); reachable for a stale Application Match Analysis
     *   (Direct Apply is never gated on location eligibility).
     */
    private function analyzeLocation(StudentProfile $studentProfile, Opportunity $opportunity): ?float
    {
        if ($opportunity->location_id === null) {
            return null;
        }

        $availableLocationIds = $studentProfile->availableLocations->pluck('id');

        return $availableLocationIds->contains($opportunity->location_id) ? 100.0 : 0.0;
    }

    /**
     * Converts raw 0-100 factor scores into the exact point contributions
     * that sum to the overall Match -- proportional redistribution over
     * only the scoreable (non-null) components, same technique every
     * prior version of this formula used, but now returning each term's
     * own rounded contribution rather than only the final rounded total.
     *
     * `overall_match_score` is defined as the sum of these already-rounded
     * contributions (not an independently-rounded weighted average) so
     * the two can never disagree by a rounding artifact -- the "why this
     * match" breakdown is guaranteed, by construction, to add up exactly
     * to the displayed total.
     *
     * @param  array<string, array{score: ?float, weight: int}>  $components
     * @return array{0: array<string, ?float>, 1: float}
     */
    private function weightedContributions(array $components): array
    {
        $totalWeight = 0;
        foreach ($components as $component) {
            if ($component['score'] !== null) {
                $totalWeight += $component['weight'];
            }
        }

        $contributions = [];
        $overall = 0.0;

        foreach ($components as $key => $component) {
            if ($component['score'] === null || $totalWeight === 0) {
                $contributions[$key] = null;
                continue;
            }

            $contribution = round($component['score'] * $component['weight'] / $totalWeight, 2);
            $contributions[$key] = $contribution;
            $overall += $contribution;
        }

        return [$contributions, round($overall, 2)];
    }

    private function analyzeSkills(StudentProfile $studentProfile, Opportunity $opportunity): array
    {
        $opportunitySkills = $opportunity->opportunitySkills;
        $studentSkillIds = $studentProfile->studentSkills->pluck('skill_id')->all();

        $requiredSkills = $opportunitySkills->where('is_required', true);
        $preferredSkills = $opportunitySkills->where('is_required', false);

        // Required skills count double towards the score, preferred skills count once.
        $totalWeight = ($requiredSkills->count() * 2) + $preferredSkills->count();

        // Recommendation Accuracy Patch: the real required-skill counts and
        // names, always computed regardless of $totalWeight below -- this
        // is what powers "Why X%?"'s "2 of 3 required skills matched" /
        // matched/missing name lists on Recommended Candidates. Never a
        // second matching algorithm: exactly the same $requiredSkills/
        // $studentSkillIds this method already computes for the score.
        $matchedRequiredNames = [];
        $missingRequiredNames = [];
        foreach ($requiredSkills as $requirement) {
            if (in_array($requirement->skill_id, $studentSkillIds, true)) {
                $matchedRequiredNames[] = $requirement->skill->name;
            } else {
                $missingRequiredNames[] = $requirement->skill->name;
            }
        }
        $requiredBreakdown = [
            'required_total' => $requiredSkills->count(),
            'required_matched' => count($matchedRequiredNames),
            'matched_required_names' => $matchedRequiredNames,
            'missing_required_names' => $missingRequiredNames,
        ];

        if ($totalWeight === 0) {
            // The opportunity has no required/preferred skills defined at
            // all -- there is nothing to score against, so this factor is
            // unavailable (its weight redistributes to whichever other
            // factors ARE scoreable), never a fake "100, trivially
            // satisfied" the way an empty requirement list silently
            // scored before this phase.
            return [
                'score' => null,
                'strengths' => [],
                'weaknesses' => [],
                ...$requiredBreakdown,
            ];
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
            ...$requiredBreakdown,
        ];
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
