<?php

namespace Tests\Feature\Candidates;

use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Candidate Opportunity Preferences + Final Recommendation Match Formula:
 * `StudentProfile.interested_in` (a set of canonical Opportunity Type
 * values) gates Recommended Candidates -- a Student not interested in an
 * Opportunity's exact `opportunity_type` is excluded entirely, never
 * shown with a docked score. `OpportunityEligibilityService::isTypeInterestEligible()`
 * is the single implementation. This is a Recommendation-only filter --
 * it never gates direct Apply or Invitation creation (see
 * `docs/BUSINESS_RULES.md`).
 */
class OpportunityTypeInterestTest extends TestCase
{
    use RefreshDatabase;

    public function test_internship_opportunity_includes_a_candidate_interested_in_internship(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'internship']);
        $this->studentWithProfile(['interested_in' => ['job', 'internship']], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
    }

    public function test_internship_opportunity_excludes_a_candidate_interested_only_in_job(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'internship']);
        $this->studentWithProfile(['interested_in' => ['job']], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
    }

    public function test_opportunity_type_interest_contributes_zero_score_points(): void
    {
        // The interest match is a pure eligibility gate -- it never
        // appears anywhere in match_breakdown and never influences
        // match_score, unlike Skills/Major/Location.
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'internship']);
        $this->studentWithProfile(['interested_in' => ['internship']], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $row = $response->json('data.candidates.0');

        $this->assertArrayNotHasKey('interested_in', $row['match_breakdown']);
        $this->assertArrayNotHasKey('type_interest', $row['match_breakdown']);
        $this->assertArrayNotHasKey('opportunity_type_match', $row['match_breakdown']);
        // Only Skills + Major (this is a Remote, unrestricted-major
        // Opportunity in this test) determine the score -- confirmed by
        // the reconciliation coverage in MatchingFormulaAuditTest; here
        // we only confirm no interest-related key was smuggled in.
    }

    public function test_a_student_interested_in_multiple_types_is_eligible_for_either(): void
    {
        $org = $this->approvedOrganization();
        $jobOpportunity = $this->opportunityFor($org, ['opportunity_type' => 'job']);
        $internshipOpportunity = $this->opportunityFor($org, ['opportunity_type' => 'internship']);
        $this->studentWithProfile(['interested_in' => ['job', 'internship']], name: 'Omar');

        Sanctum::actingAs($org->user);
        $jobResponse = $this->getJson("/api/organization/opportunities/{$jobOpportunity->id}/recommended-candidates");
        $internshipResponse = $this->getJson(
            "/api/organization/opportunities/{$internshipOpportunity->id}/recommended-candidates"
        );

        $jobResponse->assertJsonCount(1, 'data.candidates');
        $internshipResponse->assertJsonCount(1, 'data.candidates');
    }

    public function test_a_student_interested_in_volunteer_and_scholarship_is_excluded_from_a_job(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'job']);
        $this->studentWithProfile(['interested_in' => ['volunteer', 'scholarship']], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
    }

    public function test_a_candidate_with_no_interested_in_preference_at_all_remains_eligible_for_every_type(): void
    {
        // An existing Student from before this patch (or one who simply
        // hasn't set a preference) is never guessed into a restriction --
        // mirrors every other "nothing configured -> unrestricted" rule
        // in OpportunityEligibilityService (Major rule B, Location's
        // unconfigured-location case).
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'competition']);
        $this->studentWithProfile([], name: 'Omar'); // no `interested_in` at all

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
    }

    public function test_a_candidate_with_an_empty_interested_in_array_remains_eligible_for_every_type(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'scholarship']);
        $this->studentWithProfile(['interested_in' => []], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
    }

    public function test_type_interest_and_major_eligibility_are_both_enforced_together(): void
    {
        // A candidate can fail on Type interest alone, Major alone, or
        // both -- each gate is independently enforced, matching how
        // Major and Location already stack.
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'internship']);
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Civil Engineering',
            'normalized_major_name' => 'civil engineering',
        ]);

        // Right type, wrong major.
        $this->studentWithProfile([
            'interested_in' => ['internship'],
            'major' => 'Fine Arts',
        ], name: 'WrongMajor');
        // Right major, wrong type.
        $this->studentWithProfile([
            'interested_in' => ['job'],
            'major' => 'Civil Engineering',
        ], name: 'WrongType');
        // Both right.
        $this->studentWithProfile([
            'interested_in' => ['internship'],
            'major' => 'Civil Engineering',
        ], name: 'BothRight');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $names = collect($response->json('data.candidates'))->pluck('name')->all();
        $this->assertSame(['BothRight'], $names);
    }

    public function test_the_opportunity_type_is_exposed_in_the_response_for_the_flutter_header_copy(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['opportunity_type' => 'volunteer']);
        Sanctum::actingAs($org->user);

        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertSame('volunteer', $response->json('data.opportunity.opportunity_type'));
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function opportunityFor(object $org, array $overrides = []): Opportunity
    {
        return $org->profile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    private function studentWithProfile(
        array $overrides = [],
        string $name = 'Jane Student',
    ): StudentProfile {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
            'name' => $name,
        ]);

        return StudentProfile::create(array_merge(['user_id' => $user->id], $overrides));
    }
}
