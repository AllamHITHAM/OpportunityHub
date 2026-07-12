<?php

namespace Tests\Feature\Applications;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_apply_to_an_open_opportunity_using_their_own_cv(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
            'cover_letter' => 'I would love to join.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cv_id', $student->cv->id)
            ->assertJsonPath('data.opportunity_id', $opportunity->id);

        $this->assertDatabaseHas('applications', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
    }

    public function test_student_cannot_apply_to_the_same_opportunity_twice(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You have already applied to this opportunity');

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_student_cannot_apply_to_a_draft_opportunity(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['status' => 'draft']);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Opportunity not found');

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_student_cannot_apply_to_a_closed_opportunity(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity(['status' => 'closed']);

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Opportunity not found');

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_student_cannot_apply_using_another_students_cv(): void
    {
        $student = $this->studentWithProfileAndCv();
        $otherStudent = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $otherStudent->cv->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cv_id']);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_student_cannot_apply_without_a_student_profile(): void
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => 1,
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You must create a student profile first');

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_student_can_list_only_their_own_applications(): void
    {
        $student = $this->studentWithProfileAndCv();
        $otherStudent = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();
        $otherOpportunity = $this->openOpportunity();

        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        Application::create([
            'student_id' => $otherStudent->profile->id,
            'opportunity_id' => $otherOpportunity->id,
            'cv_id' => $otherStudent->cv->id,
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/applications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $student->profile->id);
    }

    public function test_organization_cannot_use_student_apply_endpoint(): void
    {
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($organizationUser);

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => 1,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_applying_creates_an_application_with_pending_status(): void
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
            'status' => 'pending',
        ]);
    }

    public function test_applied_at_is_set_automatically(): void
    {
        $student = $this->studentWithProfileAndCv();
        $opportunity = $this->openOpportunity();

        Sanctum::actingAs($student->user);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $application = Application::where('opportunity_id', $opportunity->id)->firstOrFail();

        $this->assertNotNull($application->applied_at);
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
