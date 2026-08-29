<?php

namespace Tests\Feature\Organization;

use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recommendation Accuracy Patch, section 12 -- Opportunity Details must be
 * able to show the exact requirements Recommended Candidates is actually
 * evaluated against: eligible majors and required skills, both already
 * computed elsewhere (`OpportunityEligibilityService`, `MatchingService`)
 * and never duplicated here -- this only confirms `show()` genuinely
 * returns them, and that `field_of_study` stays a separate, independent
 * field rather than being folded into eligible majors.
 */
class OpportunityRequirementVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_eligible_majors(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Civil Engineering',
            'normalized_major_name' => 'civil engineering',
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200);
        $this->assertSame(['Civil Engineering'], $response->json('data.eligible_majors'));
    }

    public function test_show_returns_required_skills_via_opportunity_skills(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $revit = Skill::create(['name' => 'Revit']);
        $opportunity->opportunitySkills()->create(['skill_id' => $revit->id, 'is_required' => true]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200);
        $this->assertSame('Revit', $response->json('data.opportunity_skills.0.skill.name'));
        $this->assertTrue($response->json('data.opportunity_skills.0.is_required'));
    }

    public function test_field_of_study_is_independent_of_eligible_majors(): void
    {
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create(
            $this->validOpportunityPayload(['field_of_study' => 'Engineering'])
        );
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => 'Civil Engineering',
            'normalized_major_name' => 'civil engineering',
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}");

        $response->assertStatus(200);
        $this->assertSame('Engineering', $response->json('data.field_of_study'));
        $this->assertSame(['Civil Engineering'], $response->json('data.eligible_majors'));
    }

    public function test_show_returns_the_canonical_location_name_via_the_legacy_location_field(): void
    {
        // mirrorLocationName() only runs on the real store()/update() path
        // -- created through the actual POST endpoint (not a direct
        // Eloquent create()) so that mirroring genuinely happens, exactly
        // as it would for a real Organization.
        $location = \App\Models\Location::create(['canonical_name' => 'Jenin']);
        $org = $this->organizationWithProfile();
        Sanctum::actingAs($org->user);

        $created = $this->postJson('/api/organization/opportunities', $this->validOpportunityPayload([
            'work_mode' => 'onsite',
            'location_id' => $location->id,
        ]));
        $opportunityId = $created->json('data.id');

        $response = $this->getJson("/api/organization/opportunities/{$opportunityId}");

        $response->assertStatus(200);
        $this->assertSame('Jenin', $response->json('data.location'));
        $this->assertSame('onsite', $response->json('data.work_mode'));
    }

    public function test_the_list_endpoint_also_returns_required_skills_not_just_show(): void
    {
        // Opportunity Requirements Integrity Patch -- the actual root
        // cause of Organization Opportunity Details showing "No required
        // skills configured" for an Opportunity that genuinely has
        // Required Skills: `OpportunityController::index()` never eager-
        // loaded `opportunitySkills.skill`, while `show()` already did.
        // The Flutter `OrganizationOpportunitiesProvider` reuses an
        // already-loaded row from the list when navigating to Details, so
        // whatever this endpoint omitted, Details silently omitted too.
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $revit = Skill::create(['name' => 'Revit']);
        $opportunity->opportunitySkills()->create(['skill_id' => $revit->id, 'is_required' => true]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson('/api/organization/opportunities');

        $response->assertStatus(200);
        $this->assertSame('Revit', $response->json('data.0.opportunity_skills.0.skill.name'));
    }

    public function test_opportunity_details_and_recommendation_endpoint_report_the_same_required_skill_count(): void
    {
        // Recommendation Accuracy Patch section 10 / Opportunity
        // Requirements Integrity Patch section 10 -- both endpoints must
        // read from the exact same `opportunitySkills` relation, never two
        // different data sources.
        $org = $this->organizationWithProfile();
        $opportunity = $org->profile->opportunities()->create($this->validOpportunityPayload());
        $quantitySurveying = Skill::create(['name' => 'Quantity Surveying']);
        $civil3d = Skill::create(['name' => 'Civil 3D']);
        $opportunity->opportunitySkills()->create(['skill_id' => $quantitySurveying->id, 'is_required' => true]);
        $opportunity->opportunitySkills()->create(['skill_id' => $civil3d->id, 'is_required' => true]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $studentProfile = \App\Models\StudentProfile::create(['user_id' => $student->id]);
        $studentProfile->studentSkills()->create(['skill_id' => $quantitySurveying->id, 'level' => 'intermediate']);

        Sanctum::actingAs($org->user);
        $detailsResponse = $this->getJson("/api/organization/opportunities/{$opportunity->id}");
        $recommendationResponse = $this->getJson(
            "/api/organization/opportunities/{$opportunity->id}/recommended-candidates"
        );

        $detailsSkillNames = collect($detailsResponse->json('data.opportunity_skills'))
            ->pluck('skill.name')
            ->sort()
            ->values()
            ->all();

        $recommendationResponse->assertStatus(200);
        $this->assertSame(['Civil 3D', 'Quantity Surveying'], $detailsSkillNames);
        $this->assertSame(2, $recommendationResponse->json('data.candidates.0.match_breakdown.required_skills_total'));
        $this->assertSame(1, $recommendationResponse->json('data.candidates.0.match_breakdown.required_skills_matched'));
        $this->assertSame(['Civil 3D'], $recommendationResponse->json('data.candidates.0.match_breakdown.missing_required_skills'));
    }

    private function organizationWithProfile(string $approvalStatus = 'approved'): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);

        $profile->approval_status = $approvalStatus;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function validOpportunityPayload(array $overrides = []): array
    {
        $skillId = Skill::firstOrCreate(['name' => 'PHP'])->id;

        return array_merge([
            'title' => 'Software Engineer',
            'description' => 'A great opportunity.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            // Opportunity Requirements Integrity Patch: required to
            // create through the real endpoint (only
            // test_show_returns_the_canonical_location_name... actually
            // uses postJson(); every other test here uses a direct
            // Eloquent create(), which silently ignores these two
            // pseudo-fields since they aren't `$fillable`).
            'eligible_majors' => ['Computer Science'],
            'skills' => [['skill_id' => $skillId, 'is_required' => true]],
        ], $overrides);
    }
}
