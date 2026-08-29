<?php

namespace Tests\Feature\Candidates;

use App\Models\Application;
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
 * Matching Formula Audit -- confirms Recommended Candidates and
 * Application Match Analysis reuse the exact same `MatchingService` core
 * (v2.0, "Candidate Opportunity Preferences + Final Recommendation Match
 * Formula": Remote = Skills 70 / Major 30; On-site/Hybrid = Skills 60 /
 * Major 25 / Location 15 -- proportional redistribution, genuine 0.0
 * fallback), and that every `match_breakdown` field is a truthful label
 * over real, already-computed values, never a fabricated explanation or a
 * second scoring path. `opportunity.field_of_study` and
 * `opportunity.experience_level` are never read by any factor here.
 */
class MatchingFormulaAuditTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // The manual case, section 19 of the patch spec -- Remote.
    // -----------------------------------------------------------------

    public function test_allam_case_major_matches_skill_missing_remote_receives_the_real_academic_factor_contribution(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'remote',
            'field_of_study' => null,
        ]);
        $bim = Skill::create(['name' => 'BIM']);
        $opportunity->opportunitySkills()->create(['skill_id' => $bim->id, 'is_required' => true]);

        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Allam');
        // No student skills at all -- BIM is missing.

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $row = $response->json('data.candidates.0');

        // Allam remains eligible (the gate passed) ...
        $this->assertSame('eligible', $row['match_breakdown']['major_eligibility']);
        // ... and that same canonical eligibleMajorRecords comparison is
        // ALSO a genuine, real 30%-weighted scoring factor on this Remote
        // Opportunity -- 'academic_match' => 'matched', major_match_score
        // => 100.0. Skills (0 of 1 required matched) contributes 0.
        // overall = (0*70 + 100*30) / 100 = 30.0 -- the Match percentage
        // genuinely reflects that Major is part of total compatibility,
        // never a forced 0% next to a contradicting "Major matches"
        // fact, and Location is not considered at all on Remote.
        $this->assertEquals(30.0, $row['match_score']);
        $this->assertEquals(0.0, $row['skills_match_score']);
        $this->assertEquals(100.0, $row['major_match_score']);
        $this->assertSame('matched', $row['match_breakdown']['academic_match']);
        $this->assertEquals(100.0, $row['match_breakdown']['major_match_score']);
        $this->assertEquals(30, $row['match_breakdown']['major_weight']);
        $this->assertEquals(30.0, $row['match_breakdown']['major_contribution']);
        $this->assertEquals(70, $row['match_breakdown']['skills_weight']);
        $this->assertEquals(0.0, $row['match_breakdown']['skills_contribution']);
        $this->assertArrayNotHasKey('experience_match_score', $row);
        $this->assertArrayNotHasKey('experience_match', $row['match_breakdown']);
        $this->assertArrayNotHasKey('field_match_score', $row);
        $this->assertArrayNotHasKey('major_field_match', $row['match_breakdown']);
        $this->assertSame(['BIM'], $row['match_breakdown']['missing_required_skills']);
        $this->assertSame('not_considered', $row['match_breakdown']['location_eligibility']);
        $this->assertNull($row['match_breakdown']['location_match_score']);
        $this->assertNull($row['match_breakdown']['location_weight']);
        $this->assertNull($row['match_breakdown']['location_contribution']);
    }

    public function test_a_second_candidate_with_the_skill_present_scores_higher_than_allam(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'remote',
            'field_of_study' => null,
        ]);
        $bim = Skill::create(['name' => 'BIM']);
        $opportunity->opportunitySkills()->create(['skill_id' => $bim->id, 'is_required' => true]);

        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Allam');
        $omar = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $omar->studentSkills()->create(['skill_id' => $bim->id, 'level' => 'intermediate']);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(2, 'data.candidates');
        $byName = collect($response->json('data.candidates'))->keyBy('name');

        $this->assertGreaterThan($byName['Allam']['match_score'], $byName['Omar']['match_score']);
        $this->assertEquals(100.0, $byName['Omar']['skills_match_score']);
    }

    // -----------------------------------------------------------------
    // Recommendation and Application Match share one formula.
    // -----------------------------------------------------------------

    public function test_recommendation_score_and_application_match_score_are_identical_for_equivalent_inputs(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'remote',
            'field_of_study' => 'Civil Engineering',
        ]);
        $autoCad = Skill::create(['name' => 'AutoCAD']);
        $bim = Skill::create(['name' => 'BIM']);
        $opportunity->opportunitySkills()->create(['skill_id' => $autoCad->id, 'is_required' => true]);
        $opportunity->opportunitySkills()->create(['skill_id' => $bim->id, 'is_required' => true]);

        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->studentSkills()->create([
            'skill_id' => $autoCad->id,
            'level' => 'intermediate',
            'years_of_experience' => 2,
        ]);

        Sanctum::actingAs($org->user);
        $recommendationResponse = $this->getJson(
            "/api/organization/opportunities/{$opportunity->id}/recommended-candidates"
        );
        $recommendationScore = $recommendationResponse->json('data.candidates.0.match_score');

        $cv = $student->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/my-cv.pdf']);
        $application = Application::create([
            'student_id' => $student->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);
        $analyzeResponse = $this->postJson("/api/organization/applications/{$application->id}/analyze");
        $applicationScore = $analyzeResponse->json('data.overall_match_score');

        // Same Student, same Opportunity, same real inputs -- both call
        // paths funnel through MatchingService::score() and must agree
        // exactly. Same is true for the individual factor scores.
        $this->assertSame($recommendationScore, $applicationScore);
        $this->assertSame(
            $recommendationResponse->json('data.candidates.0.major_match_score'),
            $analyzeResponse->json('data.major_match_score'),
        );
        $this->assertSame(
            $recommendationResponse->json('data.candidates.0.skills_match_score'),
            $analyzeResponse->json('data.skills_match_score'),
        );
    }

    // -----------------------------------------------------------------
    // field_of_study has zero influence -- match_breakdown's labels are
    // truthful, not a second algorithm.
    // -----------------------------------------------------------------

    public function test_omar_case_eligible_major_matches_canonical_eligible_majors_regardless_of_unrelated_field_of_study(): void
    {
        // The exact reported bug: candidate major = "Civil Engineering",
        // Opportunity Eligible Majors includes "Civil Engineering", but
        // the legacy free-text field_of_study is unrelated ("ccc"). Omar
        // must appear, and the response must never mention field_of_study.
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'field_of_study' => 'ccc',
        ]);
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $this->assertSame('eligible', $response->json('data.candidates.0.match_breakdown.major_eligibility'));
        $this->assertArrayNotHasKey('field_match_score', $response->json('data.candidates.0'));
        $this->assertArrayNotHasKey('major_field_match', $response->json('data.candidates.0.match_breakdown'));
    }

    public function test_random_legacy_field_of_study_text_cannot_exclude_an_eligible_candidate(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'field_of_study' => 'completely unrelated nonsense',
        ]);
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
    }

    public function test_field_of_study_never_alters_the_recommendation_score(): void
    {
        $org = $this->approvedOrganization();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->studentSkills()->create(['skill_id' => $skill->id, 'level' => 'intermediate']);

        $opportunityWithField = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'field_of_study' => 'Marketing', // deliberately unrelated
        ]);
        $opportunityWithField->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        $opportunityWithoutField = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'field_of_study' => null,
        ]);
        $opportunityWithoutField->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        Sanctum::actingAs($org->user);
        $responseA = $this->getJson("/api/organization/opportunities/{$opportunityWithField->id}/recommended-candidates");
        $responseB = $this->getJson("/api/organization/opportunities/{$opportunityWithoutField->id}/recommended-candidates");

        $this->assertEquals(
            $responseA->json('data.candidates.0.match_score'),
            $responseB->json('data.candidates.0.match_score'),
        );
        // The Major factor itself is also unaffected -- it is computed
        // purely from eligibleMajorRecords, identical on both
        // Opportunities here, never from field_of_study.
        $this->assertEquals(
            $responseA->json('data.candidates.0.major_match_score'),
            $responseB->json('data.candidates.0.major_match_score'),
        );
    }

    public function test_a_candidate_with_a_non_eligible_major_is_excluded_regardless_of_field_of_study(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'field_of_study' => 'Civil Engineering', // would have "matched" under the old formula
        ]);
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
    }

    public function test_multiple_eligible_majors_all_pass_academic_eligibility(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors(
            $org,
            ['Civil Engineering', 'Construction Engineering', 'Architecture'],
        );
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $this->studentWithProfile(['major' => 'Construction Engineering'], name: 'Layla');
        $this->studentWithProfile(['major' => 'Architecture'], name: 'Sami');
        $this->studentWithProfile(['major' => 'Fine Arts'], name: 'Excluded');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $names = collect($response->json('data.candidates'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Layla', 'Omar', 'Sami'], $names);
    }

    // -----------------------------------------------------------------
    // Experience has zero influence on Recommended Candidates too (the
    // core "never appears as a factor" proof lives in
    // MatchingBehaviorTest -- this confirms the same through the
    // Recommendation endpoint specifically).
    // -----------------------------------------------------------------

    public function test_response_never_contains_an_experience_factor(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'experience_level' => 'senior',
        ]);
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertArrayNotHasKey('experience_match_score', $response->json('data.candidates.0'));
        $this->assertArrayNotHasKey('experience_match', $response->json('data.candidates.0.match_breakdown'));
    }

    // -----------------------------------------------------------------
    // Location: On-site/Hybrid contribute exactly 15 when matched; Remote
    // is never considered at all (never a phantom 0/15).
    // -----------------------------------------------------------------

    public function test_onsite_location_contributes_exactly_15_points_when_matched(): void
    {
        $org = $this->approvedOrganization();
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'onsite',
            'location_id' => $jenin->id,
        ]);
        $skill = Skill::create(['name' => 'AutoCAD']);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->studentSkills()->create(['skill_id' => $skill->id, 'level' => 'intermediate']);
        $student->availableLocations()->sync([$jenin->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $row = $response->json('data.candidates.0');

        // Major 25/25 + Skills 60/60 + Location 15/15 = 100.
        $this->assertEquals(100.0, $row['match_score']);
        $this->assertEquals(100.0, $row['location_match_score']);
        $this->assertSame('matched', $row['match_breakdown']['location_eligibility']);
        $this->assertEquals(15, $row['match_breakdown']['location_weight']);
        $this->assertEquals(15.0, $row['match_breakdown']['location_contribution']);
        $this->assertEquals(25, $row['match_breakdown']['major_weight']);
        $this->assertEquals(60, $row['match_breakdown']['skills_weight']);
    }

    public function test_hybrid_location_contributes_exactly_15_points_when_matched(): void
    {
        $org = $this->approvedOrganization();
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'hybrid',
            'location_id' => $jenin->id,
        ]);
        $skill = Skill::create(['name' => 'AutoCAD']);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->studentSkills()->create(['skill_id' => $skill->id, 'level' => 'intermediate']);
        $student->availableLocations()->sync([$jenin->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $row = $response->json('data.candidates.0');

        $this->assertEquals(100.0, $row['match_score']);
        $this->assertEquals(15.0, $row['match_breakdown']['location_contribution']);
    }

    public function test_remote_location_contributes_nothing_never_a_phantom_zero_of_fifteen(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'remote',
        ]);
        $skill = Skill::create(['name' => 'AutoCAD']);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        // No available_location_ids at all -- if Location were consulted
        // for Remote, this candidate could be wrongly excluded/penalized.
        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->studentSkills()->create(['skill_id' => $skill->id, 'level' => 'intermediate']);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $row = $response->json('data.candidates.0');

        // Major 30/30 + Skills 70/70 = 100 -- Location plays no part.
        $this->assertEquals(100.0, $row['match_score']);
        $this->assertNull($row['location_match_score']);
        $this->assertSame('not_considered', $row['match_breakdown']['location_eligibility']);
        $this->assertNull($row['match_breakdown']['location_weight']);
        $this->assertNull($row['match_breakdown']['location_contribution']);
    }

    public function test_remote_candidate_with_no_location_data_at_all_remains_eligible(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], ['work_mode' => 'remote']);
        // No current_location_id, no available_location_ids.
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
    }

    public function test_remote_candidate_with_a_completely_different_location_remains_eligible(): void
    {
        $org = $this->approvedOrganization();
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], ['work_mode' => 'remote']);
        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        // Available only in a city with no relationship to this Remote
        // Opportunity at all -- must still be recommended.
        $student->availableLocations()->sync([$nablus->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(1, 'data.candidates');
        $this->assertEquals(
            'not_considered',
            $response->json('data.candidates.0.match_breakdown.location_eligibility'),
        );
    }

    public function test_onsite_location_mismatch_excludes_the_candidate(): void
    {
        $org = $this->approvedOrganization();
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'onsite',
            'location_id' => $jenin->id,
        ]);
        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->availableLocations()->sync([$nablus->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $response->assertStatus(200)->assertJsonCount(0, 'data.candidates');
    }

    // -----------------------------------------------------------------
    // The breakdown must reconcile with the total -- never a number that
    // "Why this match?" can't explain.
    // -----------------------------------------------------------------

    public function test_the_onsite_match_breakdown_always_reconciles_with_the_reported_total_match_score(): void
    {
        $org = $this->approvedOrganization();
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], [
            'work_mode' => 'onsite',
            'location_id' => $jenin->id,
        ]);
        $autoCad = Skill::create(['name' => 'AutoCAD']);
        $bim = Skill::create(['name' => 'BIM']);
        // Required (double weight) + preferred (single weight) mix, so
        // the Skills factor itself lands on a non-trivial fractional
        // value, not just 0/50/100.
        $opportunity->opportunitySkills()->create(['skill_id' => $autoCad->id, 'is_required' => true]);
        $opportunity->opportunitySkills()->create(['skill_id' => $bim->id, 'is_required' => false]);

        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->studentSkills()->create([
            'skill_id' => $bim->id, // only the preferred skill matched
            'level' => 'intermediate',
        ]);
        $student->availableLocations()->sync([$jenin->id]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $breakdown = $response->json('data.candidates.0.match_breakdown');

        // Independently sum the three real, already-computed
        // contributions the response itself returned -- proving
        // `match_score` is never a value the breakdown's own components
        // can't add up to (the literal "must reconcile with the final
        // percentage" requirement) -- Claude never recomputes a weighted
        // average here, only adds numbers the backend already produced.
        $this->assertNotNull($breakdown['skills_contribution']);
        $this->assertNotNull($breakdown['major_contribution']);
        $this->assertNotNull($breakdown['location_contribution']);

        $summedTotal = round(
            $breakdown['skills_contribution']
            + $breakdown['major_contribution']
            + $breakdown['location_contribution'],
            2,
        );

        $this->assertEquals($summedTotal, $response->json('data.candidates.0.match_score'));
    }

    public function test_the_remote_match_breakdown_always_reconciles_with_the_reported_total_match_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], ['work_mode' => 'remote']);
        $autoCad = Skill::create(['name' => 'AutoCAD']);
        $bim = Skill::create(['name' => 'BIM']);
        $opportunity->opportunitySkills()->create(['skill_id' => $autoCad->id, 'is_required' => true]);
        $opportunity->opportunitySkills()->create(['skill_id' => $bim->id, 'is_required' => false]);

        $student = $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');
        $student->studentSkills()->create(['skill_id' => $bim->id, 'level' => 'intermediate']);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");
        $breakdown = $response->json('data.candidates.0.match_breakdown');

        $this->assertNotNull($breakdown['skills_contribution']);
        $this->assertNotNull($breakdown['major_contribution']);
        $this->assertNull($breakdown['location_contribution']);

        $summedTotal = round($breakdown['skills_contribution'] + $breakdown['major_contribution'], 2);

        $this->assertEquals($summedTotal, $response->json('data.candidates.0.match_score'));
    }

    // -----------------------------------------------------------------
    // null vs zero, top-level and factor-level.
    // -----------------------------------------------------------------

    public function test_a_genuine_zero_overall_score_is_never_reported_as_unavailable(): void
    {
        $org = $this->approvedOrganization();
        // Deliberately unrestricted (no eligibleMajorRecords at all) --
        // Major is genuinely unavailable here (nothing configured to
        // compare against, rule B), not zero, so it is excluded from the
        // weighted average entirely rather than dragging the real Skills
        // zero toward a nonzero value.
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null]);
        $bim = Skill::create(['name' => 'BIM']);
        $opportunity->opportunitySkills()->create(['skill_id' => $bim->id, 'is_required' => true]);
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        // Skills=0 (weight 70) is the only scoreable factor -- overall
        // must be the real 0.0 -- never null, and never silently coerced
        // from a "could not calculate" state.
        $this->assertEquals(0.0, $response->json('data.candidates.0.match_score'));
        $this->assertNotNull($response->json('data.candidates.0.match_score'));
        $this->assertNull($response->json('data.candidates.0.major_match_score'));
        $this->assertSame(
            'not_applicable',
            $response->json('data.candidates.0.match_breakdown.academic_match'),
        );
    }

    // -----------------------------------------------------------------
    // Structural guarantees: no fake Application, no mutation.
    // -----------------------------------------------------------------

    public function test_recommendation_never_creates_an_application_even_when_scoring_many_candidates(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityWithMajors($org, ['Civil Engineering'], ['field_of_study' => null]);
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Allam');
        $this->studentWithProfile(['major' => 'Civil Engineering'], name: 'Omar');

        Sanctum::actingAs($org->user);
        $this->getJson("/api/organization/opportunities/{$opportunity->id}/recommended-candidates");

        $this->assertDatabaseCount('applications', 0);
    }

    private function opportunityWithMajors(object $org, array $majors, array $overrides = []): Opportunity
    {
        $opportunity = $this->opportunityFor($org, $overrides);

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
