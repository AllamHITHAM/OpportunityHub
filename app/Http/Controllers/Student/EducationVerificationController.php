<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreEducationVerificationRequest;
use App\Models\EducationVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Phase 8B-1: a Student's own education-proof submission and its status.
 * "Verified" here means an Admin reviewed the document and approved it --
 * see docs/BUSINESS_RULES.md for the full trust-model statement. This
 * never blocks account registration or Applying (see the same doc).
 */
class EducationVerificationController extends Controller
{
    /**
     * Same disk as CVController -- `local`'s root is
     * `storage/app/private`, never the public web root.
     */
    private const DISK = 'local';

    /**
     * Creates the student's first submission, or resubmits after a
     * rejection. A `verified` submission cannot be replaced in v1 (409) --
     * see docs/BUSINESS_RULES.md.
     *
     * The physical file is always stored before the DB row is
     * created/updated; if that database step then fails, the just-stored
     * file is removed so it never becomes an orphan (mirrors
     * CVController::store()). On a successful resubmission, the previous
     * managed file is deleted only after the new state is safely saved.
     */
    public function store(StoreEducationVerificationRequest $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        // A fresh query, not the cached `educationVerification` relation
        // property -- this decision is freshness-critical (it gates the
        // 409 "already verified" rule), and a cached property would
        // silently return stale data if this student's profile object was
        // already touched earlier in the same request/session.
        $existing = $studentProfile->educationVerification()->first();

        if ($existing !== null && $existing->status === 'verified') {
            return response()->json([
                'success' => false,
                'message' => 'Your education has already been verified and cannot be resubmitted.',
                'data' => null,
            ], 409);
        }

        $filename = Str::uuid()->toString().'.pdf';
        $storedPath = $request->file('file')->storeAs(
            "education-verifications/{$studentProfile->id}",
            $filename,
            self::DISK,
        );

        abort_unless($storedPath !== false, 500, 'Failed to store the education verification document.');

        $previousPath = $existing?->document_path;

        $attributes = [
            'institution_name' => $request->validated('institution_name'),
            'degree_or_program' => $request->validated('degree_or_program'),
            'document_path' => $storedPath,
            'status' => 'pending',
            'rejection_reason' => null,
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by_admin_id' => null,
        ];

        try {
            if ($existing !== null) {
                $existing->update($attributes);
                $verification = $existing;
            } else {
                $verification = $studentProfile->educationVerification()->create($attributes);
            }
        } catch (Throwable $e) {
            // The physical file was already stored -- if the DB write
            // fails for any reason, remove it rather than leaving an
            // orphan file no row will ever reference.
            Storage::disk(self::DISK)->delete($storedPath);

            throw $e;
        }

        // Only after the new state is safely saved -- and only a path
        // this application itself generated, scoped under this student --
        // is the old file removed. A legacy/foreign path is left alone.
        if (
            $previousPath !== null
            && $previousPath !== $storedPath
            && str_starts_with($previousPath, "education-verifications/{$studentProfile->id}/")
        ) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Education verification submitted successfully',
            'data' => $this->studentFacingData($verification),
        ], $existing !== null ? 200 : 201);
    }

    /**
     * Returns the student's current verification state -- a controlled
     * `not_submitted` state (still 200) rather than a 404 when none exists
     * yet, since "nothing submitted" is a normal, expected state here, not
     * an error.
     */
    public function show(Request $request): JsonResponse
    {
        $verification = $request->user()->studentProfile->educationVerification()->first();

        return response()->json([
            'success' => true,
            'message' => 'Education verification status retrieved successfully',
            'data' => $verification !== null
                ? $this->studentFacingData($verification)
                : $this->notSubmittedData(),
        ]);
    }

    /**
     * Streams the student's own document back to them. No `{id}` in the
     * route at all -- a student only ever has one verification, reached
     * through their own profile, so there is no ID a student could ever
     * manipulate to reach someone else's document.
     */
    public function document(Request $request): JsonResponse|StreamedResponse
    {
        $verification = $request->user()->studentProfile->educationVerification()->first();

        if ($verification === null) {
            return response()->json([
                'success' => false,
                'message' => 'No education verification found',
                'data' => null,
            ], 404);
        }

        if (! Storage::disk(self::DISK)->exists($verification->document_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Education verification document not found',
                'data' => null,
            ], 404);
        }

        return Storage::disk(self::DISK)->response(
            $verification->document_path,
            'education-verification.pdf',
        );
    }

    /**
     * The exact fields a Student may see -- explicitly excludes
     * `document_path` (already hidden at the model level too) and
     * `reviewed_by_admin_id` (an Admin implementation detail, not
     * something a student needs).
     */
    private function studentFacingData(EducationVerification $verification): array
    {
        return [
            'institution_name' => $verification->institution_name,
            'degree_or_program' => $verification->degree_or_program,
            'status' => $verification->status,
            'rejection_reason' => $verification->rejection_reason,
            'submitted_at' => $verification->submitted_at,
            'reviewed_at' => $verification->reviewed_at,
        ];
    }

    private function notSubmittedData(): array
    {
        return [
            'institution_name' => null,
            'degree_or_program' => null,
            'status' => 'not_submitted',
            'rejection_reason' => null,
            'submitted_at' => null,
            'reviewed_at' => null,
        ];
    }
}
