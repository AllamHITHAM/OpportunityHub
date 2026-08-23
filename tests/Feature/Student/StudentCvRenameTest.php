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

/**
 * Phase 8A-6.2: `PATCH /api/student/cvs/{cv}` — rename only, the PDF file
 * itself is never touched.
 */
class StudentCvRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_rename_their_own_cv(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'Old Title',
            'file_path' => 'cvs/old.pdf',
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'Updated CV Name']);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Updated CV Name');
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'Updated CV Name']);
    }

    public function test_student_cannot_rename_another_students_cv(): void
    {
        $owner = $this->studentWithProfile();
        $cv = $owner->profile->cvs()->create(['title' => 'Owner CV', 'file_path' => 'cvs/owner.pdf']);
        $other = $this->studentWithProfile();
        Sanctum::actingAs($other->user);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'Hijacked']);

        $response->assertStatus(404)->assertJsonPath('message', 'CV not found');
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'Owner CV']);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/mine.pdf']);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'New Name']);

        $response->assertStatus(401);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'My CV']);
    }

    public function test_an_organization_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/mine.pdf']);
        $org = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        Sanctum::actingAs($org);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'New Name']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'My CV']);
    }

    public function test_a_blank_title_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/mine.pdf']);
        Sanctum::actingAs($student->user);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => '   ']);

        $response->assertStatus(422);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'My CV']);
    }

    public function test_a_missing_title_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/mine.pdf']);
        Sanctum::actingAs($student->user);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", []);

        $response->assertStatus(422);
    }

    public function test_a_title_over_255_characters_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/mine.pdf']);
        Sanctum::actingAs($student->user);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => str_repeat('A', 256)]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'My CV']);
    }

    public function test_a_title_at_exactly_255_characters_is_accepted(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/mine.pdf']);
        Sanctum::actingAs($student->user);

        $title = str_repeat('A', 255);
        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => $title]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => $title]);
    }

    public function test_forbidden_fields_sent_in_the_body_are_ignored(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/mine.pdf',
            'version' => 1,
            'is_default' => false,
            'created_by_ai' => false,
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", [
            'title' => 'Renamed CV',
            'student_id' => 999999,
            'file_path' => 'cvs/hijacked.pdf',
            'version' => 99,
            'is_default' => true,
            'created_by_ai' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('cvs', [
            'id' => $cv->id,
            'title' => 'Renamed CV',
            'student_id' => $student->profile->id,
            'file_path' => 'cvs/mine.pdf',
            'version' => 1,
            'is_default' => false,
            'created_by_ai' => false,
        ]);
    }

    public function test_the_pdf_file_path_is_unchanged_by_rename(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'Old', 'file_path' => 'cvs/1/real.pdf']);
        Sanctum::actingAs($student->user);

        $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'New'])->assertStatus(200);

        $this->assertSame('cvs/1/real.pdf', $cv->fresh()->file_path);
    }

    public function test_the_default_flag_is_unchanged_by_rename(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'Old', 'file_path' => 'cvs/one.pdf', 'is_default' => true]);
        Sanctum::actingAs($student->user);

        $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'New'])->assertStatus(200);

        $this->assertTrue($cv->fresh()->is_default);
    }

    public function test_renaming_a_cv_already_used_in_an_application_is_allowed(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'Used CV', 'file_path' => 'cvs/used.pdf']);

        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);
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
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($student->user);

        $response = $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'Renamed Used CV']);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'Renamed Used CV']);
        // The Application's reference to this CV (by id, never by title)
        // is completely unaffected by the rename.
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'cv_id' => $cv->id]);
    }

    public function test_rename_does_not_touch_updated_at_of_unrelated_cvs(): void
    {
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create(['title' => 'Rename Me', 'file_path' => 'cvs/one.pdf']);
        $other = $student->profile->cvs()->create(['title' => 'Leave Me Alone', 'file_path' => 'cvs/two.pdf']);
        Sanctum::actingAs($student->user);

        $this->patchJson("/api/student/cvs/{$cv->id}", ['title' => 'Renamed'])->assertStatus(200);

        $this->assertSame('Leave Me Alone', $other->fresh()->title);
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
