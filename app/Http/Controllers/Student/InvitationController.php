<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Student side of Flow B (Phase 8B-3): listing invitations received
 * and responding to them. Student consent is the hard rule this whole
 * controller exists to enforce -- an Organization can never force an
 * Application into existence; see `accept()`'s own doc comment for
 * exactly what it does (and, just as importantly, does not do).
 */
class InvitationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $invitations = $request->user()->studentProfile->invitations()
            ->with(['opportunity:id,title,organization_id', 'opportunity.organizationProfile:id,organization_name'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Invitations retrieved successfully',
            'data' => $invitations,
        ]);
    }

    /**
     * Marks [invitation] `accepted`. Deliberately does **not** create an
     * `Application` -- `applications.cv_id` is required and no CV is
     * chosen at invitation time, so inventing one here would mean either
     * guessing a CV on the student's behalf or inserting an invalid row.
     * Instead, Flutter routes the student to the same existing Apply flow
     * (`POST /opportunities/{opportunity}/apply`) for `invitation->opportunity_id`,
     * where they pick a CV exactly as Flow A already requires -- from that
     * point on the two flows are identical: the same
     * `Student\ApplicationController::store()`, the same `MatchingService`
     * call, the same organization-facing applicant list. If the student
     * already applied independently before responding to this invitation,
     * accepting still just records their consent; no second Application is
     * attempted or needed.
     */
    public function accept(Invitation $invitation, Request $request): JsonResponse
    {
        return $this->respond($invitation, $request, 'accepted');
    }

    public function decline(Invitation $invitation, Request $request): JsonResponse
    {
        return $this->respond($invitation, $request, 'declined');
    }

    private function respond(Invitation $invitation, Request $request, string $newStatus): JsonResponse
    {
        if ($invitation->student_id !== $request->user()->studentProfile?->id) {
            return response()->json([
                'success' => false,
                'message' => 'Invitation not found',
                'data' => null,
            ], 404);
        }

        if ($invitation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This invitation has already been responded to',
                'data' => null,
            ], 409);
        }

        $invitation->status = $newStatus;
        $invitation->save();

        $invitation->load(['opportunity.organizationProfile.user']);
        $organizationUser = $invitation->opportunity->organizationProfile->user;
        $studentName = $request->user()->name;

        if ($newStatus === 'accepted') {
            $this->notifications->notifyInvitationAccepted(
                $organizationUser,
                $studentName,
                $invitation->opportunity->title,
                $invitation->opportunity_id,
            );
        } else {
            $this->notifications->notifyInvitationDeclined(
                $organizationUser,
                $studentName,
                $invitation->opportunity->title,
                $invitation->opportunity_id,
            );
        }

        return response()->json([
            'success' => true,
            'message' => $newStatus === 'accepted'
                ? 'Invitation accepted'
                : 'Invitation declined',
            'data' => $invitation,
        ]);
    }
}
