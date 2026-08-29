<?php

namespace Tests\Feature\Admin;

use App\Models\EducationVerification;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8B-1: Admin review of Student education-verification submissions --
 * list, view, document, verify, reject. Mirrors
 * `SkillSuggestionManagementTest`'s conventions for the review-workflow
 * shape (already-reviewed 409, reviewer/timestamp set, no silent
 * overwrites).
 */
class EducationVerificationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_verifications(): void
    {
        $admin = $this->admin();
        $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/education-verifications');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_pending_verifications_are_listed_first(): void
    {
        $admin = $this->admin();
        $verified = $this->verificationFor($this->studentWithProfile(), 'verified');
        $pending = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/education-verifications');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame($pending->id, $ids->first());
        $this->assertTrue($ids->contains($verified->id));
    }

    public function test_the_list_includes_student_identity(): void
    {
        $admin = $this->admin();
        $student = $this->studentWithProfile('jane@example.com');
        $this->verificationFor($student, 'pending');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/education-verifications');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.student_profile.user.email', 'jane@example.com');
    }

    public function test_admin_can_view_details(): void
    {
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/education-verifications/{$verification->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $verification->id)
            ->assertJsonPath('data.institution_name', $verification->institution_name);
    }

    public function test_admin_can_view_the_document(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Storage::disk('local')->put($verification->document_path, 'fake-pdf-bytes');
        Sanctum::actingAs($admin);

        $response = $this->get("/api/admin/education-verifications/{$verification->id}/document");

        $response->assertStatus(200);
        $this->assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_the_document_response_uses_inline_disposition_not_attachment(): void
    {
        // The Admin document-preview fix relies on this already being
        // `inline` (not `attachment`) -- pins down the exact header this
        // phase's Flutter-side fix depends on, since nothing here
        // currently asserts it.
        Storage::fake('local');
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Storage::disk('local')->put($verification->document_path, 'fake-pdf-bytes');
        Sanctum::actingAs($admin);

        $response = $this->get("/api/admin/education-verifications/{$verification->id}/document");

        $response->assertStatus(200);
        $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_a_non_admin_is_denied_the_document(): void
    {
        Storage::fake('local');
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Storage::disk('local')->put($verification->document_path, 'fake-pdf-bytes');
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        Sanctum::actingAs($organizationUser);

        $response = $this->getJson("/api/admin/education-verifications/{$verification->id}/document");

        $response->assertStatus(403);
    }

    public function test_a_missing_physical_file_returns_a_controlled_404(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/education-verifications/{$verification->id}/document");

        $response->assertStatus(404);
    }

    public function test_no_document_url_is_ever_exposed_in_list_or_show_responses(): void
    {
        $admin = $this->admin();
        $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $listResponse = $this->getJson('/api/admin/education-verifications');
        $listResponse->assertJsonMissingPath('data.0.document_path');

        $verification = EducationVerification::first();
        $showResponse = $this->getJson("/api/admin/education-verifications/{$verification->id}");
        $showResponse->assertJsonMissingPath('data.document_path');
    }

    public function test_admin_sees_the_students_current_resubmission_not_the_original(): void
    {
        // Phase 8.1: a Student replacing/resubmitting a document never
        // creates a second row -- the Admin must review the exact same
        // row, now carrying the new file and back at `pending`.
        Storage::fake('local');
        $admin = $this->admin();
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->postJson('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('proof.pdf', 'original bytes'),
        ])->assertStatus(201);
        $verification = EducationVerification::where('student_id', $student->profile->id)->firstOrFail();
        $verification->update(['status' => 'rejected', 'rejection_reason' => 'Blurry.']);

        $this->postJson('/api/student/education-verification', [
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc (corrected)',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('proof2.pdf', 'replacement bytes'),
        ])->assertStatus(200);

        Sanctum::actingAs($admin);
        $showResponse = $this->getJson("/api/admin/education-verifications/{$verification->id}");
        $showResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.degree_or_program', 'BSc (corrected)')
            ->assertJsonPath('data.rejection_reason', null);

        $documentResponse = $this->get("/api/admin/education-verifications/{$verification->id}/document");
        $documentResponse->assertStatus(200);
        $this->assertSame('replacement bytes', $documentResponse->streamedContent());

        // Still exactly one row for this student -- never a second,
        // competing verification record.
        $this->assertDatabaseCount('education_verifications', 1);
    }

    public function test_a_non_admin_is_denied_the_list(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/admin/education-verifications');

        $response->assertStatus(403);
    }

    public function test_a_non_admin_is_denied_verify(): void
    {
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        Sanctum::actingAs($organizationUser);

        $response = $this->putJson("/api/admin/education-verifications/{$verification->id}/verify");

        $response->assertStatus(403);
    }

    public function test_verify_transitions_to_verified_and_sets_reviewer_and_timestamp(): void
    {
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/education-verifications/{$verification->id}/verify");

        $response->assertStatus(200)->assertJsonPath('data.status', 'verified');
        $fresh = $verification->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame($admin->id, $fresh->reviewed_by_admin_id);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertNull($fresh->rejection_reason);
    }

    public function test_reject_requires_a_rejection_reason(): void
    {
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/education-verifications/{$verification->id}/reject", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['rejection_reason']);
        $this->assertSame('pending', $verification->fresh()->status);
    }

    public function test_reject_transitions_to_rejected_with_reason_reviewer_and_timestamp(): void
    {
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/education-verifications/{$verification->id}/reject", [
            'rejection_reason' => 'Document is unreadable.',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.status', 'rejected');
        $fresh = $verification->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Document is unreadable.', $fresh->rejection_reason);
        $this->assertSame($admin->id, $fresh->reviewed_by_admin_id);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_repeated_verify_on_an_already_verified_row_is_a_safe_conflict(): void
    {
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/education-verifications/{$verification->id}/verify")->assertStatus(200);

        $response = $this->putJson("/api/admin/education-verifications/{$verification->id}/verify");

        $response->assertStatus(409);
        $this->assertSame('verified', $verification->fresh()->status);
    }

    public function test_repeated_reject_on_an_already_rejected_row_is_a_safe_conflict(): void
    {
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/education-verifications/{$verification->id}/reject", [
            'rejection_reason' => 'First reason.',
        ])->assertStatus(200);

        $response = $this->putJson("/api/admin/education-verifications/{$verification->id}/reject", [
            'rejection_reason' => 'Second reason.',
        ]);

        $response->assertStatus(409);
        $this->assertSame('First reason.', $verification->fresh()->rejection_reason);
    }

    public function test_rejecting_an_already_verified_row_does_not_undo_the_verification(): void
    {
        $admin = $this->admin();
        $verification = $this->verificationFor($this->studentWithProfile(), 'pending');
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/education-verifications/{$verification->id}/verify")->assertStatus(200);

        $response = $this->putJson("/api/admin/education-verifications/{$verification->id}/reject", [
            'rejection_reason' => 'Too late.',
        ]);

        $response->assertStatus(409);
        $fresh = $verification->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertNull($fresh->rejection_reason);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function verificationFor(object $student, string $status): EducationVerification
    {
        return $student->profile->educationVerification()->create([
            'institution_name' => 'State University',
            'degree_or_program' => 'BSc Computer Science',
            'document_path' => "education-verifications/{$student->profile->id}/proof.pdf",
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    private function studentWithProfile(?string $email = null): object
    {
        $user = User::factory()->create(array_filter([
            'role' => 'student',
            'status' => 'active',
            'email' => $email,
        ]));
        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }
}
