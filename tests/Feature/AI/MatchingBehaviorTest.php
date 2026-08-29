<?php

namespace Tests\Feature\AI;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\MajorNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MatchingBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_returns_success_and_a_numeric_match_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['overall_match_score']]);

        $this->assertIsNumeric($response->json('data.overall_match_score'));
    }

    public function test_match_score_is_between_0_and_100(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");
        $score = (float) $response->json('data.overall_match_score');

        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function test_analysis_stores_match_score_on_the_application(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");
        $score = (float) $response->json('data.overall_match_score');

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'match_score' => $score,
        ]);
    }

    public function test_reanalyzing_updates_the_existing_score_instead_of_creating_duplicate_data(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);

        $this->postJson("/api/organization/applications/{$application->id}/analyze")->assertStatus(200);

        // Add a skill match so the second analysis produces a different score.
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);
        $this->addStudentSkill($student, $skill);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");
        $newScore = (float) $response->json('data.overall_match_score');

        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'match_score' => $newScore,
        ]);
    }

    public function test_analysis_response_includes_the_expected_scoring_breakdown(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [
                'overall_match_score',
                'skills_match_score',
                'major_match_score',
                'strengths',
                'weaknesses',
                'recommendation',
            ]]);
    }

    public function test_required_skills_contribute_more_strongly_than_optional_skills(): void
    {
        // Student who matches only the REQUIRED skill (misses the preferred one).
        $orgA = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($orgA);
        $requiredSkillA = $this->createSkill('Laravel A-Req');
        $preferredSkillA = $this->createSkill('Docker A-Pref');
        $this->addOpportunitySkill($opportunityA, $requiredSkillA, true);
        $this->addOpportunitySkill($opportunityA, $preferredSkillA, false);

        $studentMatchingRequired = $this->studentWithProfile();
        $this->addStudentSkill($studentMatchingRequired, $requiredSkillA);
        $applicationA = $this->applicationFor($opportunityA, $studentMatchingRequired);

        // Student who matches only the PREFERRED skill (misses the required one).
        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $requiredSkillB = $this->createSkill('Laravel B-Req');
        $preferredSkillB = $this->createSkill('Docker B-Pref');
        $this->addOpportunitySkill($opportunityB, $requiredSkillB, true);
        $this->addOpportunitySkill($opportunityB, $preferredSkillB, false);

        $studentMatchingPreferred = $this->studentWithProfile();
        $this->addStudentSkill($studentMatchingPreferred, $preferredSkillB);
        $applicationB = $this->applicationFor($opportunityB, $studentMatchingPreferred);

        Sanctum::actingAs($orgA->user);
        $responseA = $this->postJson("/api/organization/applications/{$applicationA->id}/analyze");

        Sanctum::actingAs($orgB->user);
        $responseB = $this->postJson("/api/organization/applications/{$applicationB->id}/analyze");

        $scoreMatchingRequired = (float) $responseA->json('data.skills_match_score');
        $scoreMatchingPreferred = (float) $responseB->json('data.skills_match_score');

        $this->assertGreaterThan($scoreMatchingPreferred, $scoreMatchingRequired);
    }

    public function test_a_student_with_matching_skills_receives_a_higher_score_than_a_student_with_no_matching_skills(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunityA = $this->opportunityFor($orgA);
        $skillA = $this->createSkill('Laravel Matching');
        $this->addOpportunitySkill($opportunityA, $skillA, true);

        $matchingStudent = $this->studentWithProfile();
        $this->addStudentSkill($matchingStudent, $skillA);
        $applicationA = $this->applicationFor($opportunityA, $matchingStudent);

        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $skillB = $this->createSkill('Laravel NonMatching');
        $this->addOpportunitySkill($opportunityB, $skillB, true);

        $nonMatchingStudent = $this->studentWithProfile();
        $applicationB = $this->applicationFor($opportunityB, $nonMatchingStudent);

        Sanctum::actingAs($orgA->user);
        $responseA = $this->postJson("/api/organization/applications/{$applicationA->id}/analyze");

        Sanctum::actingAs($orgB->user);
        $responseB = $this->postJson("/api/organization/applications/{$applicationB->id}/analyze");

        $this->assertGreaterThan(
            (float) $responseB->json('data.overall_match_score'),
            (float) $responseA->json('data.overall_match_score')
        );
    }

    public function test_missing_optional_profile_data_does_not_crash_analysis(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfile();
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_analysis_works_when_student_has_no_skills_returning_a_valid_low_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile();
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // PHP's json_encode(0.0) emits "0", not "0.0" — a decoded JSON value
        // can't distinguish int 0 from float 0.0, so compare numerically
        // rather than with a strict type-sensitive assertion.
        $this->assertEquals(0, (float) $response->json('data.skills_match_score'));
    }

    // -----------------------------------------------------------------
    // v2.0, "Candidate Opportunity Preferences + Final Recommendation
    // Match Formula": Remote = Skills 70 / Major 30; On-site/Hybrid =
    // Skills 60 / Major 25 / Location 15. Experience is completely
    // removed (see `MatchingService`'s own doc comment). Proportional
    // redistribution when a factor is unavailable, no fake-neutral
    // placeholders. `opportunityFor()` defaults to `work_mode: remote`,
    // so tests below are Skills 70/Major 30 unless stated otherwise.
    // -----------------------------------------------------------------

    // A. Perfect match on both remaining factors -> overall 100.
    public function test_perfect_match_on_all_factors_scores_100(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->addEligibleMajor($opportunity, 'Computer Science');
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile(['major' => 'Computer Science']);
        $this->addStudentSkill($student, $skill);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(100.0, (float) $response->json('data.overall_match_score'));
    }

    // B. Zero match on both scoreable factors -> overall 0.
    public function test_zero_match_on_all_factors_scores_0(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->addEligibleMajor($opportunity, 'Computer Science');
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile(['major' => 'Fine Arts']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(0.0, (float) $response->json('data.overall_match_score'));
    }

    // Opportunity Academic Matching Cleanup: `field_match_score` no longer
    // exists in the response at all -- confirmed absent regardless of
    // what `major`/`field_of_study` are set to.
    public function test_response_never_contains_a_field_match_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'ccc']);
        $student = $this->studentWithProfile(['major' => 'Civil Engineering']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertJsonMissingPath('data.field_match_score');
    }

    // Opportunity Academic Matching Cleanup: legacy `field_of_study` free
    // text (however unrelated to the student's real major) can never
    // change `overall_match_score` -- it is no longer read by
    // MatchingService at all.
    public function test_field_of_study_never_changes_the_overall_score(): void
    {
        $org = $this->approvedOrganization();
        $skill = $this->createSkill('Laravel');
        $student = $this->studentWithProfile(['major' => 'Civil Engineering']);
        $this->addStudentSkill($student, $skill, years: 2);

        $opportunityWithUnrelatedField = $this->opportunityFor($org, ['field_of_study' => 'ccc']);
        $this->addEligibleMajor($opportunityWithUnrelatedField, 'Civil Engineering');
        $this->addOpportunitySkill($opportunityWithUnrelatedField, $skill, true);
        $applicationA = $this->applicationFor($opportunityWithUnrelatedField, $student);

        $opportunityWithNoField = $this->opportunityFor($org, ['field_of_study' => null]);
        $this->addEligibleMajor($opportunityWithNoField, 'Civil Engineering');
        $this->addOpportunitySkill($opportunityWithNoField, $skill, true);
        $applicationB = $this->applicationFor($opportunityWithNoField, $student);

        Sanctum::actingAs($org->user);
        $responseA = $this->postJson("/api/organization/applications/{$applicationA->id}/analyze");
        $responseB = $this->postJson("/api/organization/applications/{$applicationB->id}/analyze");

        $this->assertEquals(
            $responseA->json('data.overall_match_score'),
            $responseB->json('data.overall_match_score'),
        );
    }

    // H. Opportunity with zero skills defined at all -> skills unavailable
    // (not a fake 100), weight redistributes entirely to Major (the only
    // other, and now only remaining, factor on a Remote Opportunity).
    public function test_no_opportunity_skills_makes_skills_factor_unavailable_not_a_fake_100(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->addEligibleMajor($opportunity, 'Fine Arts');
        $student = $this->studentWithProfile(['major' => 'Fine Arts']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        // skills unavailable (null), major = 100 (matched).
        // overall = (100*30) / 30 = 100 -- the only scoreable factor.
        $this->assertNull($response->json('data.skills_match_score'));
        $this->assertEquals(100.0, (float) $response->json('data.overall_match_score'));
    }

    // I. Experience has zero influence -- `experience_level` is left
    // completely unread by `MatchingService`. Two Opportunities differing
    // only in `experience_level` must score identically.
    public function test_experience_level_has_zero_influence_on_the_score(): void
    {
        $org = $this->approvedOrganization();
        $skill = $this->createSkill('Laravel');
        $student = $this->studentWithProfile(['major' => 'Civil Engineering']);
        // Deliberately no years_of_experience recorded at all -- if
        // Experience still influenced the score, a `senior`-level
        // Opportunity would score this student worse than a
        // `no_experience` one.
        $this->addStudentSkill($student, $skill);

        $opportunityNoExperience = $this->opportunityFor($org, ['experience_level' => 'no_experience']);
        $this->addEligibleMajor($opportunityNoExperience, 'Civil Engineering');
        $this->addOpportunitySkill($opportunityNoExperience, $skill, true);
        $applicationA = $this->applicationFor($opportunityNoExperience, $student);

        $opportunitySenior = $this->opportunityFor($org, ['experience_level' => 'senior']);
        $this->addEligibleMajor($opportunitySenior, 'Civil Engineering');
        $this->addOpportunitySkill($opportunitySenior, $skill, true);
        $applicationB = $this->applicationFor($opportunitySenior, $student);

        Sanctum::actingAs($org->user);
        $responseA = $this->postJson("/api/organization/applications/{$applicationA->id}/analyze");
        $responseB = $this->postJson("/api/organization/applications/{$applicationB->id}/analyze");

        $this->assertEquals(
            $responseA->json('data.overall_match_score'),
            $responseB->json('data.overall_match_score'),
        );
        $this->assertEquals(100.0, (float) $responseA->json('data.overall_match_score'));
    }

    // I2. Experience never appears as a failed Match factor -- there is no
    // `experience_match_score`/`experience_match` key in the response at
    // all any more.
    public function test_response_never_contains_an_experience_match_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['experience_level' => 'senior']);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertJsonMissingPath('data.experience_match_score');
        $response->assertJsonMissingPath('data.experience_match');
    }

    // K. No education placeholder anywhere in the response.
    public function test_response_never_contains_an_education_match_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertJsonMissingPath('data.education_match_score');
    }

    // L. Required-skill weighting still applies under the new formula
    // (already covered relatively by test_required_skills_contribute_
    // more_strongly_than_optional_skills; this asserts the exact ratio).
    public function test_required_skill_weighs_double_a_preferred_skill_in_the_skills_score(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $required = $this->createSkill('Required Skill');
        $preferred = $this->createSkill('Preferred Skill');
        $this->addOpportunitySkill($opportunity, $required, true);
        $this->addOpportunitySkill($opportunity, $preferred, false);

        $student = $this->studentWithProfile();
        $this->addStudentSkill($student, $required);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        // totalWeight = 2*1 + 1 = 3, matchedWeight = 2 -> 66.67.
        $this->assertEquals(66.67, (float) $response->json('data.skills_match_score'));
    }

    // M (arithmetic proof). Explicit weighted-average check across both
    // remaining scoreable factors together, on a Remote Opportunity
    // (Skills 70 / Major 30).
    public function test_overall_score_is_the_exact_weighted_average_of_scoreable_factors(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $this->addEligibleMajor($opportunity, 'Computer Science'); // student's major won't match
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile(['major' => 'Fine Arts']);
        $this->addStudentSkill($student, $skill); // skills match -> 100
        // major does not match the restricted list -> major = 0
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        // overall = (100*70 + 0*30) / 100 = 70.0
        $this->assertEquals(70.0, (float) $response->json('data.overall_match_score'));
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

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

    private function studentWithProfile(array $overrides = []): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(array_merge(['user_id' => $user->id], $overrides));

        $cv = $profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function applicationFor(Opportunity $opportunity, object $student): Application
    {
        return Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
    }

    private function createSkill(string $name): Skill
    {
        return Skill::create(['name' => $name]);
    }

    private function addOpportunitySkill(Opportunity $opportunity, Skill $skill, bool $isRequired): void
    {
        $opportunity->opportunitySkills()->create([
            'skill_id' => $skill->id,
            'is_required' => $isRequired,
        ]);
    }

    private function addStudentSkill(object $student, Skill $skill, ?float $years = null): void
    {
        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'years_of_experience' => $years,
        ]);
    }

    private function addEligibleMajor(Opportunity $opportunity, string $major): void
    {
        $opportunity->eligibleMajorRecords()->create([
            'major_name' => $major,
            'normalized_major_name' => MajorNormalizer::normalize($major),
        ]);
    }
}
