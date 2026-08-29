<?php

namespace Tests\Feature\Candidates;

use App\Models\Location;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\MajorNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recommendation Accuracy Patch -- the deterministic `match_breakdown`
 * exposed on each Recommended Candidates row, built entirely from real,
 * already-computed matching inputs (never a second algorithm, never a
 * fabricated explanation). Also the three manual E2E scenarios from the
 * patch spec (Remote/On-site match/On-site mismatch), reproduced as real
 * regression tests against the actual HTTP endpoint.
 */
class MatchBreakdownTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // match_breakdown: skills.
    // -----------------------------------------------------------------

    public function test_match_breakdown_reports_required_skill_counts_and_names(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);
        $autoCad = Skill::create(['name' => 'AutoCAD']);
        $quantitySurveying = Skill::create(['name' => 'Quantity Surveying']);
        $revit = Skill::create(['name' => 'Revit']);
        $opportunity->opportunitySkills()->create(['skill_id' => $autoCad->id, 'is_required' => true]);
        $opportunity->opportunitySkills()->create(['skill_id' => $quantitySurveying->id, 'is_required' => true]);
        $opportunity->opportunitySkills()->create(['skill_id' => $revit->id, 'is_required' => true]);

        $student = $this->studentWithProfile(name: 'Omar');
        $student->studentSkills()->create(['skill_id' => $autoCad->id, 'level' => 'intermediate']);
        $student->studentSkills()->create(['skill_id' => $quantitySurveying->id, 'level' => 'intermediate']);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $breakdown = $response->json('data.candidates.0.match_breakdown');
        $this->assertSame(3, $breakdown['required_skills_total']);
        $this->assertSame(2, $breakdown['required_skills_matched']);
        $this->assertSame(['AutoCAD', 'Quantity Surveying'], $breakdown['matched_required_skills']);
        $this->assertSame(['Revit'], $breakdown['missing_required_skills']);
    }

    public function test_match_breakdown_major_eligibility_is_always_eligible_for_a_listed_candidate(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering']);
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertSame('eligible', $response->json('data.candidates.0.match_breakdown.major_eligibility'));
    }

    // -----------------------------------------------------------------
    // match_breakdown: location.
    // -----------------------------------------------------------------

    public function test_match_breakdown_location_is_not_considered_for_remote(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote', 'field_of_study' => null]);
        $this->studentWithProfile(name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertSame('not_considered', $response->json('data.candidates.0.match_breakdown.location_eligibility'));
    }

    public function test_match_breakdown_location_is_matched_for_onsite(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $nablus->id, 'field_of_study' => null]);
        $student = $this->studentWithProfile(name: 'Omar');
        $student->availableLocations()->sync([$nablus->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertSame('matched', $response->json('data.candidates.0.match_breakdown.location_eligibility'));
    }

    public function test_match_breakdown_location_is_unrestricted_for_a_legacy_onsite_opportunity(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'field_of_study' => null]);
        $this->studentWithProfile(name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertSame('unrestricted', $response->json('data.candidates.0.match_breakdown.location_eligibility'));
    }

    // -----------------------------------------------------------------
    // null-vs-zero (patch section 11): the sub-factor scores must stay
    // genuinely null, never silently coerced to 0, when their input is
    // unavailable.
    // -----------------------------------------------------------------

    public function test_skills_match_score_is_null_not_zero_when_opportunity_has_no_skills(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);
        $this->studentWithProfile(name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertNull($response->json('data.candidates.0.skills_match_score'));
    }

    public function test_field_match_score_is_never_returned_at_all_opportunity_academic_matching_cleanup(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Civil Engineering']);
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertArrayNotHasKey('field_match_score', $response->json('data.candidates.0'));
        $this->assertArrayNotHasKey('major_field_match', $response->json('data.candidates.0.match_breakdown'));
    }

    // -----------------------------------------------------------------
    // Manual E2E prep, section 18 -- reproduced as real regression tests.
    // -----------------------------------------------------------------

    public function test_case_a_remote_candidate_with_no_location_is_recommended_and_location_not_considered(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering']);
        // Remote by default (opportunityFor()'s default work_mode), no
        // location_id set, and Omar has zero location data at all.
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $this->assertSame('Omar', $response->json('data.candidates.0.name'));
        $this->assertSame('not_considered', $response->json('data.candidates.0.match_breakdown.location_eligibility'));
        $this->assertSame('remote', $response->json('data.opportunity.work_mode'));
    }

    public function test_case_b_onsite_candidate_whose_available_locations_include_the_opportunitys_location_is_recommended(): void
    {
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $jenin->id, 'field_of_study' => null]);
        $student = $this->studentWithProfile(name: 'Omar');
        $student->availableLocations()->sync([$jenin->id, $nablus->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $this->assertSame('matched', $response->json('data.candidates.0.match_breakdown.location_eligibility'));
    }

    public function test_current_location_alone_never_substitutes_for_available_work_locations(): void
    {
        // A Student whose ONLY location data is `current_location_id`
        // (never `available_location_ids`) must still be excluded from an
        // On-site Opportunity's Recommended Candidates -- current location
        // is informational only and is never consulted by location
        // eligibility (see OpportunityEligibilityService::isLocationEligible()).
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $jenin->id, 'field_of_study' => null]);
        $student = $this->studentWithProfile(['current_location_id' => $jenin->id], name: 'Omar');
        $this->assertCount(0, $student->fresh()->availableLocations);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
    }

    public function test_case_c_onsite_candidate_whose_available_locations_exclude_the_opportunitys_location_is_not_recommended(): void
    {
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $ramallah->id, 'field_of_study' => null]);
        $student = $this->studentWithProfile(name: 'Omar');
        $student->availableLocations()->sync([$jenin->id, $nablus->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror OpportunityRecommendationTest's own conventions.
    // -----------------------------------------------------------------

    private function opportunityWithMajors(object $org, array $majors): Opportunity
    {
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);

        foreach ($majors as $major) {
            $opportunity->eligibleMajorRecords()->create([
                'major_name' => $major,
                'normalized_major_name' => MajorNormalizer::normalize($major),
            ]);
        }

        return $opportunity->fresh();
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
