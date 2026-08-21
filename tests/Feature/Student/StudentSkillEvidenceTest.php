<?php

namespace Tests\Feature\Student;

use App\Models\Application;
use App\Models\CV;
use App\Models\CvSkillEvidence;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\StudentSkill;
use App\Models\User;
use App\Services\MatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-6.1: `source` evidence on `student_skills`
 * (`manual` = Self-declared, `cv_ai` = CV-supported) and the anti-spoofing
 * checks `StudentSkillController::store()` applies before ever trusting a
 * `cv_ai` claim.
 */
class StudentSkillEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pre_existing_student_skill_row_defaults_to_manual(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'Laravel']);

        // Created directly, the same way every StudentSkill row was
        // created before this phase -- `source` is never passed.
        $studentSkill = StudentSkill::create([
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
            'level' => 'intermediate',
        ]);

        $this->assertSame('manual', $studentSkill->fresh()->source);
    }

    public function test_a_manual_add_stores_source_manual(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'PHP']);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'advanced',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.source', 'manual');
        $this->assertDatabaseHas('student_skills', [
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
            'source' => 'manual',
        ]);
    }

    public function test_an_explicit_source_manual_is_accepted_with_no_evidence_required(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'Python']);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'beginner',
            'source' => 'manual',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.source', 'manual');
    }

    public function test_a_cv_ai_claim_backed_by_real_evidence_is_accepted(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $cv = $this->cvFor($student->profile->id);
        CvSkillEvidence::create([
            'student_id' => $student->profile->id,
            'cv_id' => $cv->id,
            'skill_id' => $skill->id,
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
            'cv_id' => $cv->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.source', 'cv_ai');
        $this->assertDatabaseHas('student_skills', [
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
            'source' => 'cv_ai',
        ]);
    }

    public function test_a_cv_ai_claim_with_no_matching_evidence_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $cv = $this->cvFor($student->profile->id);
        // No CvSkillEvidence row exists for this (cv, skill) pair -- the
        // AI extraction never actually identified this skill on this CV.
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
            'cv_id' => $cv->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_a_student_cannot_spoof_cv_ai_for_an_arbitrary_catalog_skill(): void
    {
        $student = $this->studentWithProfile();
        $realSkill = Skill::create(['name' => 'AutoCAD']);
        $arbitrarySkill = Skill::create(['name' => 'Revit']);
        $cv = $this->cvFor($student->profile->id);
        // Evidence exists for AutoCAD, not for Revit -- the claim must be
        // scoped to the exact skill the evidence covers.
        CvSkillEvidence::create([
            'student_id' => $student->profile->id,
            'cv_id' => $cv->id,
            'skill_id' => $realSkill->id,
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $arbitrarySkill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
            'cv_id' => $cv->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_a_student_cannot_use_another_students_cv_evidence(): void
    {
        $owner = $this->studentWithProfile();
        $attacker = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $ownerCv = $this->cvFor($owner->profile->id);
        CvSkillEvidence::create([
            'student_id' => $owner->profile->id,
            'cv_id' => $ownerCv->id,
            'skill_id' => $skill->id,
        ]);
        Sanctum::actingAs($attacker->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
            'cv_id' => $ownerCv->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_a_cv_id_missing_from_the_request_is_rejected_when_claiming_cv_ai(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_deleted_cv_evidence_is_safely_rejected_not_a_crash(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $cv = $this->cvFor($student->profile->id);
        CvSkillEvidence::create([
            'student_id' => $student->profile->id,
            'cv_id' => $cv->id,
            'skill_id' => $skill->id,
        ]);
        $cvId = $cv->id;
        // Deleting the CV cascades the evidence row away with it.
        $cv->delete();
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'source' => 'cv_ai',
            'cv_id' => $cvId,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_student_skill_uniqueness_is_unchanged_regardless_of_source(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD']);
        $cv = $this->cvFor($student->profile->id);
        CvSkillEvidence::create([
            'student_id' => $student->profile->id,
            'cv_id' => $cv->id,
            'skill_id' => $skill->id,
        ]);
        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'beginner',
            'source' => 'manual',
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/skills', [
            'skill_id' => $skill->id,
            'level' => 'expert',
            'source' => 'cv_ai',
            'cv_id' => $cv->id,
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('student_skills', 1);
    }

    public function test_matching_service_score_is_unchanged_regardless_of_evidence_source(): void
    {
        $skill = Skill::create(['name' => 'AutoCAD']);

        $manualStudent = $this->studentWithProfile();
        $manualStudent->profile->update(['major' => 'Civil Engineering']);
        $manualStudent->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'expert',
            'years_of_experience' => 5,
            'source' => 'manual',
        ]);

        $cvAiStudent = $this->studentWithProfile();
        $cvAiStudent->profile->update(['major' => 'Civil Engineering']);
        $cvAiStudent->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'expert',
            'years_of_experience' => 5,
            'source' => 'cv_ai',
        ]);

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
            'field_of_study' => 'Civil Engineering',
            'status' => 'open',
        ]);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        $manualCv = $this->cvFor($manualStudent->profile->id);
        $manualApplication = Application::create([
            'student_id' => $manualStudent->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $manualCv->id,
        ]);

        $cvAiCv = $this->cvFor($cvAiStudent->profile->id);
        $cvAiApplication = Application::create([
            'student_id' => $cvAiStudent->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cvAiCv->id,
        ]);

        $service = app(MatchingService::class);
        $manualResult = $service->analyze($manualApplication);
        $cvAiResult = $service->analyze($cvAiApplication);

        $this->assertSame($manualResult['overall_match_score'], $cvAiResult['overall_match_score']);
    }

    private function cvFor(int $studentId): CV
    {
        return CV::create([
            'student_id' => $studentId,
            'title' => 'My CV',
            'file_path' => "cvs/{$studentId}/irrelevant.pdf",
            'parsed_text' => 'Some CV text.',
        ]);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
