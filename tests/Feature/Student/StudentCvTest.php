<?php

namespace Tests\Feature\Student;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentCvTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_list_their_cvs(): void
    {
        $student = $this->studentWithProfile();
        $student->profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/cvs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_student_can_create_a_cv_record(): void
    {
        $student = $this->studentWithProfile();

        Sanctum::actingAs($student->user);

        $response = $this->postJson('/api/student/cvs', [
            'title' => 'Software Engineer CV',
            'file_path' => 'cvs/software-engineer.pdf',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Software Engineer CV');

        $this->assertDatabaseHas('cvs', [
            'student_id' => $student->profile->id,
            'title' => 'Software Engineer CV',
        ]);
    }

    public function test_student_can_set_one_cv_as_default(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'CV One',
            'file_path' => 'cvs/one.pdf',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->putJson("/api/student/cvs/{$cv->id}/default");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('cvs', [
            'id' => $cv->id,
            'is_default' => true,
        ]);
    }

    public function test_setting_a_new_default_removes_the_old_default(): void
    {
        $student = $this->studentWithProfile();
        $firstCv = $student->profile->cvs()->create([
            'title' => 'CV One',
            'file_path' => 'cvs/one.pdf',
            'is_default' => true,
        ]);
        $secondCv = $student->profile->cvs()->create([
            'title' => 'CV Two',
            'file_path' => 'cvs/two.pdf',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->putJson("/api/student/cvs/{$secondCv->id}/default");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('cvs', ['id' => $secondCv->id, 'is_default' => true]);
        $this->assertDatabaseHas('cvs', ['id' => $firstCv->id, 'is_default' => false]);
    }

    public function test_student_can_delete_an_unused_cv(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'Unused CV',
            'file_path' => 'cvs/unused.pdf',
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->deleteJson("/api/student/cvs/{$cv->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('cvs', ['id' => $cv->id]);
    }

    public function test_student_cannot_delete_a_cv_used_by_an_application(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'Used CV',
            'file_path' => 'cvs/used.pdf',
        ]);

        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $organizationProfile = OrganizationProfile::create([
            'user_id' => $organizationUser->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);

        $opportunity = Opportunity::create([
            'organization_id' => $organizationProfile->id,
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);

        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->deleteJson("/api/student/cvs/{$cv->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot delete a CV that has been used in an application');

        $this->assertDatabaseHas('cvs', ['id' => $cv->id]);
    }

    public function test_student_cannot_delete_another_students_cv(): void
    {
        $owner = $this->studentWithProfile();
        $cv = $owner->profile->cvs()->create([
            'title' => 'Owner CV',
            'file_path' => 'cvs/owner.pdf',
        ]);

        $otherStudent = $this->studentWithProfile();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->deleteJson("/api/student/cvs/{$cv->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'CV not found');

        $this->assertDatabaseHas('cvs', ['id' => $cv->id]);
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
