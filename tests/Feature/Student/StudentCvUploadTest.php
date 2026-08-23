<?php

namespace Tests\Feature\Student;

use App\Models\Application;
use App\Models\CV;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-4: real multipart PDF upload for `POST /api/student/cvs`, the
 * authenticated CV download endpoints (student's own, and an organization's
 * through one of its own applications), and the delete-time physical-file
 * cleanup rules. `StudentCvTest.php` still covers the pre-existing
 * list/set-default/delete-conflict behavior (unchanged by this phase); this
 * file is additive, focused on the upload/download/cleanup surface only.
 */
class StudentCvUploadTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Upload
    // -----------------------------------------------------------------

    public function test_a_valid_pdf_upload_succeeds(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->uploadCv($student, 'Software Engineer CV', 'resume.pdf');

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Software Engineer CV');
    }

    public function test_the_uploaded_file_is_physically_stored_on_the_local_disk(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $this->uploadCv($student, 'My CV', 'resume.pdf')->assertStatus(201);

        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        Storage::disk('local')->assertExists($cv->file_path);
    }

    public function test_the_stored_file_path_is_a_managed_path_under_the_owning_student(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $this->uploadCv($student, 'My CV', 'resume.pdf')->assertStatus(201);

        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertStringStartsWith("cvs/{$student->profile->id}/", $cv->file_path);
        $this->assertStringEndsWith('.pdf', $cv->file_path);
    }

    public function test_the_title_is_stored_exactly_as_submitted(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $this->uploadCv($student, 'Backend Developer Resume', 'resume.pdf')->assertStatus(201);

        $this->assertDatabaseHas('cvs', [
            'student_id' => $student->profile->id,
            'title' => 'Backend Developer Resume',
        ]);
    }

    public function test_upload_requires_authentication(): void
    {
        Storage::fake('local');

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            'file' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }

    public function test_an_organization_user_is_rejected(): void
    {
        Storage::fake('local');
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);
        Sanctum::actingAs($organizationUser);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            'file' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(403);
    }

    public function test_a_student_without_a_profile_is_rejected(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            'file' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'You must create a student profile first');
    }

    public function test_a_non_pdf_file_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            'file' => UploadedFile::fake()->create('resume.docx', 100, 'application/msword'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('cvs', 0);
    }

    public function test_a_file_over_5mb_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            // 5120 KB is the limit -- one KB over it must fail.
            'file' => UploadedFile::fake()->create('resume.pdf', 5121, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('cvs', 0);
    }

    public function test_a_file_exactly_at_the_5mb_limit_is_accepted(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            'file' => UploadedFile::fake()->create('resume.pdf', 5120, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
    }

    public function test_a_missing_file_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('cvs', 0);
    }

    public function test_a_client_supplied_file_path_cannot_bypass_the_real_upload(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            // An attacker-controlled file_path -- must be completely
            // ignored; the request still requires and uses a real `file`.
            'file_path' => '/etc/passwd',
            'file' => UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertNotSame('/etc/passwd', $cv->file_path);
        $this->assertStringStartsWith("cvs/{$student->profile->id}/", $cv->file_path);
    }

    public function test_the_stored_filename_is_never_the_clients_original_filename(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/cvs', [
            'title' => 'My CV',
            // A path-traversal attempt as the "original" filename -- since
            // the server always generates its own random filename, the
            // client's filename never influences the stored path at all.
            'file' => UploadedFile::fake()->create('../../../evil.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertStringNotContainsString('..', $cv->file_path);
        $this->assertStringNotContainsString('evil', $cv->file_path);
        $this->assertMatchesRegularExpression(
            '#^cvs/'.$student->profile->id.'/[0-9a-f-]{36}\.pdf$#',
            $cv->file_path,
        );
    }

    // -----------------------------------------------------------------
    // Download — student
    // -----------------------------------------------------------------

    public function test_a_student_can_download_their_own_cv(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadCv($student, 'My CV', 'resume.pdf');
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $response = $this->get("/api/student/cvs/{$cv->id}/download");

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
    }

    /**
     * Phase 8A-6.3: confirms the exact real response shape the Flutter
     * fix (View/Download CV) relies on — served `inline` (never
     * `attachment`), with the real CV title (never the server-managed
     * UUID storage filename) as the suggested `.pdf` filename. This is
     * unchanged, pre-existing backend behavior (Laravel's own
     * `Storage::response()` default) — this test documents/locks it in,
     * it does not reflect a behavior change.
     */
    public function test_the_download_response_is_served_inline_with_a_meaningful_pdf_filename(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadCv($student, 'Software Engineer CV', 'resume.pdf');
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $response = $this->get("/api/student/cvs/{$cv->id}/download");

        $response->assertStatus(200);
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline', $disposition);
        $this->assertStringContainsString('Software Engineer CV.pdf', $disposition);
        // The real server-managed storage path (a UUID, never the
        // title) is never leaked as the suggested filename.
        $this->assertStringNotContainsString($cv->file_path, $disposition);
    }

    public function test_a_student_cannot_download_another_students_cv(): void
    {
        Storage::fake('local');
        $owner = $this->studentWithProfile();
        Sanctum::actingAs($owner->user);
        $this->uploadCv($owner, 'Owner CV', 'resume.pdf');
        $cv = CV::where('student_id', $owner->profile->id)->firstOrFail();

        $otherStudent = $this->studentWithProfile();
        Sanctum::actingAs($otherStudent->user);

        $response = $this->getJson("/api/student/cvs/{$cv->id}/download");

        $response->assertStatus(404)->assertJsonPath('message', 'CV not found');
    }

    public function test_a_missing_physical_file_returns_a_controlled_404(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'Ghost CV',
            'file_path' => "cvs/{$student->profile->id}/does-not-exist.pdf",
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/cvs/{$cv->id}/download");

        $response->assertStatus(404)->assertJsonPath('message', 'CV file not found');
    }

    public function test_a_legacy_fake_path_row_returns_a_controlled_404_not_a_crash(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'Legacy CV',
            'file_path' => 'C:\\Users\\Someone\\Documents\\CV.pdf',
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->getJson("/api/student/cvs/{$cv->id}/download");

        $response->assertStatus(404)->assertJsonPath('message', 'CV file not found');
    }

    // -----------------------------------------------------------------
    // Download — organization
    // -----------------------------------------------------------------

    public function test_an_organization_can_download_the_cv_for_an_application_to_its_own_opportunity(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadCv($student, 'My CV', 'resume.pdf');
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->get("/api/organization/applications/{$application->id}/cv");

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_another_organizations_application_is_denied(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadCv($student, 'My CV', 'resume.pdf');
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $ownerOrg = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($ownerOrg);
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $otherOrg = $this->approvedOrganization();
        Sanctum::actingAs($otherOrg->user);

        $response = $this->getJson("/api/organization/applications/{$application->id}/cv");

        $response->assertStatus(404)->assertJsonPath('message', 'Application not found');
    }

    public function test_a_nonexistent_application_id_is_denied_not_a_cv_guessing_vector(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/applications/999999/cv');

        $response->assertStatus(404);
    }

    public function test_an_application_whose_cv_file_is_missing_is_controlled_safely(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'Ghost CV',
            'file_path' => "cvs/{$student->profile->id}/does-not-exist.pdf",
        ]);

        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/applications/{$application->id}/cv");

        $response->assertStatus(404)->assertJsonPath('message', 'CV file not found');
    }

    // -----------------------------------------------------------------
    // Delete — physical file cleanup
    // -----------------------------------------------------------------

    public function test_deleting_a_cv_removes_its_managed_physical_file(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadCv($student, 'My CV', 'resume.pdf');
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        Storage::disk('local')->assertExists($cv->file_path);

        $response = $this->deleteJson("/api/student/cvs/{$cv->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('cvs', ['id' => $cv->id]);
        Storage::disk('local')->assertMissing($cv->file_path);
    }

    public function test_a_conflicting_delete_leaves_the_db_row_and_physical_file_untouched(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadCv($student, 'My CV', 'resume.pdf');
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $response = $this->deleteJson("/api/student/cvs/{$cv->id}");

        $response->assertStatus(409);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id]);
        Storage::disk('local')->assertExists($cv->file_path);
    }

    public function test_deleting_a_legacy_fake_path_row_does_not_touch_the_filesystem(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        $cv = $student->profile->cvs()->create([
            'title' => 'Legacy CV',
            'file_path' => 'C:\\Users\\Someone\\Documents\\CV.pdf',
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->deleteJson("/api/student/cvs/{$cv->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('cvs', ['id' => $cv->id]);
    }

    public function test_deleting_one_cv_never_deletes_another_cvs_file(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadCv($student, 'CV One', 'one.pdf');
        $this->uploadCv($student, 'CV Two', 'two.pdf');
        $cvs = CV::where('student_id', $student->profile->id)->get();
        $first = $cvs->firstWhere('title', 'CV One');
        $second = $cvs->firstWhere('title', 'CV Two');

        $this->deleteJson("/api/student/cvs/{$first->id}")->assertStatus(200);

        Storage::disk('local')->assertMissing($first->file_path);
        Storage::disk('local')->assertExists($second->file_path);
        $this->assertDatabaseHas('cvs', ['id' => $second->id]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function uploadCv(object $student, string $title, string $filename)
    {
        Sanctum::actingAs($student->user);

        return $this->post('/api/student/cvs', [
            'title' => $title,
            'file' => UploadedFile::fake()->create($filename, 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);
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

    private function opportunityFor(object $org): Opportunity
    {
        return $org->profile->opportunities()->create([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);
    }
}
