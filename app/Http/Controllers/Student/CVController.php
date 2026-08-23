<?php

namespace App\Http\Controllers\Student;

use App\Exceptions\AiSkillExtractionException;
use App\Exceptions\CvTextExtractionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreCVRequest;
use App\Http\Requests\Student\UpdateCVRequest;
use App\Models\CV;
use App\Services\AiSkillExtractionService;
use App\Services\CvDocumentClassifierService;
use App\Services\CvTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CVController extends Controller
{
    /**
     * The disk CV files are stored on -- `local`'s root is
     * `storage/app/private` (see config/filesystems.php), never the public
     * web root, so a file is only ever reachable through an authenticated,
     * ownership-checked controller action (this class / the Organization
     * CV download action), never a direct/guessable URL.
     */
    private const DISK = 'local';

    /**
     * Phase 8A-6.2: below this many characters of trimmed `parsed_text`,
     * a document is treated as not having enough readable text to be
     * worth an AI classification/extraction call at all. Deliberately
     * very low -- this is only a cheap pre-filter for genuinely
     * near-empty PDFs (e.g. a stray watermark or a single heading); the
     * real "is this actually a CV" judgment is CvDocumentClassifierService
     * below, not this length check.
     */
    private const MIN_READABLE_TEXT_LENGTH = 20;

    public function __construct(private readonly CvTextExtractor $textExtractor)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $cvs = $request->user()->studentProfile->cvs;

        return response()->json([
            'success' => true,
            'message' => 'CVs retrieved successfully',
            'data' => $cvs,
        ]);
    }

    /**
     * Phase 8A-4: real multipart PDF upload. The client never chooses the
     * stored path/filename -- a fresh, random server filename is generated
     * (never the original upload's filename), so nothing about the request
     * can influence where or under what name the file lands on disk.
     *
     * Phase 8A-5: after the file is stored, its text is extracted
     * deterministically (see CvTextExtractor) and persisted as
     * `parsed_text`. Extraction is best-effort and never blocks CV
     * creation -- the file already passed Laravel's own `mimes:pdf`
     * validation before reaching here, so a parse failure at this point
     * (a scanned/image-only PDF with no text, or a corrupt/encrypted PDF
     * that still identified as a PDF) is purely an internal processing
     * outcome, not a reason to reject an otherwise-valid upload. Either
     * case simply leaves `parsed_text` null -- see CvTextExtractor's own
     * doc comment on why this phase doesn't need a separate status flag
     * to distinguish them.
     */
    public function store(StoreCVRequest $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        $filename = Str::uuid()->toString().'.pdf';

        $storedPath = $request->file('file')->storeAs(
            "cvs/{$studentProfile->id}",
            $filename,
            self::DISK,
        );

        abort_unless($storedPath !== false, 500, 'Failed to store the CV file.');

        $parsedText = $this->extractParsedText($storedPath);

        try {
            $cv = $studentProfile->cvs()->create([
                'title' => $request->validated('title'),
                'file_path' => $storedPath,
                'parsed_text' => $parsedText,
            ]);
        } catch (Throwable $e) {
            // The physical file was already stored -- if creating the DB
            // row fails for any reason, remove it rather than leaving an
            // orphan file no CV row will ever reference.
            Storage::disk(self::DISK)->delete($storedPath);

            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'CV created successfully',
            'data' => $cv,
        ], 201);
    }

    /**
     * Best-effort text extraction for the just-stored PDF at
     * [storedPath]. Returns null (not an empty string) both when the PDF
     * genuinely has no extractable text and when extraction itself fails
     * -- see this class's own doc comment on why this phase collapses
     * both cases to the same `parsed_text = null` outcome.
     */
    private function extractParsedText(string $storedPath): ?string
    {
        try {
            $text = $this->textExtractor->extract($storedPath);
        } catch (CvTextExtractionException) {
            return null;
        }

        return $text === '' ? null : $text;
    }

    /**
     * Phase 8A-6.2: rename only -- the PDF itself is never touched.
     * `UpdateCVRequest` recognizes only `title`, so nothing else this
     * request body might contain (`student_id`, `file_path`,
     * `parsed_text`, `version`, `is_default`, `created_by_ai`) can ever
     * reach the update. Renaming has no relationship to Delete's "used in
     * an Application" conflict rule -- a CV referenced by an Application
     * is identified by `cv_id`, never by its title, so renaming a CV that
     * already has applications is always safe and always allowed.
     */
    public function update(CV $cv, UpdateCVRequest $request): JsonResponse
    {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        $cv->update(['title' => $request->validated('title')]);

        return response()->json([
            'success' => true,
            'message' => 'CV updated successfully',
            'data' => $cv->fresh(),
        ]);
    }

    /**
     * Streams the CV's own PDF back to the student it belongs to. Serves
     * inline (not a forced download) so a browser can render it directly --
     * "View CV" in the Flutter app.
     *
     * A pre-Phase-8A-4 row may still carry a legacy, non-managed path
     * (e.g. a local Windows path typed into the old `file_path` text
     * field) -- `Storage::exists()` against that string on the `local`
     * disk will simply be false (it was never really stored here), which
     * this deliberately treats as a normal, controlled 404 rather than
     * attempting to read an arbitrary path.
     */
    public function download(CV $cv, Request $request): JsonResponse|StreamedResponse
    {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        if (! Storage::disk(self::DISK)->exists($cv->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'CV file not found',
                'data' => null,
            ], 404);
        }

        return Storage::disk(self::DISK)->response($cv->file_path, $cv->title.'.pdf');
    }

    public function destroy(CV $cv, Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        if ($cv->student_id !== $studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        if ($cv->applications()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete a CV that has been used in an application',
                'data' => null,
            ], 409);
        }

        $cv->delete();

        // Only ever deletes a path this application itself generated and
        // scoped under this CV's own owning student (see store() above) --
        // a legacy/manually-typed `file_path` never matches this prefix,
        // so it's left alone rather than risking an arbitrary filesystem
        // delete on a client-influenced (pre-Phase-8A-4) string.
        if (str_starts_with($cv->file_path, "cvs/{$studentProfile->id}/")) {
            Storage::disk(self::DISK)->delete($cv->file_path);
        }

        return response()->json([
            'success' => true,
            'message' => 'CV deleted successfully',
            'data' => null,
        ]);
    }

    public function setDefault(CV $cv, Request $request): JsonResponse
    {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        DB::transaction(function () use ($cv) {
            $cv->studentProfile->cvs()->where('id', '!=', $cv->id)->update(['is_default' => false]);
            $cv->update(['is_default' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Default CV updated successfully',
            'data' => $cv->fresh(),
        ]);
    }

    /**
     * Phase 8A-6: AI CV Skill Extraction. Suggestion-only -- returns
     * transient structured skill suggestions derived from this CV's
     * already-extracted text (parsed_text, Phase 8A-5). Never mutates the
     * Student's skills, never touches match_score, and never returns
     * parsed_text itself. Accepting a suggestion is a separate, explicit
     * client action against the existing Student Skill endpoint
     * (StudentSkillController::store) -- this action never writes
     * anything.
     *
     * Phase 8A-6.2: two validation gates now run before extraction, in
     * order --
     *   1. a deterministic readable-text-length check (below
     *      MIN_READABLE_TEXT_LENGTH is treated as "not enough text to
     *      analyze", regardless of whether it's literally empty or just
     *      near-empty);
     *   2. a bounded AI classification (CvDocumentClassifierService)
     *      confirming the text actually reads as a CV/resume, so a
     *      lecture chapter, article, or unrelated PDF that merely
     *      mentions technical terms can never have skills attributed to
     *      the student from it.
     * Neither gate ever reaches AiSkillExtractionService (so no
     * CvSkillEvidence/SkillSuggestion is ever written) unless both pass. A
     * classifier-provider failure is a 503 ("temporarily unavailable"),
     * exactly like an extraction-provider failure -- never reported as
     * "not a CV".
     *
     * Phase 8A-6.3: an atomic per-CV lock (409, "already being analyzed")
     * guards the entire gate+extraction flow against a concurrent request
     * for the same CV, and both the classification and extraction HTTP
     * calls now retry a transient provider outcome with backoff (see
     * App\Services\Concerns\RetriesTransientAiProviderCalls) instead of
     * failing on the very first hiccup.
     */
    public function extractSkills(
        CV $cv,
        Request $request,
        AiSkillExtractionService $service,
        CvDocumentClassifierService $classifier,
    ): JsonResponse {
        if ($cv->student_id !== $request->user()->studentProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'CV not found',
                'data' => null,
            ], 404);
        }

        // Phase 8A-6.3: an atomic, cross-request lock (the `database`
        // cache store, this app's real configured driver -- safe across
        // concurrent PHP-FPM workers, unlike an in-memory-only guard) so
        // the exact same CV can never be analyzed by two overlapping
        // requests at once (e.g. two browser tabs, or a double-submit
        // that slipped past the Flutter-side button-disable guard). A
        // generous TTL covers the worst case of the retry-with-backoff
        // below (up to 3 attempts x 25s timeout + backoff) so a lock can
        // never outlive a request that genuinely crashed mid-flight.
        $lock = Cache::lock("cv-extract-skills:{$cv->id}", 90);
        if (! $lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'This CV is already being analyzed. Please wait for it to finish.',
                'data' => null,
            ], 409);
        }

        try {
            $text = trim((string) $cv->parsed_text);

            if (mb_strlen($text) < self::MIN_READABLE_TEXT_LENGTH) {
                return response()->json([
                    'success' => false,
                    'message' => "We couldn't find enough readable text in this PDF to analyze it.",
                    'data' => null,
                ], 422);
            }

            try {
                $isCv = $classifier->isCv($text);
            } catch (AiSkillExtractionException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                ], 503);
            }

            if (! $isCv) {
                return response()->json([
                    'success' => false,
                    'message' => "This document doesn't appear to be a CV or resume. Upload a CV to use AI Skill Analysis.",
                    'data' => null,
                ], 422);
            }

            try {
                $skills = $service->extractSkills($cv);
            } catch (AiSkillExtractionException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                ], 503);
            }

            return response()->json([
                'success' => true,
                'message' => 'CV skills extracted successfully',
                'data' => [
                    'skills' => $skills,
                ],
            ]);
        } finally {
            $lock->release();
        }
    }
}
