<?php

namespace App\Services;

use App\Models\Opportunity;
use App\Models\StudentProfile;
use App\Support\MajorNormalizer;
use Illuminate\Support\Collection;

/**
 * The single backend authority for "is this Student's major an
 * acceptable fit for this Opportunity" (Phase 8B-3.2) -- a pre-application
 * eligibility guard, deliberately unrelated to `MatchingService`/
 * `match_score`: eligibility is a yes/no gate checked *before* an
 * Application can exist; `match_score` is a ranking aid computed *after*
 * one does. Neither Skills nor match_score are ever read here.
 *
 * Used identically from `Organization\CandidateController` (opportunity-
 * scoped Candidate Search), `Organization\InvitationController` (before
 * creating an Invitation), and `Student\ApplicationController` (before
 * creating an Application) -- one shared implementation, so the
 * normalization/matching rule can never drift between the three call
 * sites. [isLocationEligible] and [isTypeInterestEligible] below are
 * Recommendation-only extensions (see each method's own doc comment) --
 * they do not gate direct Apply or Invitation creation.
 */
class OpportunityEligibilityService
{
    /**
     * Eligibility rule (see docs/BUSINESS_RULES.md for the full
     * rationale):
     *
     * A. [$opportunity] has explicit eligible majors (`eligibleMajors`
     *    rows) -- [$student]'s `major` must normalize-match at least one
     *    of them.
     * B. [$opportunity] has no explicit eligible majors -- unrestricted,
     *    always eligible. `field_of_study` is descriptive metadata about
     *    the opportunity, never an eligibility restriction, so it is
     *    deliberately never consulted here -- see [Opportunity]'s own doc
     *    comment on `field_of_study` vs. `eligibleMajors`.
     */
    public function isStudentEligible(Opportunity $opportunity, StudentProfile $student): bool
    {
        $eligibleMajors = $opportunity->eligibleMajorRecords;

        if ($eligibleMajors->isEmpty()) {
            return true;
        }

        return $this->majorMatchesAny($student->major, $eligibleMajors->pluck('normalized_major_name'));
    }

    /**
     * @param  Collection<int, string>  $normalizedTargets
     */
    private function majorMatchesAny(?string $studentMajor, Collection $normalizedTargets): bool
    {
        if ($studentMajor === null || trim($studentMajor) === '') {
            return false;
        }

        return $normalizedTargets->contains(MajorNormalizer::normalize($studentMajor));
    }

    /**
     * Location eligibility (Phase O8.2) -- deliberately a *separate*
     * method from [isStudentEligible], not folded into it: major
     * eligibility already gates direct Apply and Invitation creation, and
     * folding location in there too would immediately make every existing
     * Student -- none of whom have any [StudentProfile::availableLocations]
     * configured yet, since the field is brand new -- unable to apply to
     * or be invited to any On-site/Hybrid Opportunity until they fill it
     * in. That is a much larger, retroactive behavior change than this
     * phase asked for. This method is used only by
     * `Organization\OpportunityRecommendationController`, where being
     * excluded from a *recommendation* list carries no such risk -- the
     * Student can still be found via Candidate Search or apply directly.
     *
     * Rule (see docs/BUSINESS_RULES.md section 5a-i for the full table):
     * - `work_mode = remote`: location is irrelevant -- always eligible,
     *   never consulted at all.
     * - `work_mode = onsite` or `hybrid`, but the Opportunity itself has
     *   no `location_id` set (a historical Opportunity from before this
     *   phase, or one an Organization simply hasn't set yet): there is
     *   nothing to compare against, so -- mirroring rule B of
     *   [isStudentEligible] above (empty `eligibleMajors` = unrestricted)
     *   -- this is treated as unrestricted, not a block.
     * - `work_mode = onsite` or `hybrid`, and the Opportunity has a real
     *   `location_id`: the Student is eligible only if that ID appears in
     *   their own `availableLocations`. A Student with zero configured
     *   locations is never guessed into either outcome -- they are simply
     *   not eligible (excluded from ranking), the truthful "cannot
     *   confirm" answer, never a false positive.
     */
    public function isLocationEligible(Opportunity $opportunity, StudentProfile $student): bool
    {
        if ($opportunity->work_mode === 'remote') {
            return true;
        }

        if ($opportunity->location_id === null) {
            return true;
        }

        $availableLocationIds = $student->availableLocations->pluck('id');

        return $availableLocationIds->contains($opportunity->location_id);
    }

    /**
     * Opportunity Type interest eligibility (Candidate Opportunity
     * Preferences patch) -- mirrors [isLocationEligible]'s scoping
     * exactly: used only by `Organization\OpportunityRecommendationController`,
     * never by direct Apply or Invitation creation (this Student
     * preference does not restrict what a Student may apply to or be
     * invited to -- it only narrows who is proactively *recommended*).
     *
     * A Student with no `interested_in` recorded at all (`null` or an
     * empty array -- every profile created before this patch, since the
     * column is never backfilled) is treated as unrestricted, matching
     * every other "nothing configured" rule in this service
     * ([isStudentEligible] rule B, [isLocationEligible]'s unconfigured-
     * location case): missing preference data excludes no one, it is
     * never guessed into a restriction.
     */
    public function isTypeInterestEligible(Opportunity $opportunity, StudentProfile $student): bool
    {
        $interestedIn = $student->interested_in;

        if ($interestedIn === null || $interestedIn === []) {
            return true;
        }

        return in_array($opportunity->opportunity_type, $interestedIn, true);
    }
}
