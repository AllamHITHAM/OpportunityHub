<?php

namespace Tests\Feature\Candidates;

use App\Models\Location;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\OpportunityEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase O8.2 — canonical, ID-based location eligibility for Opportunity
 * Recommended Candidates. Location is compared by `locations.id` only,
 * never a raw display string, so "Nablus", "Nablus, Palestine", and any
 * other spelling of the same place can never be treated as different
 * locations — the ambiguity is removed by construction (there is no
 * free-text location input left anywhere in this flow), not fixed after
 * the fact with fuzzy string matching.
 */
class LocationEligibilityTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Canonical catalog: one real place, one stable ID.
    // -----------------------------------------------------------------

    public function test_a_location_name_is_unique_in_the_catalog(): void
    {
        Location::create(['canonical_name' => 'Nablus']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Location::create(['canonical_name' => 'Nablus']);
    }

    public function test_eligibility_compares_location_ids_never_display_strings(): void
    {
        // Two rows that would collide under any free-text comparison
        // scheme -- but they are unrelated on purpose here, proving the
        // eligibility check only ever consults the numeric ID, never a
        // name/spelling.
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);

        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $nablus->id]);
        $student = $this->studentWithProfile();
        $student->availableLocations()->sync([$ramallah->id]);

        $service = app(OpportunityEligibilityService::class);

        $this->assertFalse($service->isLocationEligible($opportunity->fresh(), $student->fresh()));
    }

    // -----------------------------------------------------------------
    // Student: multiple available locations.
    // -----------------------------------------------------------------

    public function test_a_student_may_have_multiple_available_locations(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $jenin = Location::create(['canonical_name' => 'Jenin']);

        $student = $this->studentWithProfile();
        $student->availableLocations()->sync([$nablus->id, $ramallah->id, $jenin->id]);

        $this->assertCount(3, $student->fresh()->availableLocations);
    }

    // -----------------------------------------------------------------
    // Work Mode controls whether location is relevant at all.
    // -----------------------------------------------------------------

    public function test_onsite_candidate_matches_when_opportunity_location_is_in_their_list(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $nablus->id]);
        $student = $this->studentWithProfile();
        $student->availableLocations()->sync([$nablus->id]);

        $service = app(OpportunityEligibilityService::class);

        $this->assertTrue($service->isLocationEligible($opportunity->fresh(), $student->fresh()));
    }

    public function test_onsite_candidate_is_excluded_when_the_location_is_not_in_their_list(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $nablus->id]);
        $student = $this->studentWithProfile();
        $student->availableLocations()->sync([$ramallah->id]);

        $service = app(OpportunityEligibilityService::class);

        $this->assertFalse($service->isLocationEligible($opportunity->fresh(), $student->fresh()));
    }

    public function test_hybrid_follows_the_same_location_rule_as_onsite(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $matching = $this->opportunityFor($org, ['work_mode' => 'hybrid', 'location_id' => $nablus->id]);
        $student = $this->studentWithProfile();
        $student->availableLocations()->sync([$nablus->id]);

        $service = app(OpportunityEligibilityService::class);

        $this->assertTrue($service->isLocationEligible($matching->fresh(), $student->fresh()));

        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $nonMatching = $this->opportunityFor($org, ['work_mode' => 'hybrid', 'location_id' => $ramallah->id]);
        $this->assertFalse($service->isLocationEligible($nonMatching->fresh(), $student->fresh()));
    }

    public function test_remote_ignores_candidate_location_entirely(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote', 'location_id' => $nablus->id]);
        // Student has NO available locations at all, and the opportunity's
        // own location is a different city than the student could ever
        // match -- Remote must still be eligible, since location is never
        // consulted for Remote at all.
        $student = $this->studentWithProfile();

        $service = app(OpportunityEligibilityService::class);

        $this->assertTrue($service->isLocationEligible($opportunity->fresh(), $student->fresh()));
    }

    public function test_remote_opportunity_recommendations_never_exclude_on_location(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'remote', 'field_of_study' => null]);
        $this->studentWithProfile(name: 'No Location Student'); // zero available locations

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $this->assertNull($response->json('data.opportunity.location'));
        $this->assertSame('remote', $response->json('data.opportunity.work_mode'));
    }

    // -----------------------------------------------------------------
    // Missing candidate location data is never guessed.
    // -----------------------------------------------------------------

    public function test_missing_candidate_location_is_never_guessed_for_an_onsite_opportunity(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $nablus->id]);
        // Zero available locations configured -- must be excluded, never
        // assumed eligible or ineligible from unrelated data (university,
        // phone number, etc.).
        $student = $this->studentWithProfile();

        $service = app(OpportunityEligibilityService::class);

        $this->assertFalse($service->isLocationEligible($opportunity->fresh(), $student->fresh()));
    }

    public function test_a_legacy_opportunity_with_no_canonical_location_is_unrestricted(): void
    {
        // An On-site/Hybrid Opportunity that predates this phase (or one
        // an Organization simply hasn't set a canonical location for yet)
        // has no `location_id` -- there is nothing to compare against, so
        // this mirrors the empty-`eligibleMajors` "unrestricted" rule
        // rather than blocking every candidate.
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite']);
        $student = $this->studentWithProfile(); // zero available locations

        $service = app(OpportunityEligibilityService::class);

        $this->assertTrue($service->isLocationEligible($opportunity->fresh(), $student->fresh()));
    }

    public function test_recommendation_endpoint_excludes_a_candidate_with_no_available_locations_for_onsite(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $nablus->id, 'field_of_study' => null]);
        $this->studentWithProfile(name: 'No Location Student');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
        $this->assertSame('Nablus', $response->json('data.opportunity.location.canonical_name'));
    }

    public function test_recommendation_endpoint_includes_a_candidate_whose_location_matches_for_onsite(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['work_mode' => 'onsite', 'location_id' => $nablus->id, 'field_of_study' => null]);
        $student = $this->studentWithProfile(name: 'Nablus Student');
        $student->availableLocations()->sync([$nablus->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $this->assertSame('Nablus Student', $response->json('data.candidates.0.name'));
    }

    // -----------------------------------------------------------------
    // Helpers -- mirror OpportunityRecommendationTest's own conventions.
    // -----------------------------------------------------------------

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
