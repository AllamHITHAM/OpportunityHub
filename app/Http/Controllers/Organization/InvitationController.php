<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreInvitationRequest;
use App\Models\Application;
use App\Models\Invitation;
use App\Models\Opportunity;
use App\Models\StudentProfile;
use App\Services\NotificationService;
use App\Services\OpportunityEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Organization-driven invitations to apply (Phase 8B-3, Flow B). Sending
 * an invitation never creates an `Application` and never touches
 * `MatchingService` -- it only ever inserts one `Invitation` row, then
 * (as of Phase 8B-3.1) notifies the Student both in-app and by email via
 * `NotificationService::notifyInvitationReceived()`. See
 * `Student\InvitationController::accept()` for where Flow B eventually
 * converges into the exact same Application pipeline Flow A already uses.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly OpportunityEligibilityService $eligibility,
    ) {
    }

    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $opportunity = Opportunity::with('eligibleMajorRecords')
            ->where('id', $request->validated('opportunity_id'))
            ->where('organization_id', $organizationId)
            ->first();

        if ($opportunity === null) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        if ($opportunity->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'This opportunity is not open for invitations',
                'data' => null,
            ], 422);
        }

        $studentProfile = StudentProfile::with('user')
            ->where('id', $request->validated('student_id'))
            ->first();

        if ($studentProfile === null || $studentProfile->user?->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Student not found',
                'data' => null,
            ], 404);
        }

        $alreadyApplied = Application::where('opportunity_id', $opportunity->id)
            ->where('student_id', $studentProfile->id)
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'success' => false,
                'message' => 'This student has already applied to this opportunity',
                'data' => null,
            ], 409);
        }

        // Phase 8B-3.2: the same rule `Student\ApplicationController::store()`
        // enforces for a direct Apply -- an Organization can never invite
        // (or, via that other guard, admit) a Student whose major isn't
        // eligible for this Opportunity, and there's no way to bypass it
        // by calling the API directly.
        if (! $this->eligibility->isStudentEligible($opportunity, $studentProfile)) {
            return response()->json([
                'success' => false,
                'message' => 'Student major is not eligible for this opportunity',
                'data' => null,
            ], 422);
        }

        try {
            $invitation = Invitation::create([
                'opportunity_id' => $opportunity->id,
                'student_id' => $studentProfile->id,
                'message' => $request->validated('message'),
            ]);
            // `status` has no fillable/PHP-side default -- only the
            // database schema default ('pending') -- so the in-memory
            // instance `create()` returns doesn't carry it until refreshed.
            $invitation->refresh();
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'An invitation already exists for this student and opportunity',
                'data' => null,
            ], 409);
        }

        $this->notifications->notifyInvitationReceived(
            $studentProfile->user,
            $request->user()->organizationProfile->organization_name,
            $opportunity->title,
            $request->validated('message'),
        );

        $invitation->load(['opportunity:id,title', 'studentProfile:id,user_id,university,major', 'studentProfile.user:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Invitation sent successfully',
            'data' => $invitation,
        ], 201);
    }
}
