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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-5: deterministic server-side PDF text extraction on upload.
 * Covers the parsing outcomes (real text, image-only/no text, malformed-
 * but-PDF-identified content), the privacy rule (parsed_text never
 * serialized to any Student/Organization response), and orphan-file
 * cleanup when CV row creation fails after the physical file is already
 * stored. `StudentCvUploadTest.php` continues to cover the Phase 8A-4
 * upload/download/delete surface unmodified by this phase.
 */
class StudentCvParsingTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Extraction outcomes
    // -----------------------------------------------------------------

    public function test_a_real_text_pdf_upload_persists_its_extracted_text(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $this->uploadRealPdf($student, 'My CV', $this->pdfWithText(['Backend Engineer', 'Five years Laravel']))
            ->assertStatus(201);

        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertNotNull($cv->parsed_text);
        $this->assertStringContainsString('Backend Engineer', $cv->parsed_text);
        $this->assertStringContainsString('Five years Laravel', $cv->parsed_text);
    }

    public function test_an_image_only_pdf_uploads_successfully_with_a_null_parsed_text(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->uploadRealPdf($student, 'Scanned CV', $this->pdfWithNoText());

        $response->assertStatus(201);
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertNull($cv->parsed_text);
        Storage::disk('local')->assertExists($cv->file_path);
    }

    public function test_a_pdf_identified_file_with_unparseable_content_still_uploads_successfully(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        // Laravel's own `mimes:pdf` validation already confirmed this is
        // (as far as the upload is concerned) a PDF -- a parser-level
        // failure on top of that must never reject the upload.
        $response = $this->post('/api/student/cvs', [
            'title' => 'Corrupt CV',
            'file' => UploadedFile::fake()->createWithContent(
                'resume.pdf',
                "%PDF-1.4\nThis is not a real PDF body structure.\n%%EOF",
            ),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertNull($cv->parsed_text);
        Storage::disk('local')->assertExists($cv->file_path);
        $this->assertDatabaseCount('cvs', 1);
    }

    // -----------------------------------------------------------------
    // Privacy: parsed_text never serialized
    // -----------------------------------------------------------------

    public function test_parsed_text_is_absent_from_the_create_response(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->uploadRealPdf($student, 'My CV', $this->pdfWithText(['Some real content']));

        $response->assertStatus(201)->assertJsonMissingPath('data.parsed_text');
    }

    public function test_parsed_text_is_absent_from_the_student_cv_list(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadRealPdf($student, 'My CV', $this->pdfWithText(['Some real content']));

        $response = $this->getJson('/api/student/cvs');

        $response->assertStatus(200)->assertJsonMissingPath('data.0.parsed_text');
    }

    public function test_parsed_text_is_absent_from_the_organization_application_response(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadRealPdf($student, 'My CV', $this->pdfWithText(['Some real content']));
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/applications/{$application->id}");

        $response->assertStatus(200)->assertJsonMissingPath('data.cv.parsed_text');
    }

    public function test_parsed_text_is_absent_from_the_organization_applicants_list(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadRealPdf($student, 'My CV', $this->pdfWithText(['Some real content']));
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->getJson("/api/organization/opportunities/{$opportunity->id}/applications");

        $response->assertStatus(200)->assertJsonMissingPath('data.0.cv.parsed_text');
    }

    // -----------------------------------------------------------------
    // Orphan-file cleanup when DB row creation fails
    // -----------------------------------------------------------------

    public function test_a_db_failure_after_storing_the_file_leaves_no_orphan_file_and_no_cv_row(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        // A SQLite trigger that deterministically fails the INSERT for one
        // specific sentinel title -- simulates a DB-layer failure (e.g. a
        // constraint violation, a lost connection) occurring after the
        // physical file has already been stored, without needing to mock
        // Eloquent/DB internals.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER force_cv_insert_failure
            BEFORE INSERT ON cvs
            WHEN NEW.title = 'FORCE_DB_FAILURE_SENTINEL'
            BEGIN
                SELECT RAISE(ABORT, 'Forced failure for test');
            END;
        SQL);

        $response = $this->post('/api/student/cvs', [
            'title' => 'FORCE_DB_FAILURE_SENTINEL',
            'file' => UploadedFile::fake()->createWithContent(
                'resume.pdf',
                $this->pdfWithText(['Irrelevant content']),
            ),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(500);
        $this->assertDatabaseCount('cvs', 0);

        $files = Storage::disk('local')->allFiles("cvs/{$student->profile->id}");
        $this->assertEmpty($files, 'The stored file must be cleaned up when CV row creation fails.');
    }

    // -----------------------------------------------------------------
    // Regression: default/version/delete/application flow unaffected
    // -----------------------------------------------------------------

    public function test_uploading_a_new_cv_does_not_change_existing_default_behavior(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $this->uploadRealPdf($student, 'First CV', $this->pdfWithText(['First']));
        $first = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertFalse($first->is_default);

        $this->uploadRealPdf($student, 'Second CV', $this->pdfWithText(['Second']));

        $this->assertDatabaseCount('cvs', 2);
        $this->assertDatabaseHas('cvs', ['id' => $first->id, 'is_default' => false]);
    }

    public function test_deleting_a_parsed_cv_removes_the_row_and_its_managed_file(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadRealPdf($student, 'My CV', $this->pdfWithText(['Real content']));
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();
        $this->assertNotNull($cv->parsed_text);

        $response = $this->deleteJson("/api/student/cvs/{$cv->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('cvs', ['id' => $cv->id]);
        Storage::disk('local')->assertMissing($cv->file_path);
    }

    public function test_a_parsed_cv_still_works_normally_in_the_application_cv_id_flow(): void
    {
        Storage::fake('local');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $this->uploadRealPdf($student, 'My CV', $this->pdfWithText(['Real content']));
        $cv = CV::where('student_id', $student->profile->id)->firstOrFail();

        $opportunity = $this->openOpportunity();

        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $cv->id,
        ]);

        $response->assertStatus(201)->assertJsonPath('data.cv_id', $cv->id);
        $this->assertDatabaseHas('applications', [
            'student_id' => $student->profile->id,
            'cv_id' => $cv->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function uploadRealPdf(object $student, string $title, string $pdfBytes)
    {
        return $this->post('/api/student/cvs', [
            'title' => $title,
            'file' => UploadedFile::fake()->createWithContent('resume.pdf', $pdfBytes),
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

    private function openOpportunity(): Opportunity
    {
        return $this->opportunityFor($this->approvedOrganization());
    }

    /**
     * @param  string[]  $lines
     */
    private function pdfWithText(array $lines): string
    {
        $y = 700;
        $stream = '';
        foreach ($lines as $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= "BT /F1 12 Tf 72 {$y} Td ({$escaped}) Tj ET\n";
            $y -= 20;
        }

        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => "<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream",
        ], 1);
    }

    private function pdfWithNoText(): string
    {
        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << >> /MediaBox [0 0 612 792] /Contents 4 0 R >>',
            4 => "<< /Length 0 >>\nstream\n\nendstream",
        ], 1);
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function buildPdf(array $objects, int $rootObjNum): string
    {
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($out);
        $count = max(array_keys($objects)) + 1;

        $out .= "xref\n0 {$count}\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        $out .= "trailer\n<< /Size {$count} /Root {$rootObjNum} 0 R >>\n";
        $out .= "startxref\n{$xrefOffset}\n%%EOF";

        return $out;
    }
}
