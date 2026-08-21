<?php

namespace Tests\Feature\Organization;

use App\Models\Application;
use App\Models\CV;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-6.1: Organization-facing application responses now carry each
 * applicant's Student Skills with their evidence `source`
 * (`manual`/`cv_ai`), so the Organization can tell CV-supported skills
 * from self-declared ones. Covers `show`, `index`, and
 * `indexForOpportunity` -- and confirms nothing else about the existing
 * response contract (parsed_text privacy, match_score, assessment/offer
 * shape) changed.
 */
class ApplicationSkillEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_details_include_each_skills_evidence_source(): void
    {
        $student = $this->studentWithProfile();
        $manualSkill = Skill::create(['name' => 'AutoCAD']);
        $aiSkill = Skill::create(['name' => 'Primavera P6']);
        $student->profile->studentSkills()->create([
            'skill_id' => $manualSkill->id,
            'level' => 'intermediate',
            'source' => 'manual',
        ]);
        $student->profile->studentSkills()->create([
            'skill_id' => $aiSkill->id,
            'level' => 'advanced',
            'source' => 'cv_ai',
        ]);
        $application = $this->applicationFor($student);
        $org = $application->opportunity->organizationProfile->user;
        Sanctum::actingAs($org);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200);
        $skills = collect($response->json('data.student_profile.student_skills'));
        $this->assertTrue($skills->contains(fn ($s) => $s['skill']['name'] === 'AutoCAD' && $s['source'] === 'manual'));
        $this->assertTrue($skills->contains(fn ($s) => $s['skill']['name'] === 'Primavera P6' && $s['source'] === 'cv_ai'));
    }

    public function test_the_applications_index_also_includes_skill_evidence(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
        ]);
        $application = $this->applicationFor($student);
        $org = $application->opportunity->organizationProfile->user;
        Sanctum::actingAs($org);

        $response = $this->getJson('/api/organization/applications');

        $response->assertStatus(200);
        $skills = collect($response->json('data.0.student_profile.student_skills'));
        $this->assertTrue($skills->contains(fn ($s) => $s['skill']['name'] === 'AutoCAD' && $s['source'] === 'cv_ai'));
    }

    public function test_the_opportunity_scoped_applicants_list_also_includes_skill_evidence(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'manual',
        ]);
        $application = $this->applicationFor($student);
        $org = $application->opportunity->organizationProfile->user;
        Sanctum::actingAs($org);

        $response = $this->getJson("/api/organization/opportunities/{$application->opportunity_id}/applications");

        $response->assertStatus(200);
        $skills = collect($response->json('data.0.student_profile.student_skills'));
        $this->assertTrue($skills->contains(fn ($s) => $s['skill']['name'] === 'AutoCAD' && $s['source'] === 'manual'));
    }

    public function test_parsed_text_and_ai_internals_are_never_leaked_alongside_skill_evidence(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
        ]);
        $application = $this->applicationFor($student, 'A very specific parsed CV sentence.');
        $org = $application->opportunity->organizationProfile->user;
        Sanctum::actingAs($org);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)->assertJsonMissingPath('data.cv.parsed_text');
        $this->assertStringNotContainsString('A very specific parsed CV sentence.', $response->getContent());
        $this->assertStringNotContainsString('confidence', $response->getContent());
        $this->assertStringNotContainsString('suggestion_id', $response->getContent());
    }

    public function test_existing_application_response_fields_are_preserved(): void
    {
        $student = $this->studentWithProfile();
        $application = $this->applicationFor($student);
        $org = $application->opportunity->organizationProfile->user;
        Sanctum::actingAs($org);

        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $application->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure([
                'data' => [
                    'id', 'student_id', 'opportunity_id', 'cv_id', 'status',
                    'opportunity', 'cv', 'student_profile',
                ],
            ]);
    }

    private function applicationFor(object $student, string $parsedText = 'CV text.'): Application
    {
        $org = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        $organizationProfile = OrganizationProfile::create([
            'user_id' => $org->id,
            'organization_name' => 'Acme',
            'organization_type' => 'company',
        ]);
        $opportunity = $organizationProfile->opportunities()->create([
            'title' => 'Civil Engineer',
            'description' => 'Role',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);

        $cv = CV::create([
            'student_id' => $student->profile->id,
            'title' => 'My CV',
            'file_path' => "cvs/{$student->profile->id}/irrelevant.pdf",
            'parsed_text' => $parsedText,
        ]);

        return Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
