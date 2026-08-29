<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Conversation;
use App\Models\Invitation;
use App\Models\Message;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;

/**
 * Messaging MVP -- the single backend authority for who may start a
 * Conversation, the deterministic reuse rule, and sending/reading
 * messages. Deliberately separate from `OpportunityEligibilityService`
 * (composed, not extended): eligibility answers "can this Student apply
 * to/be recommended for this Opportunity", messaging answers a related
 * but distinct question, "does the Organization have a legitimate
 * recruiting reason to open a conversation with this Student about this
 * Opportunity" -- the latter is satisfied by either Application,
 * Invitation, or Recommended-Candidates eligibility, so it is a strict
 * superset, not a re-implementation, of the former.
 *
 * No WebSockets/Reverb, no realtime push -- every conversation/message
 * read here is a plain synchronous DB read, matching this project's
 * existing "no queue/job/event for a local, low-volume concern" doctrine
 * (see `NotificationService`'s own doc comment).
 */
class MessagingService
{
    public function __construct(
        private readonly OpportunityEligibilityService $eligibility,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Whether the Organization that owns [$opportunity] has a legitimate
     * recruiting reason to message [$student] about it -- checked before
     * every conversation start, never trusted from the client. One of:
     *
     * - A real `Application` already exists for this Student/Opportunity
     *   pair (Application context).
     * - A real `Invitation` already exists for this Student/Opportunity
     *   pair (Invitation context).
     * - The Student is currently eligible to be recommended for this
     *   Opportunity (`OpportunityEligibilityService`'s three
     *   Recommended-Candidates gates: type interest, major, location) --
     *   the same real check `Organization\OpportunityRecommendationController`
     *   already uses to decide who is shown at all.
     *
     * [$opportunity] is assumed already ownership-checked by the caller
     * (belongs to the Organization in question) -- this method only
     * answers the *relationship* question, not the *ownership* one.
     */
    public function canOrganizationMessageStudent(Opportunity $opportunity, StudentProfile $student): bool
    {
        $hasApplication = Application::where('opportunity_id', $opportunity->id)
            ->where('student_id', $student->id)
            ->exists();
        if ($hasApplication) {
            return true;
        }

        $hasInvitation = Invitation::where('opportunity_id', $opportunity->id)
            ->where('student_id', $student->id)
            ->exists();
        if ($hasInvitation) {
            return true;
        }

        return $this->eligibility->isTypeInterestEligible($opportunity, $student)
            && $this->eligibility->isStudentEligible($opportunity, $student)
            && $this->eligibility->isLocationEligible($opportunity, $student);
    }

    /**
     * The deterministic reuse rule (section 19 of the phase spec): the
     * same real Organization + Student + Opportunity triple always
     * resolves to the same `Conversation` row -- `firstOrCreate` against
     * the table's own `unique(organization_id, student_id, opportunity_id)`
     * constraint, never a duplicate conversation for a repeated "Message
     * Candidate" tap. `application_id` is opportunistically attached (if
     * a real Application already exists for this pair) purely as extra
     * context for the UI -- it is never part of the identity key, since a
     * Student may legitimately be messaged before ever applying.
     */
    public function startOrReuseConversation(
        OrganizationProfile $organization,
        StudentProfile $student,
        Opportunity $opportunity,
    ): Conversation {
        $applicationId = Application::where('opportunity_id', $opportunity->id)
            ->where('student_id', $student->id)
            ->value('id');

        return Conversation::firstOrCreate(
            [
                'organization_id' => $organization->id,
                'student_id' => $student->id,
                'opportunity_id' => $opportunity->id,
            ],
            ['application_id' => $applicationId],
        );
    }

    /**
     * Creates one real Message and notifies the *other* party exactly
     * once (Phase spec section 27) -- never the sender. [$sender] is
     * always the authenticated session's own `User`, passed in by the
     * controller, never derived from request input.
     */
    public function sendMessage(Conversation $conversation, User $sender, string $body): Message
    {
        $message = $conversation->messages()->create([
            'sender_user_id' => $sender->id,
            'body' => $body,
        ]);

        // Keeps the conversation list's own "most recent activity"
        // ordering accurate without a separate denormalized column.
        $conversation->touch();

        $conversation->loadMissing(['organizationProfile.user', 'studentProfile.user']);
        $isOrganizationSender = $sender->id === $conversation->organizationProfile->user->id;
        $recipientIsStudent = $isOrganizationSender;
        $recipientUser = $recipientIsStudent
            ? $conversation->studentProfile->user
            : $conversation->organizationProfile->user;
        $senderDisplayName = $isOrganizationSender
            ? $conversation->organizationProfile->organization_name
            : $conversation->studentProfile->user->name;

        $this->notifications->notifyNewMessage($recipientUser, $senderDisplayName, $conversation, $recipientIsStudent);

        return $message;
    }

    /**
     * Marks every unread message *from the other party* as read -- called
     * once when the recipient opens the conversation
     * (`ConversationController::show()`). Never marks the viewer's own
     * sent messages as read (those were never unread to begin with, from
     * their own point of view).
     */
    public function markIncomingMessagesRead(Conversation $conversation, int $viewingUserId): void
    {
        $conversation->messages()
            ->where('sender_user_id', '!=', $viewingUserId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * The real count of messages from the other party that [$viewingUserId]
     * has not yet read -- powers both the conversation list's unread
     * badge and the single-conversation unread indicator. Never a
     * fabricated/estimated number.
     */
    public function unreadCountFor(Conversation $conversation, int $viewingUserId): int
    {
        return $conversation->messages()
            ->where('sender_user_id', '!=', $viewingUserId)
            ->whereNull('read_at')
            ->count();
    }
}
