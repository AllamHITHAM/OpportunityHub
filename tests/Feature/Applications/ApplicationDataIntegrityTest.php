<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_stores_the_selected_cv_id(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
    }

    public function test_application_stores_the_correct_student_id_and_opportunity_id(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('applications', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
    }

    public function test_deleting_a_cv_used_by_an_application_remains_blocked(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->deleteJson("/api/student/cvs/{$student->cv->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot delete a CV that has been used in an application');

        $this->assertDatabaseHas('cvs', ['id' => $student->cv->id]);
    }

    public function test_duplicate_application_protection_works_at_both_api_and_database_level(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        // API level: the endpoint's own pre-check returns a clean 409.
        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(409);

        $this->assertDatabaseCount('applications', 1);

        // Database level: the unique(student_id, opportunity_id) constraint
        // rejects a duplicate row even when created directly, bypassing the
        // controller's own pre-check entirely.
        $this->expectException(QueryException::class);

        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
    }

    private function studentWithProfileAndCv(): object
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
