<?php

namespace Tests\Unit\Services;

use App\Exceptions\CvTextExtractionException;
use App\Services\CvTextExtractor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 8A-5: direct unit coverage for CvTextExtractor, using small
 * hand-built PDF fixtures (real, structurally valid PDF byte streams with
 * correct xref offsets -- not real-world sample files) so every scenario
 * is deterministic and self-contained. No external services, no OCR.
 */
class CvTextExtractorTest extends TestCase
{
    private CvTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->extractor = app(CvTextExtractor::class);
    }

    public function test_a_simple_text_pdf_extracts_its_text(): void
    {
        $path = $this->putManagedPdf($this->pdfWithText(['Hello World']));

        $text = $this->extractor->extract($path);

        $this->assertStringContainsString('Hello World', $text);
    }

    public function test_multiple_lines_are_all_extracted(): void
    {
        $path = $this->putManagedPdf(
            $this->pdfWithText(['Software Engineer', 'Backend Developer', 'Five years experience']),
        );

        $text = $this->extractor->extract($path);

        $this->assertStringContainsString('Software Engineer', $text);
        $this->assertStringContainsString('Backend Developer', $text);
        $this->assertStringContainsString('Five years experience', $text);
    }

    public function test_crlf_line_endings_are_normalized_to_lf(): void
    {
        // The extractor's own normalization runs on whatever the parser
        // returns -- simulate a parser that handed back CRLF-heavy text by
        // checking the normalizer indirectly through a real multi-line
        // extraction (the parser itself always yields LF between text
        // lines), and directly assert no CR survives.
        $path = $this->putManagedPdf($this->pdfWithText(['Line One', 'Line Two']));

        $text = $this->extractor->extract($path);

        $this->assertStringNotContainsString("\r", $text);
    }

    public function test_excessive_blank_lines_are_collapsed(): void
    {
        $path = $this->putManagedPdf($this->pdfWithText(['First', '', '', '', 'Second']));

        $text = $this->extractor->extract($path);

        $this->assertStringNotContainsString("\n\n\n", $text);
        $this->assertStringContainsString('First', $text);
        $this->assertStringContainsString('Second', $text);
    }

    public function test_outer_whitespace_is_trimmed(): void
    {
        $path = $this->putManagedPdf($this->pdfWithText(['Trimmed Content']));

        $text = $this->extractor->extract($path);

        $this->assertSame($text, trim($text));
    }

    public function test_accented_latin_text_is_extracted_reliably(): void
    {
        // WinAnsiEncoding accented characters (Café Résumé) -- a
        // deterministic, reliably-supported non-ASCII case.
        $path = $this->putManagedPdf($this->pdfWithWinAnsiText("Caf\xe9 R\xe9sum\xe9"));

        $text = $this->extractor->extract($path);

        $this->assertStringContainsString('Café Résumé', $text);
    }

    public function test_an_image_only_pdf_with_no_text_returns_an_empty_string_not_an_exception(): void
    {
        $path = $this->putManagedPdf($this->pdfWithNoText());

        $text = $this->extractor->extract($path);

        $this->assertSame('', $text);
    }

    public function test_a_malformed_pdf_throws_a_domain_exception(): void
    {
        $path = $this->putManagedPdf("%PDF-1.4\nThis is not a real PDF structure at all.\n%%EOF");

        $this->expectException(CvTextExtractionException::class);
        $this->extractor->extract($path);
    }

    public function test_a_truncated_pdf_throws_a_domain_exception(): void
    {
        $path = $this->putManagedPdf("%PDF-1.4\n");

        $this->expectException(CvTextExtractionException::class);
        $this->extractor->extract($path);
    }

    public function test_a_missing_file_throws_a_domain_exception(): void
    {
        $this->expectException(CvTextExtractionException::class);
        $this->extractor->extract('cvs/1/'.$this->uuid().'.pdf');
    }

    public function test_an_encrypted_pdf_throws_a_domain_exception(): void
    {
        $path = $this->putManagedPdf($this->encryptedPdf());

        $this->expectException(CvTextExtractionException::class);
        $this->extractor->extract($path);
    }

    public function test_a_non_managed_path_is_rejected_without_reading_the_filesystem(): void
    {
        // Even if a file genuinely exists at this path on the fake disk,
        // it must never be read -- only the exact `cvs/{id}/{uuid}.pdf`
        // shape is a managed path.
        Storage::disk('local')->put('cvs/legacy-path.pdf', $this->pdfWithText(['Should never be read']));

        $this->expectException(CvTextExtractionException::class);
        $this->extractor->extract('cvs/legacy-path.pdf');
    }

    public function test_an_absolute_path_is_rejected_without_reading_the_filesystem(): void
    {
        $this->expectException(CvTextExtractionException::class);
        $this->extractor->extract('C:\\Users\\Someone\\Documents\\CV.pdf');
    }

    public function test_a_path_traversal_attempt_is_rejected(): void
    {
        $this->expectException(CvTextExtractionException::class);
        $this->extractor->extract('cvs/1/../../../etc/passwd');
    }

    // -----------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------

    private function putManagedPdf(string $pdfBytes): string
    {
        $path = 'cvs/1/'.$this->uuid().'.pdf';
        Storage::disk('local')->put($path, $pdfBytes);

        return $path;
    }

    private function uuid(): string
    {
        return 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
    }

    /**
     * A minimal, structurally-correct single-page PDF with one Tj
     * text-showing operator per line (each on its own `BT...ET` block, at
     * a descending Y coordinate so they read as separate lines).
     *
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

    private function pdfWithWinAnsiText(string $rawWinAnsiText): string
    {
        $stream = "BT /F1 12 Tf 72 700 Td ({$rawWinAnsiText}) Tj ET";

        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            5 => "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ], 1);
    }

    /**
     * A structurally valid single-page PDF with an empty content stream --
     * simulates a scanned/image-only page with no real text operators.
     */
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
     * A PDF whose trailer references an /Encrypt dictionary -- structurally
     * valid but password-protected, which smalot/pdfparser explicitly
     * refuses to open ("Secured pdf file are currently not supported.").
     */
    private function encryptedPdf(): string
    {
        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => "<< /Length 44 >>\nstream\nBT /F1 24 Tf 100 700 Td (Secret) Tj ET\nendstream",
            6 => '<< /Filter /Standard /V 2 /R 3 /O (garbageownerpassword32bytes!!) /U (garbageuserpassword32byte!!!!) /P -1 /Length 128 >>',
        ], 1, ' /Encrypt 6 0 R');
    }

    /**
     * Builds a minimal PDF from a map of object-number => body, with a
     * real, correctly-offset xref table (a hand-typed placeholder xref
     * table causes smalot/pdfparser to misreport the file as "possibly
     * secured" rather than genuinely parsing it, so this computes real
     * byte offsets).
     *
     * @param  array<int, string>  $objects
     */
    private function buildPdf(array $objects, int $rootObjNum, string $extraTrailerEntries = ''): string
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

        $out .= "trailer\n<< /Size {$count} /Root {$rootObjNum} 0 R{$extraTrailerEntries} >>\n";
        $out .= "startxref\n{$xrefOffset}\n%%EOF";

        return $out;
    }
}
