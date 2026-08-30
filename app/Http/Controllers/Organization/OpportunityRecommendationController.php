<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Invitation;
use App\Models\Opportunity;
use App\Models\StudentProfile;
use App\Services\MatchingService;
use App\Services\OpportunityEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase O8.1 — for one of the Organization's own Opportunities, ranks its
 * real, eligible Student candidates by the exact same match formula
 * `Organization\ApplicationAnalysisController` uses for a real Application
 * — computed live via `MatchingService::scoreCandidate()`, which never
 * inserts a throwaway `Application` row just to produce a number (see that
 * method's own doc comment).
 *
 * Eligibility is applied *before* ranking, on three independent axes, all
 * via `OpportunityEligibilityService`: Opportunity Type interest
 * (`isTypeInterestEligible()`, Candidate Opportunity Preferences —
 * Recommendation-only, same scoping as location), canonical Major
 * (`isStudentEligible()` — the same authority `Organization\CandidateController`
 * and `Organization\InvitationController` also use), and canonical
 * Location (`isLocationEligible()`). An explicitly ineligible candidate on
 * any of the three is never scored or returned here at all, so a
 * mismatched type/major/location can never be "promoted" by a high
 * skills score.
 *
 * Reuses `CandidateController::index()`'s exact safe-field candidate shape
 * (see that controller's own doc comment on why every field is built
 * explicitly rather than serializing `StudentProfile`/`User` directly),
 * extended with the real match score/factor breakdown and this
 * Opportunity's real Application/Invitation relationship state.
 */
class OpportunityRecommendationController extends Controller
{
    public function __construct(
        private readonly OpportunityEligibilityService $eligibility,
        private readonly MatchingService $matching,
    ) {
    }

    public function index(Opportunity $opportunity, Request $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        if ($opportunity->organization_id !== $organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        // Loaded once for the single Opportunity -- `eligibleMajorRecords`
        // and `locationRecord` for the two eligibility gates,
        // `opportunitySkills.skill` so `MatchingService::scoreCandidate()`
        // never re-queries it per candidate below (avoiding an N+1 across
        // possibly many students).
        $opportunity->loadMissing(['eligibleMajorRecords', 'locationRecord', 'opportunitySkills.skill']);

        $candidates = StudentProfile::query()
            ->whereHas('user', function ($query) {
                $query->where('status', 'active');
            })
            ->with(['studentSkills.skill', 'availableLocations', 'currentLocation', 'educationVerification', 'user:id,name,email'])
            ->orderBy('id')
            ->get()
            // Candidate Opportunity Preferences: Opportunity Type interest
            // is checked first (cheapest, no DB-relation reads), then the
            // pre-existing Major and Location gates -- all three must pass
            // before a candidate is scored or shown at all.
            ->filter(fn (StudentProfile $profile) => $this->eligibility->isTypeInterestEligible($opportunity, $profile)
                && $this->eligibility->isStudentEligible($opportunity, $profile)
                && $this->eligibility->isLocationEligible($opportunity, $profile))
            ->values();

        $applicationsByStudentId = Application::where('opportunity_id', $opportunity->id)
            ->get(['id', 'student_id'])
            ->keyBy('student_id');
        $invitationsByStudentId = Invitation::where('opportunity_id', $opportunity->id)
            ->get(['student_id', 'status'])
            ->keyBy('student_id');

        // Recommendation Accuracy Patch: the real, truthful "Location
        // Eligibility" state for every candidate below, computed once from
        // the Opportunity alone (not per-candidate) since it never varies
        // across candidates -- every one of them already passed
        // isLocationEligible() to even be in $candidates above:
        // - 'not_considered': work_mode is remote, location is never
        //   consulted at all -- never claim it was "matched".
        // - 'unrestricted': on-site/hybrid but this Opportunity has no
        //   canonical location set -- nothing was compared against.
        // - 'matched': on-site/hybrid with a real location_id -- every
        //   candidate here genuinely has it in their availableLocations.
        $locationEligibilityStatus = match (true) {
            $opportunity->work_mode === 'remote' => 'not_considered',
            $opportunity->location_id === null => 'unrestricted',
            default => 'matched',
        };

        $ranked = $candidates->map(function (StudentProfile $profile) use (
            $opportunity,
            $applicationsByStudentId,
            $invitationsByStudentId,
            $locationEligibilityStatus,
        ) {
            // `studentSkills.skill` is already eager-loaded on $profile
            // above, and `opportunitySkills.skill` on $opportunity --
            // scoreCandidate()'s own loadMissing() calls are no-ops here,
            // never a fresh query per candidate.
            $score = $this->matching->scoreCandidate($profile, $opportunity);
            $application = $applicationsByStudentId->get($profile->id);
            $invitation = $invitationsByStudentId->get($profile->id);

            $candidate = [
                'id' => $profile->id,
                'name' => $profile->user->name,
                'university' => $profile->university,
                'major' => $profile->major,
                'graduation_year' => $profile->graduation_year,
                'bio' => $profile->bio,
                'profile_photo_url' => $profile->profile_photo_url,
                'education_verification_status' => $profile->education_verification_status,
                'current_location' => $profile->currentLocation
                    ? ['id' => $profile->currentLocation->id, 'canonical_name' => $profile->currentLocation->canonical_name]
                    : null,
                'available_locations' => $profile->availableLocations->map(fn ($location) => [
                    'id' => $location->id,
                    'canonical_name' => $location->canonical_name,
                ])->values(),
                'skills' => $profile->studentSkills->map(fn ($studentSkill) => [
                    'name' => $studentSkill->skill->name,
                    'source' => $studentSkill->source,
                ])->values(),
                'match_score' => $score['overall_match_score'],
                'skills_match_score' => $score['skills_match_score'],
                'major_match_score' => $score['major_match_score'],
                'location_match_score' => $score['location_match_score'],
                'already_applied' => $application !== null,
                'application_id' => $application?->id,
                // null (never invited), or the real invitations.status
                // value -- 'pending', 'accepted', or 'declined'.
                'invitation_status' => $invitation?->status,
                // Matching Formula Audit, extended by the Opportunity
                // Academic Matching Cleanup, extended again by
                // "Recommendation Match: Major Must Contribute to Total
                // Score", extended once more by "Candidate Opportunity
                // Preferences + Final Recommendation Match Formula" -- a
                // deterministic "why this score" breakdown built entirely
                // from real, already-computed
                // MatchingService/OpportunityEligibilityService inputs --
                // never a fabricated explanation, never a second formula.
                // Every *_contribution/*_weight pair below is already
                // expressed in the exact points that sum to `match_score`
                // -- the client never multiplies a raw score by a weight
                // itself, it only ever displays these numbers.
                // 'major_eligibility' is always 'eligible' here: every
                // candidate in $candidates already passed
                // isStudentEligible() in the filter() above this map().
                // There is no `experience_match`/`experience_match_score`
                // any more -- Experience was removed from this formula
                // entirely (Final Recommendation Match Formula); the DB
                // column is left untouched but never read here.
                'match_breakdown' => [
                    'major_eligibility' => 'eligible',
                    'academic_match' => $this->academicMatchFactorStatus($score['major_match_score']),
                    'major_match_score' => $score['major_match_score'],
                    'major_weight' => $score['major_weight'],
                    'major_contribution' => $score['major_contribution'],
                    'required_skills_total' => $score['required_skills_total'],
                    'required_skills_matched' => $score['required_skills_matched'],
                    'matched_required_skills' => $score['matched_required_skills'],
                    'missing_required_skills' => $score['missing_required_skills'],
                    'skills_match_score' => $score['skills_match_score'],
                    'skills_weight' => $score['skills_weight'],
                    'skills_contribution' => $score['skills_contribution'],
                    'location_eligibility' => $locationEligibilityStatus,
                    'location_match_score' => $score['location_match_score'],
                    'location_weight' => $score['location_weight'],
                    'location_contribution' => $score['location_contribution'],
                ],
            ];

            // Organization Candidate Profile Enrichment: same
            // application_id-gated contact rule as
            // `Organization\CandidateController::index()` -- see that
            // controller's doc comment. A Recommended Candidate is never
            // required to have applied, so this is `null`/absent for most
            // rows, present only once a real Application already exists.
            if ($application !== null) {
                $candidate['phone'] = $profile->phone;
                $candidate['email'] = $profile->user->email;
            }

            return $candidate;
        });

        // Highest match first; deterministic tie-break on student ID so
        // repeated requests against unchanged data always return the same
        // order (never randomized, never dependent on map/collection
        // iteration order).
        $sorted = $ranked->sort(function (array $a, array $b) {
            if ($a['match_score'] !== $b['match_score']) {
                return $b['match_score'] <=> $a['match_score'];
            }

            return $a['id'] <=> $b['id'];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Recommended candidates retrieved successfully',
            'data' => [
                // Phase O8.2: the real Opportunity context the Flutter
                // Recommended Candidates screen needs to render a
                // *truthful* explanation of what was actually filtered/
                // ranked -- in particular, `work_mode` so the copy never
                // claims location was considered for a Remote Opportunity.
                // `opportunity_type` (Candidate Opportunity Preferences)
                // lets the screen render real, dynamic "these candidates
                // are interested in {type} opportunities" copy without
                // relying on the caller to have separately passed it in.
                // Hand-assembled, not the raw model, so this never grows
                // to leak more than these fields by accident.
                'opportunity' => [
                    'opportunity_type' => $opportunity->opportunity_type,
                    'work_mode' => $opportunity->work_mode,
                    'location' => $opportunity->locationRecord
                        ? [
                            'id' => $opportunity->locationRecord->id,
                            'canonical_name' => $opportunity->locationRecord->canonical_name,
                        ]
                        : null,
                ],
                'candidates' => $sorted,
            ],
        ]);
    }

    /**
     * Classifies `MatchingService::analyzeMajor()`'s real
     * `major_match_score` ("Recommendation Match: Major Must Contribute to
     * Total Score") -- binary, unlike experience, since `analyzeMajor()`
     * itself only ever returns `null`, `0.0`, or `100.0` (no partial
     * credit for a canonical major match):
     * - `null`: 'not_applicable' (the Opportunity has no
     *   `eligibleMajorRecords` configured -- unrestricted, nothing to
     *   compare against).
     * - `100.0`: 'matched'.
     * - anything else (`0.0`): 'not_matched'.
     */
    private function academicMatchFactorStatus(?float $majorMatchScore): string
    {
        return match (true) {
            $majorMatchScore === null => 'not_applicable',
            $majorMatchScore >= 100.0 => 'matched',
            default => 'not_matched',
        };
    }
}
