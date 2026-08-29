<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendMessageRequest;
use App\Models\Conversation;
use App\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Messaging MVP -- the one shared Conversation resource both an
 * Organization and a Student reach through the exact same URLs
 * (`GET /api/conversations`, `GET /api/conversations/{conversation}`,
 * `POST /api/conversations/{conversation}/messages`), registered under
 * plain `auth:sanctum, active` (no `role:` middleware) since either role
 * is a legitimate caller here -- ownership is instead checked per-request
 * against whichever side of the Conversation the authenticated user's
 * own `role` puts them on. This keeps the messaging surface a single,
 * non-duplicated implementation rather than two near-identical
 * Organization/Student controllers, per this phase's own "keep one
 * shared Conversation resource where possible" instruction.
 *
 * Starting a *new* conversation is a separate, Organization-only concern
 * (`Organization\ConversationStartController`) -- a Student never
 * initiates, only replies to a conversation an Organization already
 * started (section 20 of the phase spec).
 */
class ConversationController extends Controller
{
    public function __construct(private readonly MessagingService $messaging)
    {
    }

    /**
     * The authenticated user's own conversations, most recently active
     * first -- an Organization sees every conversation belonging to its
     * `organizationProfile`, a Student sees every conversation belonging
     * to their own `studentProfile`. Each row carries the real other
     * party's name, the real Opportunity title (when set), the real last
     * message preview, and the real unread count for *this* viewer --
     * never a fabricated preview or badge count.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Conversation::query()
            ->with(['organizationProfile', 'studentProfile.user', 'opportunity:id,title'])
            ->with(['latestMessage'])
            ->orderByDesc('updated_at');

        if ($user->role === 'organization') {
            $organizationId = $user->organizationProfile?->id;
            if ($organizationId === null) {
                return response()->json(['success' => true, 'message' => 'Conversations retrieved successfully', 'data' => []]);
            }
            $query->where('organization_id', $organizationId);
        } elseif ($user->role === 'student') {
            $studentId = $user->studentProfile?->id;
            if ($studentId === null) {
                return response()->json(['success' => true, 'message' => 'Conversations retrieved successfully', 'data' => []]);
            }
            $query->where('student_id', $studentId);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'This action is unauthorized for your account type',
                'data' => null,
            ], 403);
        }

        $conversations = $query->get();

        $data = $conversations->map(fn (Conversation $conversation) => $this->summarize($conversation, $user));

        return response()->json([
            'success' => true,
            'message' => 'Conversations retrieved successfully',
            'data' => $data,
        ]);
    }

    /**
     * One conversation's full message history, chronological order --
     * and, as the one real side effect of *reading* a conversation, marks
     * every unread message from the other party as read (never the
     * viewer's own messages). `404` for a conversation that exists but
     * doesn't belong to the authenticated user, the same
     * "not found" convention (never `403`, which would confirm it
     * exists) every other owner-scoped resource in this API already
     * uses.
     */
    public function show(Conversation $conversation, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->belongsToUser($conversation, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
                'data' => null,
            ], 404);
        }

        $this->messaging->markIncomingMessagesRead($conversation, $user->id);

        $conversation->load([
            'organizationProfile',
            'studentProfile.user',
            'opportunity:id,title',
            'messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation retrieved successfully',
            'data' => [
                ...$this->summarize($conversation, $user, includeUnread: false),
                'messages' => $conversation->messages->map(fn ($message) => [
                    'id' => $message->id,
                    'sender_user_id' => $message->sender_user_id,
                    'is_own_message' => $message->sender_user_id === $user->id,
                    'body' => $message->body,
                    'read_at' => $message->read_at?->toIso8601String(),
                    'created_at' => $message->created_at->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Sends one real message. `sender_user_id` is always
     * `$request->user()->id` -- never trusted from the request body (the
     * request shape doesn't even accept one, see `SendMessageRequest`).
     * `404` for a conversation the authenticated user doesn't belong to,
     * same convention as `show()`.
     */
    public function storeMessage(Conversation $conversation, SendMessageRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->belongsToUser($conversation, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation not found',
                'data' => null,
            ], 404);
        }

        $message = $this->messaging->sendMessage($conversation, $user, $request->validated('body'));

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully',
            'data' => [
                'id' => $message->id,
                'sender_user_id' => $message->sender_user_id,
                'is_own_message' => true,
                'body' => $message->body,
                'read_at' => null,
                'created_at' => $message->created_at->toIso8601String(),
            ],
        ], 201);
    }

    private function belongsToUser(Conversation $conversation, $user): bool
    {
        if ($user->role === 'organization') {
            return $conversation->organization_id === $user->organizationProfile?->id;
        }

        if ($user->role === 'student') {
            return $conversation->student_id === $user->studentProfile?->id;
        }

        return false;
    }

    private function summarize(Conversation $conversation, $user, bool $includeUnread = true): array
    {
        $isOrganizationViewer = $user->role === 'organization';

        $otherPartyName = $isOrganizationViewer
            ? $conversation->studentProfile->user->name
            : $conversation->organizationProfile->organization_name;

        $latest = $conversation->latestMessage;

        $summary = [
            'id' => $conversation->id,
            'opportunity' => $conversation->opportunity
                ? ['id' => $conversation->opportunity->id, 'title' => $conversation->opportunity->title]
                : null,
            'application_id' => $conversation->application_id,
            'other_party_name' => $otherPartyName,
            // Only ever the Student's own `student_profiles.id` -- what
            // `POST /organization/candidates/{student}/conversation` and
            // every other Organization-facing Candidate endpoint already
            // expect as `{student}`.
            'student_id' => $conversation->student_id,
            'last_message' => $latest ? [
                'body' => $latest->body,
                'created_at' => $latest->created_at->toIso8601String(),
                'is_own_message' => $latest->sender_user_id === $user->id,
            ] : null,
            'updated_at' => $conversation->updated_at->toIso8601String(),
        ];

        if ($includeUnread) {
            $summary['unread_count'] = $this->messaging->unreadCountFor($conversation, $user->id);
        }

        return $summary;
    }
}
