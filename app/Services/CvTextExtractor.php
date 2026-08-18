<?php

namespace App\Services;

use App\Exceptions\CvTextExtractionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Phase 8A-5: deterministic, local, non-AI plain-text extraction from a
 * managed CV PDF (`smalot/pdfparser` -- pure PHP, no external executable,
 * no OCR). Purely mechanical: no skill identification, no summarization,
 * no AI/LLM calls, no model reads or writes. The extracted text is only
 * ever handed back to the caller (`Student\CVController::store()`, the
 * one place this service is used) -- this class never decides what
 * happens to it.
 */
class CvTextExtractor
{
    private const DISK = 'local';

    /**
     * Matches only a path this application itself generates at upload
     * time (see `CVController::store()`): `cvs/{student_id}/{uuid}.pdf`.
     * In practice this service is only ever called with such a path --
     * never client input -- but this guard is defense in depth: it can
     * never be coerced into reading an arbitrary filesystem path even if
     * a future caller passed the wrong string.
     */
    private const MANAGED_PATH_PATTERN = '#^cvs/\d+/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#';

    /**
     * Extracts and minimally normalizes the plain text of the managed PDF
     * at [relativePath] on the private `local` disk.
     *
     * Returns an empty string when the PDF is structurally valid but has
     * no extractable text (e.g. a scanned/image-only PDF, which this
     * phase deliberately does not OCR) -- a normal, expected outcome,
     * never a thrown exception.
     *
     * @throws CvTextExtractionException if [relativePath] isn't a managed
     *   upload path, the file doesn't exist on disk, or the PDF itself
     *   cannot be opened at all (corrupt or encrypted/password-protected).
     */
    public function extract(string $relativePath): string
    {
        if (! preg_match(self::MANAGED_PATH_PATTERN, $relativePath)) {
            throw new CvTextExtractionException('Not a managed CV upload path.');
        }

        if (! Storage::disk(self::DISK)->exists($relativePath)) {
            throw new CvTextExtractionException('CV file not found on disk.');
        }

        $absolutePath = Storage::disk(self::DISK)->path($relativePath);

        try {
            $document = (new Parser())->parseFile($absolutePath);
            $text = $document->getText();
        } catch (Throwable $e) {
            Log::warning('CV text extraction failed', [
                'path' => $relativePath,
                'exception' => $e->getMessage(),
            ]);

            throw new CvTextExtractionException('Could not parse the PDF file.', $e);
        }

        return $this->normalize($text);
    }

    /**
     * Minimal, non-destructive normalization: unify line endings, collapse
     * runs of 3+ blank lines down to one, trim only the outer whitespace.
     * Deliberately does not lowercase, strip punctuation, or otherwise
     * reshape the text -- a later AI-extraction phase needs it as close to
     * the source as possible.
     */
    private function normalize(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = preg_replace('/\n{3,}/', "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }
}
