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

class AnalysisDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_or_missing_application_id_returns_correct_not_found_response(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/applications/999999/analyze');

        $response->assertStatus(404);
    }

    /**
     * ApplicationAnalysisController@analyze only checks organization ownership —
     * it does not restrict on the opportunity's or application's own status, so
     * closed opportunities and decided (e.g. rejected) applications can still
     * be analyzed.
     */
    public function test_closed_or_historical_applications_can_still_be_analyzed(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['status' => 'closed']);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        $application->status = 'rejected';
        $application->save();

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_analysis_does_not_change_application_status(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = $this->applicationFor($opportunity, $this->studentWithProfile());

        $this->assertSame('pending', $application->fresh()->status);

        Sanctum::actingAs($org->user);

        $this->postJson("/api/organization/applications/{$application->id}/analyze")->assertStatus(200);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'status' => 'pending',
        ]);
    }

    public function test_analysis_does_not_modify_student_profile_cv_opportunity_or_skills(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile();
        $this->addStudentSkill($student, $skill, 2.5);
        $application = $this->applicationFor($opportunity, $student);

        $studentProfileBefore = $student->profile->fresh()->toArray();
        $cvBefore = $student->cv->fresh()->toArray();
        $opportunityBefore = $opportunity->fresh()->toArray();
        $studentSkillCountBefore = $student->profile->studentSkills()->count();
        $opportunitySkillCountBefore = $opportunity->opportunitySkills()->count();

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/analyze")->assertStatus(200);

        $this->assertEquals($studentProfileBefore, $student->profile->fresh()->toArray());
        $this->assertEquals($cvBefore, $student->cv->fresh()->toArray());
        $this->assertEquals($opportunityBefore, $opportunity->fresh()->toArray());
        $this->assertSame($studentSkillCountBefore, $student->profile->studentSkills()->count());
        $this->assertSame($opportunitySkillCountBefore, $opportunity->opportunitySkills()->count());
    }

    public function test_analysis_result_is_deterministic_for_the_same_input(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $skill = $this->createSkill('Laravel');
        $this->addOpportunitySkill($opportunity, $skill, true);

        $student = $this->studentWithProfile();
        $this->addStudentSkill($student, $skill, 2.5);
        $application = $this->applicationFor($opportunity, $student);

        Sanctum::actingAs($org->user);

        $first = $this->postJson("/api/organization/applications/{$application->id}/analyze");
        $second = $this->postJson("/api/organization/applications/{$application->id}/analyze");

        $this->assertSame(
            $first->json('data.overall_match_score'),
            $second->json('data.overall_match_score')
        );
        $this->assertSame(
            $first->json('data.skills_match_score'),
            $second->json('data.skills_match_score')
        );
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
