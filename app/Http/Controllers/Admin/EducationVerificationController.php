<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectEducationVerificationRequest;
use App\Models\EducationVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 8B-1: Admin review of Student education-proof submissions.
 * "Verified" means the platform Admin reviewed the uploaded document and
 * approved it -- not university/government/cryptographic verification
 * (see docs/BUSINESS_RULES.md). Kept minimal on purpose, mirroring
 * `Admin\SkillSuggestionController`: no bulk actions, no delete, no
 * silent re-review of an already-final state.
 */
class EducationVerificationController extends Controller
{
    private const DISK = 'local';

    /**
     * Every submission, pending ones first (oldest-submitted first within
     * that group, so the review queue is worked in submission order),
     * then already-reviewed ones. Includes the student's identity
     * (`studentProfile.user`) so an Admin never has to cross-reference a
     * bare student ID.
     */
    public function index(): JsonResponse
    {
        $verifications = EducationVerification::with('studentProfile.user')
            ->orderByRaw("status = 'pending' desc")
            ->orderBy('submitted_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Education verifications retrieved successfully',
            'data' => $verifications,
        ]);
    }

    public function show(EducationVerification $verification): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Education verification retrieved successfully',
            'data' => $verification->load('studentProfile.user'),
        ]);
    }

    /**
     * Streams the document for Admin review. Admin-only (already gated by
     * the `role:admin` middleware group) -- no ownership check needed
     * beyond that, since any Admin may review any submission.
     */
    public function document(EducationVerification $verification): JsonResponse|StreamedResponse
    {
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
     * Approves the submission. Never silently overwrites an
     * already-reviewed one (409) -- the same controlled-conflict pattern
     * `Admin\SkillSuggestionController::approve()`/`reject()` already use.
     */
    public function verify(EducationVerification $verification, Request $request): JsonResponse
    {
        if ($verification->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This education verification has already been reviewed.',
                'data' => $verification,
            ], 409);
        }

        $verification->update([
            'status' => 'verified',
            'reviewed_at' => now(),
            'reviewed_by_admin_id' => $request->user()->id,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Education verification approved successfully',
            'data' => $verification->fresh('studentProfile.user'),
        ]);
    }

    public function reject(RejectEducationVerificationRequest $request, EducationVerification $verification): JsonResponse
    {
        if ($verification->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This education verification has already been reviewed.',
                'data' => $verification,
            ], 409);
        }

        $verification->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by_admin_id' => $request->user()->id,
            'rejection_reason' => $request->validated('rejection_reason'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Education verification rejected successfully',
            'data' => $verification->fresh('studentProfile.user'),
        ]);
    }
}
