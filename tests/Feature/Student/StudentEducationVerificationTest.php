<?php

namespace Tests\Feature\Student;

use App\Models\EducationVerification;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-1: Student education-verification submission, own-state read,
 * own-document view, and resubmission rules. Mirrors
 * `StudentCvUploadTest`'s conventions for the upload/download/cleanup
 * surface.
 */
class StudentEducationVerificationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Submission
    // -----------------------------------------------------------------

    public function test_a_valid_submission_succeeds_and_is_pending(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->submit($student, 'State University', 'BSc Computer Science');

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.institution_name', 'State University')
            ->assertJsonPath('data.degree_or_program', 'BSc Computer Science');
    }

    public function test_the_document_is_stored_privately_on_the_local_disk(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $this->submit($student)->assertStatus(201);

        $verification = EducationVerification::where('student_id', $student->profile->id)->firstOrFail();
        Storage::disk('local')->assertExists($verification->document_path);
        $this->assertStringStartsWith("education-verifications/{$student->profile->id}/", $verification->document_path);
    }

    public function test_submission_requires_authentication(): void
    {
        Storage::fake('local');

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(401);
    }

    public function test_an_organization_user_is_rejected(): void
    {
        Storage::fake('local');
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        Sanctum::actingAs($organizationUser);

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(403);
    }

    public function test_a_student_without_a_profile_is_rejected(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(404)
            ->assertJsonPath('message', 'You must create a student profile first');
    }

    public function test_a_non_pdf_file_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
            'file' => UploadedFile::fake()->create('proof.docx', 100, 'application/msword'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('education_verifications', 0);
    }

    public function test_a_file_over_5mb_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
            'file' => UploadedFile::fake()->create('proof.pdf', 5121, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('education_verifications', 0);
    }

    public function test_missing_institution_name_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/education-verification', [
            'degree_or_program' => 'BSc',
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['institution_name']);
        $this->assertDatabaseCount('education_verifications', 0);
    }

    public function test_missing_degree_or_program_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['degree_or_program']);
    }

    public function test_missing_file_is_rejected(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    public function test_a_student_cannot_spoof_status_reviewer_or_reviewed_at(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $response = $this->post('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
            'status' => 'verified',
            'reviewed_by_admin_id' => $admin->id,
            'reviewed_at' => now()->toDateTimeString(),
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $verification = EducationVerification::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertSame('pending', $verification->status);
        $this->assertNull($verification->reviewed_by_admin_id);
        $this->assertNull($verification->reviewed_at);
    }

    // -----------------------------------------------------------------
    // Own state
    // -----------------------------------------------------------------

    public function test_reading_own_state_with_no_submission_returns_not_submitted(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/education-verification');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'not_submitted')
            ->assertJsonPath('data.institution_name', null);
    }

    public function test_reading_own_state_after_submission_returns_pending(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->submit($student, 'State University', 'BSc');

        $response = $this->getJson('/api/student/education-verification');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.institution_name', 'State University')
            ->assertJsonPath('data.degree_or_program', 'BSc');
    }

    public function test_own_state_never_exposes_the_document_path_or_reviewer_id(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->submit($student);

        $response = $this->getJson('/api/student/education-verification');

        $response->assertStatus(200)
            ->assertJsonMissingPath('data.document_path')
            ->assertJsonMissingPath('data.reviewed_by_admin_id');
    }

    // -----------------------------------------------------------------
    // Own document
    // -----------------------------------------------------------------

    public function test_a_student_can_view_their_own_document(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->submit($student);

        $response = $this->get('/api/student/education-verification/document');

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_a_student_with_no_submission_gets_a_controlled_404_for_the_document(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/education-verification/document');

        $response->assertStatus(404);
    }

    public function test_each_student_only_ever_sees_their_own_document(): void
    {
        Storage::fake('local');
        $owner = $this->studentWithProfile();
        Sanctum::actingAs($owner->user);
        $this->submit($owner, 'Owner University', 'BSc');
        $ownerVerification = EducationVerification::where('student_id', $owner->profile->id)->firstOrFail();

        $other = $this->studentWithProfile();
        Sanctum::actingAs($other->user);
        $this->submit($other, 'Other University', 'MSc');
        $otherVerification = EducationVerification::where('student_id', $other->profile->id)->firstOrFail();

        // There is no `{id}` in this route at all -- each student can only
        // ever reach the row scoped to their own `studentProfile`, so the
        // "other student's document" simply isn't reachable through it.
        $response = $this->getJson('/api/student/education-verification');
        $response->assertJsonPath('data.institution_name', 'Other University');

        $this->assertNotSame($ownerVerification->document_path, $otherVerification->document_path);
    }

    public function test_a_missing_physical_file_returns_a_controlled_404(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        $student->profile->educationVerification()->create([
            'institution_name' => 'Ghost University',
            'degree_or_program' => 'BSc',
            'document_path' => "education-verifications/{$student->profile->id}/does-not-exist.pdf",
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/education-verification/document');

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Education verification document not found');
    }

    // -----------------------------------------------------------------
    // Resubmission
    // -----------------------------------------------------------------

    public function test_a_rejected_verification_can_be_resubmitted(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->submit($student, 'State University', 'BSc');
        $verification = EducationVerification::where('student_id', $student->profile->id)->firstOrFail();
        $verification->update([
            'status' => 'rejected',
            'rejection_reason' => 'Document is unreadable.',
            'reviewed_at' => now(),
        ]);

        $response = $this->submit($student, 'State University', 'BSc (Honours)');

        $response->assertStatus(200)->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('education_verifications', 1);
        $fresh = $verification->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
        $this->assertNull($fresh->reviewed_at);
        $this->assertSame('BSc (Honours)', $fresh->degree_or_program);
    }

    public function test_resubmission_replaces_the_old_managed_file(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->submit($student);
        $verification = EducationVerification::where('student_id', $student->profile->id)->firstOrFail();
        $verification->update(['status' => 'rejected', 'rejection_reason' => 'Blurry.']);
        $oldPath = $verification->document_path;
        Storage::disk('local')->assertExists($oldPath);

        $this->submit($student);

        $fresh = $verification->fresh();
        $this->assertNotSame($oldPath, $fresh->document_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($fresh->document_path);
    }

    public function test_a_pending_verification_can_also_be_resubmitted(): void
    {
        // Unusual but explicitly allowed (docs/BUSINESS_RULES.md section
        // 9b): store() only blocks a `verified` current status, so a
        // student can also correct a still-`pending` submission before an
        // Admin has reviewed it.
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->submit($student, 'State University', 'BSc');
        $verification = EducationVerification::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertSame('pending', $verification->status);
        $oldPath = $verification->document_path;

        $response = $this->submit($student, 'State University', 'BSc (corrected)');

        $response->assertStatus(200)->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('education_verifications', 1);
        $fresh = $verification->fresh();
        $this->assertSame('BSc (corrected)', $fresh->degree_or_program);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($fresh->document_path);
    }

    public function test_a_verified_verification_cannot_be_resubmitted(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->submit($student);
        $verification = EducationVerification::where('student_id', $student->profile->id)->firstOrFail();
        $verification->update(['status' => 'verified', 'reviewed_at' => now()]);
        $verifiedPath = $verification->document_path;

        $response = $this->submit($student, 'Another University', 'PhD');

        $response->assertStatus(409);
        $fresh = $verification->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame($verifiedPath, $fresh->document_path);
        Storage::disk('local')->assertExists($verifiedPath);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function submit(
        object $student,
        string $institutionName = 'State University',
        string $degreeOrProgram = 'BSc Computer Science',
    ) {
        Sanctum::actingAs($student->user);

        return $this->post('/api/student/education-verification', [
            'institution_name' => $institutionName,
            'degree_or_program' => $degreeOrProgram,
            'file' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
