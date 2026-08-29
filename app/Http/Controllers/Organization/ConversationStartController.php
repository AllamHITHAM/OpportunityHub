<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StartConversationRequest;
use App\Models\Opportunity;
use App\Models\StudentProfile;
use App\Services\MessagingService;
use Illuminate\Http\JsonResponse;

/**
 * Messaging MVP -- the one place a new Conversation can ever be created.
 * Organization-only (a Student never initiates, only replies -- section
 * 20 of the phase spec); the shared `ConversationController` handles
 * everything else (list/show/send) for both roles.
 */
class ConversationStartController extends Controller
{
    public function __construct(private readonly MessagingService $messaging)
    {
    }

    /**
     * Starts (or, per the deterministic reuse rule, reuses) a
     * Conversation between the authenticated Organization and
     * [$student] about `opportunity_id`.
     *
     * Checks in order:
     * 1. The Opportunity must belong to the authenticated Organization --
     *    `404` otherwise (never `403`, the same "don't confirm another
     *    organization's resource exists" convention every other
     *    Organization-owned-resource endpoint in this API already uses).
     * 2. The Organization must have a legitimate recruiting reason to
     *    message this Student about this Opportunity
     *    (`MessagingService::canOrganizationMessageStudent()`) -- `403`
     *    otherwise, a genuine authorization failure distinct from "not
     *    found" since the Opportunity itself is real and owned.
     */
    public function start(StudentProfile $student, StartConversationRequest $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        $opportunity = Opportunity::where('id', $request->validated('opportunity_id'))
            ->where('organization_id', $organizationId)
            ->first();

        if ($opportunity === null) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        if (! $this->messaging->canOrganizationMessageStudent($opportunity, $student)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have a recruiting relationship with this candidate for this opportunity yet.',
                'data' => null,
            ], 403);
        }

        $conversation = $this->messaging->startOrReuseConversation(
            $request->user()->organizationProfile,
            $student,
            $opportunity,
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversation ready',
            'data' => ['conversation_id' => $conversation->id],
        ], 201);
    }
}
