<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateApplicationStatusRequest;
use App\Models\Application;
use App\Models\Opportunity;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $applications = Application::whereHas('opportunity', function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        })->with(['opportunity', 'studentProfile.user', 'cv'])
            ->orderByRaw($this->matchScoreRankingOrder())
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Applications retrieved successfully',
            'data' => $applications,
        ]);
    }

    public function indexForOpportunity(Opportunity $opportunity, Request $request): JsonResponse
    {
        if ($opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $applications = $opportunity->applications()->with(['studentProfile.user', 'cv'])
            ->orderByRaw($this->matchScoreRankingOrder())
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Applications retrieved successfully',
            'data' => $applications,
        ]);
    }

    /**
     * Ranking for organization-facing applicant lists (Phase 8A-1):
     * calculated scores first (highest first), null (not-yet-calculated)
     * scores always last, `applied_at` ascending as the deterministic
     * tie-breaker within each group (earliest applicant first). Never
     * applied to any student-facing endpoint or ordering.
     *
     * `match_score IS NULL` evaluates to `0`/`1` identically on both this
     * project's runtime driver (MySQL/MariaDB) and its test driver
     * (SQLite) -- ordering ascending on that expression puts every
     * non-null row (`0`) before every null row (`1`) on both, without
     * relying on driver-specific `NULLS LAST` syntax this project doesn't
     * universally support.
     */
    private function matchScoreRankingOrder(): string
    {
        return 'match_score IS NULL ASC, match_score DESC, applied_at ASC';
    }

    public function show(Application $application, Request $request): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Application retrieved successfully',
            'data' => $application->load(['opportunity', 'studentProfile.user', 'cv']),
        ]);
    }

    public function updateStatus(UpdateApplicationStatusRequest $request, Application $application): JsonResponse
    {
        if ($application->opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
                'data' => null,
            ], 404);
        }

        if ($application->status === 'withdrawn') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot change the status of a withdrawn application',
                'data' => null,
            ], 409);
        }

        // Phase 6C-4: once an Offer exists, the Application/Offer lifecycle
        // is owned entirely by OfferService (sendOffer/acceptOffer/
        // declineOffer) -- this generic endpoint must never independently
        // move the Application again, in either direction. Without this
        // guard an organization could, e.g., reject an application whose
        // Offer is still `sent`, producing the impossible combination
        // Offer.status=sent + Application.status=rejected. v1 has no Offer
        // cancel/rescind workflow, so this is a hard block, not a
        // conditional one -- see docs/BUSINESS_RULES.md section 7b.
        if ($application->offer()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This application already has an offer; its status can only change through the offer accept/decline endpoints.',
                'data' => null,
            ], 409);
        }

        $previousStatus = $application->status;
        $newStatus = $request->validated('status');

        // Phase 7A-2: notification is transition-based, not request-based —
        // a request that re-sets the application's already-current status
        // (e.g. shortlisted -> shortlisted) must never create a duplicate
        // notification. Only `shortlisted`/`rejected` have a defined
        // student-facing event; `reviewed` has none. Wrapped in the same
        // transaction as the status mutation so a Notification failure
        // rolls back the status change too (see NotificationService's own
        // doc comment on why that's acceptable for a local DB insert).
        DB::transaction(function () use ($application, $newStatus, $previousStatus) {
            $application->status = $newStatus;
            $application->reviewed_at = now();
            $application->save();

            if ($newStatus === $previousStatus) {
                return;
            }

            if ($newStatus === 'shortlisted') {
                $this->notifications->notifyApplicationShortlisted(
                    $application->studentProfile->user,
                    $application->opportunity->title,
                    $application->id,
                );
            } elseif ($newStatus === 'rejected') {
                // This is the only place a generic "Application Update"
                // rejection notification is ever created. An Offer decline
                // (OfferService::declineOffer()) also lands the Application
                // on `rejected`, but that path is structurally unreachable
                // from here: once an Offer exists, the guard above (line
                // ~92) already blocks this entire endpoint with a 409
                // before this transaction ever starts. The two rejection
                // notifications (this one, and notifyOfferDeclined() to the
                // organization) can therefore never both fire for the same
                // application.
                $this->notifications->notifyApplicationRejected(
                    $application->studentProfile->user,
                    $application->opportunity->title,
                    $application->id,
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Application status updated successfully',
            'data' => $application->fresh(['opportunity', 'studentProfile.user', 'cv']),
        ]);
    }
}
