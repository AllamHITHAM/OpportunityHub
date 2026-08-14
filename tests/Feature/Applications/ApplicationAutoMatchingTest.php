<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-2: proves `POST /api/opportunities/{opportunity}/apply` now
 * synchronously calculates and persists `match_score` (using the same
 * `MatchingService` formula as the manual `/analyze` endpoint), that this
 * happens inside the same transaction as the Application insert, that a
 * sparse/empty student profile still produces a real (not crashing, not
 * null) score, and that the score is still never exposed in the student
 * response (Phase 8A-1 privacy).
 */
class ApplicationAutoMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_applying_automatically_calculates_and_persists_a_match_score(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $application = Application::where('opportunity_id', $opportunity->id)->firstOrFail();

        $this->assertNotNull($application->match_score);
    }

    public function test_auto_calculated_score_matches_what_manual_analysis_would_produce(): void
    {
        $skill = Skill::create(['name' => 'Laravel']);
        $student = $this->studentWithProfileAndCv(['major' => 'Computer Science']);
        $student->profile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'intermediate',
            'years_of_experience' => 2,
        ]);

        $opportunity = $this->openOpportunity([
            'field_of_study' => 'Computer Science',
            'experience_level' => 'junior',
        ]);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $application = Application::where('opportunity_id', $opportunity->id)->firstOrFail();

        // skills=100 (matched required), field=100 (exact major match),
        // experience=100 (junior requires 1 year, capped at 2 years).
        $this->assertEquals(100.0, (float) $application->match_score);
    }

    public function test_a_sparse_profile_with_no_skills_or_major_still_gets_a_real_calculated_score(): void
    {
        $student = $this->studentWithProfileAndCv();
        $skill = Skill::create(['name' => 'Laravel']);
        $opportunity = $this->openOpportunity(['field_of_study' => 'Computer Science']);
        $opportunity->opportunitySkills()->create(['skill_id' => $skill->id, 'is_required' => true]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(201);

        $application = Application::where('opportunity_id', $opportunity->id)->firstOrFail();

        // skills=0 (no matched skills, but opportunity has real
        // requirements so this IS scoreable), field unavailable (no
        // major), experience=0 (junior requires 1yr, student has none) --
        // a real, low, non-null score, never a crash and never null.
        $this->assertNotNull($application->match_score);
        $this->assertEquals(0.0, (float) $application->match_score);
    }

    public function test_an_opportunity_with_no_skills_or_field_still_produces_a_real_score_from_experience_alone(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['field_of_study' => null, 'experience_level' => 'no_experience']);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $application = Application::where('opportunity_id', $opportunity->id)->firstOrFail();

        // skills and field both unavailable -- only experience (20 weight,
        // now 100% of the total) is scoreable, and no_experience always
        // scores 100.
        $this->assertEquals(100.0, (float) $application->match_score);
    }

    public function test_the_create_response_still_never_exposes_match_score_to_the_student(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(201)->assertJsonMissingPath('data.match_score');

        // Sanity check: it really was calculated (non-null in the DB),
        // proving the omission is deliberate field-level hiding, not an
        // accidental "it just never got calculated".
        $application = Application::where('opportunity_id', $opportunity->id)->firstOrFail();
        $this->assertNotNull($application->match_score);
    }

    public function test_organization_new_application_notification_is_still_created_alongside_auto_matching(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $opportunity->organizationProfile->user_id,
            'type' => 'application',
        ]);
    }

    public function test_a_failed_duplicate_application_does_not_leave_a_stray_match_score_calculation(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(409);

        $this->assertDatabaseCount('applications', 1);
    }

    private function studentWithProfileAndCv(array $overrides = []): object
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

    private function openOpportunity(array $overrides = []): Opportunity
    {
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $organizationProfile = OrganizationProfile::create([
            'user_id' => $organizationUser->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $organizationProfile->approval_status = 'approved';
        $organizationProfile->save();

        return $organizationProfile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }
}
