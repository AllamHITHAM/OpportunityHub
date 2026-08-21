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
 * sites.
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
     * B. [$opportunity] has no explicit eligible majors but a nonblank
     *    legacy `field_of_study` -- [$student]'s `major` must
     *    normalize-match that single value, using the exact same
     *    normalization rule.
     * C. [$opportunity] has neither -- unrestricted, always eligible.
     *
     * A null/blank [$student] major can never satisfy A or B (there is
     * nothing to compare), so it's only ever eligible under C.
     */
    public function isStudentEligible(Opportunity $opportunity, StudentProfile $student): bool
    {
        $eligibleMajors = $opportunity->eligibleMajorRecords;

        if ($eligibleMajors->isNotEmpty()) {
            return $this->majorMatchesAny($student->major, $eligibleMajors->pluck('normalized_major_name'));
        }

        $fieldOfStudy = $opportunity->field_of_study;
        if ($fieldOfStudy !== null && trim($fieldOfStudy) !== '') {
            return $this->majorMatchesAny($student->major, collect([MajorNormalizer::normalize($fieldOfStudy)]));
        }

        return true;
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
}
