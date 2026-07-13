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
                'education_match_score',
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

    private function studentWithProfile(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

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
