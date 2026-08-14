<?php

namespace Tests\Feature\AI;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
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
                'field_match_score',
                'experience_match_score',
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
    // Phase 8A-2: v1.1 formula (Skills 60 / Field 20 / Experience 20,
    // proportional redistribution when a factor is unavailable, no
    // fake-neutral placeholders).
    // -----------------------------------------------------------------

    // A. Perfect match on all three factors -> overall 100.
    public function test_perfect_match_on_all_factors_scores_100(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer Science', 'experience_level' => 'no_experience']);
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile(['major' => 'Computer Science']);
        $this->addStudentSkill($student, $skill);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(100.0, (float) $response->json('data.overall_match_score'));
    }

    // B. Zero match on all three (scoreable) factors -> overall 0.
    public function test_zero_match_on_all_factors_scores_0(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Civil Engineering', 'experience_level' => 'senior']);
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile(['major' => 'Fine Arts']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(0.0, (float) $response->json('data.overall_match_score'));
    }

    // C/M. Field unavailable (no major) -- weight redistributes to skills
    // (60) + experience (20) = 80 total, verified with exact arithmetic.
    public function test_missing_major_redistributes_field_weight_to_skills_and_experience(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer Science', 'experience_level' => 'junior']);
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile(['major' => null]);
        $this->addStudentSkill($student, $skill, years: 0.5);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        // skills = 100 (matched required skill), field unavailable,
        // experience = min(0.5/1, 1) * 100 = 50.
        // overall = (100*60 + 50*20) / 80 = 87.5
        $this->assertNull($response->json('data.field_match_score'));
        $this->assertEquals(87.5, (float) $response->json('data.overall_match_score'));
    }

    // Missing opportunity.field_of_study also makes the field factor
    // unavailable, not just a missing student major.
    public function test_missing_field_of_study_makes_field_factor_unavailable(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => null, 'experience_level' => 'no_experience']);

        $student = $this->studentWithProfile(['major' => 'Computer Science']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertNull($response->json('data.field_match_score'));
    }

    // D/E. Exact match after normalization (case + whitespace collapsing).
    public function test_field_match_is_normalized_for_case_and_whitespace(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer   Science', 'experience_level' => 'no_experience']);
        $student = $this->studentWithProfile(['major' => '  computer science  ']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(100.0, (float) $response->json('data.field_match_score'));
    }

    // F. Meaningful "contains" match at a whole-word boundary.
    public function test_field_match_accepts_a_whole_segment_contains_match(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Civil Engineering and Construction', 'experience_level' => 'no_experience']);
        $student = $this->studentWithProfile(['major' => 'Civil Engineering']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(100.0, (float) $response->json('data.field_match_score'));
    }

    // G. Substring false-positive guard: "Art" must NOT match inside
    // "Part-time Arts Program".
    public function test_field_match_rejects_a_trivial_substring_false_positive(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Part-time Arts Program', 'experience_level' => 'no_experience']);
        $student = $this->studentWithProfile(['major' => 'Art']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(0.0, (float) $response->json('data.field_match_score'));
    }

    // H. Opportunity with zero skills defined at all -> skills unavailable
    // (not the old fake 100), weight redistributes to field + experience.
    public function test_no_opportunity_skills_makes_skills_factor_unavailable_not_a_fake_100(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer Science', 'experience_level' => 'no_experience']);
        $student = $this->studentWithProfile(['major' => 'Fine Arts']);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        // skills unavailable (null), field = 0 (no match), experience = 100
        // (no_experience). overall = (0*20 + 100*20) / 40 = 50.
        $this->assertNull($response->json('data.skills_match_score'));
        $this->assertEquals(50.0, (float) $response->json('data.overall_match_score'));
    }

    // I. `no_experience` requires 0 years -> automatically 100 regardless
    // of the student's own recorded experience.
    public function test_no_experience_level_always_scores_100_on_experience(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['experience_level' => 'no_experience']);
        $student = $this->studentWithProfile();
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(100.0, (float) $response->json('data.experience_match_score'));
    }

    // J. Experience is proportional to the max years_of_experience across
    // the student's skills, capped at 100.
    public function test_experience_score_is_proportional_to_max_years_of_experience(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['experience_level' => 'mid']); // requires 3 years
        $skillA = $this->createSkill('Laravel');
        $skillB = $this->createSkill('Docker');
        $student = $this->studentWithProfile();
        $this->addStudentSkill($student, $skillA, years: 1.5);
        $this->addStudentSkill($student, $skillB, years: 6.0); // max wins, capped at 100
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertEquals(100.0, (float) $response->json('data.experience_match_score'));
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

    // M (arithmetic proof). Explicit weighted-average check across all
    // three scoreable factors together.
    public function test_overall_score_is_the_exact_weighted_average_of_scoreable_factors(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer Science', 'experience_level' => 'mid']); // 3 years
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile(['major' => 'Fine Arts']); // field mismatch -> 0
        $this->addStudentSkill($student, $skill); // skills match -> 100
        // no years_of_experience recorded -> experience = 0
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        // overall = (100*60 + 0*20 + 0*20) / 100 = 60.0
        $this->assertEquals(60.0, (float) $response->json('data.overall_match_score'));
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
}
