<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Interview;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The single backend API for creating in-app Notification rows (Phase
 * 7A-1). Every current business workflow (Application, Assessment/
 * Interview, Quiz, Offer) routes its notification-worthy events through
 * this service's convenience methods rather than calling
 * `Notification::create()` directly, the same "one service owns this
 * concern" doctrine `AssessmentService`/`OfferService` already establish
 * for their own domains.
 *
 * **Wired into the real workflow as of Phase 7A-2.**
 * `Student\ApplicationController::store()`,
 * `Organization\ApplicationController::updateStatus()`,
 * `AssessmentService::createInterviewAssessment()`,
 * `Organization\InterviewController::update()`,
 * `Organization\QuizController::publish()`,
 * `Student\QuizController::submit()`, and every `OfferService` transition
 * method now call this service — always synchronously, always inside the
 * same DB transaction as the business mutation it accompanies (see each
 * call site's own doc comment for its exact placement/transition-guard
 * logic). No Admin-facing events exist in this phase.
 *
 * No Laravel Events/Listeners/Observers/Jobs are used here — every method
 * is a plain synchronous call that inserts one row and returns it, matching
 * this project's current architecture doctrine and scale (see
 * docs/ARCHITECTURE.md). Because creation is transaction-bound, an
 * unexpected `NotificationService` failure can roll back the business
 * action it accompanies — acceptable since this is a local DB insert with
 * no external I/O.
 *
 * **Phase 10A.4A** adds two events: `notifyQuizDecisionInterview()`
 * (student-facing, queues an email — the one coherent "Assessment
 * Update — Interview Invitation" message a released "Advance to
 * Interview" decision sends, replacing what would otherwise be two
 * separate messages for the same release) and `notifyDecisionRequired()`
 * (organization-facing, in-app only — a scheduled release time passed
 * with no ready decision). `notifyQuizResultAvailable()` below is no
 * longer called from the standard release path as of this phase (every
 * release now goes through a `next_action`-specific communication
 * instead — see `QuizResultReleaseService`) but is deliberately left
 * intact, not deleted, since it remains structurally valid and its own
 * tests still exercise it directly.
 *
 * **`EmailService` collaborator (Phase 7A-4.1, extended Phase 7A-4.2).**
 * This class remains the single conceptual "which workflow event happened,
 * who does it concern" boundary — it still owns the in-app Notification's
 * copy/type/priority/action_url exactly as before. It now also decides
 * *which* of its events are important enough to also queue a transactional
 * email, delegating the actual Mailable/transport/after-commit mechanics to
 * `EmailService` (constructor-injected) rather than calling `Mail::` itself
 * — mixing SMTP transport concerns into this class would defeat the point
 * of having a separate `EmailService` at all. **Nine of fourteen events
 * queue an email as of Phase 10A.2**: `notifyOfferSent()` (the Phase
 * 7A-4.1 pilot), `notifyApplicationRejected()`, `notifyInterviewScheduled()`,
 * `notifyInterviewRescheduled()`, `notifyQuizPublished()`,
 * `notifyOfferAccepted()`, `notifyOfferDeclined()`,
 * `notifyInvitationReceived()` (Phase 8B-3.1 — the Student otherwise has
 * no reason to open the app and discover an invitation exists), and
 * `notifyQuizResultAvailable()` (Phase 10A.2 — now fires only at actual
 * release time, not at submission, so it graduated from "too frequent to
 * justify an email" to a genuinely meaningful once-per-Assessment event;
 * see that method's own doc comment). Five remain in-app only, unchanged
 * and queuing nothing: `notifyApplicationSubmitted()`,
 * `notifyApplicationShortlisted()`, `notifyQuizCompleted()` — each fires
 * too frequently per-user or isn't independently actionable to justify an
 * email — plus `notifyInvitationAccepted()`/`notifyInvitationDeclined()`
 * (Phase 8B-3, organization-facing) — each fires too infrequently per
 * organization to justify one; see docs/BUSINESS_RULES.md section 8 for
 * the full matrix and reasoning.
 * Because every `EmailService::send*Email()` method queues with
 * after-commit semantics (see `QueuedTransactionalMail`), calling one here
 * — still inside the same `DB::transaction()` as the business mutation it
 * accompanies — does not carry the "an unexpected failure rolls back the
 * business action" risk the in-app Notification insert above still has: a
 * queue-dispatch failure raises immediately (see `EmailService`'s own doc
 * comment on why that's deliberate), but the actual SMTP send itself never
 * runs until well after this transaction has already committed, and its
 * failure is isolated to the queued job (see docs/BUSINESS_RULES.md
 * section 8).
 */
class NotificationService
{
    public function __construct(private readonly EmailService $emails)
    {
    }

    /**
     * Mirrors the `notifications.type` enum exactly (see the
     * `2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`
     * migration) — kept here so an invalid value fails with a clear
     * exception before ever reaching the database, rather than surfacing as
     * an opaque `QueryException`.
     */
    private const ALLOWED_TYPES = [
        'system',
        'application',
        'interview',
        'assessment',
        'offer',
        'organization',
        'opportunity',
        'message',
    ];

    /**
     * Mirrors the `notifications.priority` enum exactly — unchanged by
     * Phase 7A-1 (no new priority value was needed for the current event
     * matrix; see docs/BUSINESS_RULES.md).
     */
    private const ALLOWED_PRIORITIES = ['low', 'normal', 'high'];

    /**
     * Creates one Notification row for [$user]. The generic API every
     * convenience method below ultimately calls.
     *
     * Deliberately never accepts `is_read`/`read_at`/`sent_at` — those stay
     * the model/database's own concern (`is_read` defaults `false` at the
     * schema level, `read_at` starts `null`, `sent_at` is
     * `useCurrent()`-defaulted), exactly matching how
     * `NotificationController::markAsRead()` is the only place that ever
     * sets `is_read`/`read_at` today.
     *
     * @throws InvalidArgumentException  When [$type] or [$priority] isn't
     *                                    one of the enum's real values —
     *                                    checked here so a typo in a caller
     *                                    fails immediately and legibly
     *                                    instead of as a raw database enum
     *                                    error.
     */
    public function create(
        User $user,
        string $title,
        string $message,
        string $type = 'system',
        string $priority = 'normal',
        ?string $actionUrl = null,
    ): Notification {
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Invalid notification type: \"{$type}\".");
        }

        if (! in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            throw new InvalidArgumentException("Invalid notification priority: \"{$priority}\".");
        }

        return Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'priority' => $priority,
            'action_url' => $actionUrl,
        ]);
    }

    // -----------------------------------------------------------------
    // Application
    // -----------------------------------------------------------------

    /**
     * Organization-facing: a student submitted a new application.
     */
    public function notifyApplicationSubmitted(
        User $organizationUser,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $organizationUser,
            'New Application',
            "A new application was submitted for {$opportunityTitle}.",
            type: 'application',
            priority: 'normal',
            actionUrl: $this->organizationApplicationPath($applicationId),
        );
    }

    /**
     * Student-facing: the organization shortlisted the student's
     * application.
     */
    public function notifyApplicationShortlisted(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $studentUser,
            'Application Shortlisted',
            "You have been shortlisted for {$opportunityTitle}.",
            type: 'application',
            priority: 'normal',
            actionUrl: $this->studentApplicationPath($applicationId),
        );
    }

    /**
     * Student-facing: the organization rejected the student's application
     * (pre-Offer — see docs/BUSINESS_RULES.md on the Offer-decline path,
     * which is a separate `notifyOfferDeclined()`-triggering event, not
     * this one).
     */
    public function notifyApplicationRejected(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        $notification = $this->create(
            $studentUser,
            'Application Update',
            "Your application for {$opportunityTitle} was not selected.",
            type: 'application',
            priority: 'high',
            actionUrl: $this->studentApplicationPath($applicationId),
        );

        $this->emails->sendApplicationRejectedEmail(
            $studentUser,
            $opportunityTitle,
            $applicationId,
        );

        return $notification;
    }

    // -----------------------------------------------------------------
    // Invitation (Phase 8B-3, Flow B). As of Phase 8B-3.1,
    // `notifyInvitationReceived()` also queues an email -- the Student
    // otherwise has no reason to open the app and discover an invitation
    // exists. `notifyInvitationAccepted()`/`notifyInvitationDeclined()`
    // (organization-facing) remain in-app only: each fires too
    // infrequently per organization to justify a second email type for
    // this event pair, and the Organization is already actively watching
    // its own sent invitations.
    // -----------------------------------------------------------------

    /**
     * Student-facing: an Organization sent an invitation to apply.
     */
    public function notifyInvitationReceived(
        User $studentUser,
        string $organizationName,
        string $opportunityTitle,
        ?string $invitationMessage = null,
    ): Notification {
        $notification = $this->create(
            $studentUser,
            'Invitation to Apply',
            "{$organizationName} invited you to apply for {$opportunityTitle}.",
            type: 'opportunity',
            priority: 'normal',
            actionUrl: $this->studentInvitationsPath(),
        );

        $this->emails->sendInvitationReceivedEmail(
            $studentUser,
            $organizationName,
            $opportunityTitle,
            $invitationMessage,
        );

        return $notification;
    }

    /**
     * Organization-facing: the student accepted an invitation.
     */
    public function notifyInvitationAccepted(
        User $organizationUser,
        string $studentName,
        string $opportunityTitle,
        int $opportunityId,
    ): Notification {
        return $this->create(
            $organizationUser,
            'Invitation Accepted',
            "{$studentName} accepted your invitation to apply for {$opportunityTitle}.",
            type: 'opportunity',
            priority: 'normal',
            actionUrl: $this->organizationOpportunityPath($opportunityId),
        );
    }

    /**
     * Organization-facing: the student declined an invitation.
     */
    public function notifyInvitationDeclined(
        User $organizationUser,
        string $studentName,
        string $opportunityTitle,
        int $opportunityId,
    ): Notification {
        return $this->create(
            $organizationUser,
            'Invitation Declined',
            "{$studentName} declined your invitation to apply for {$opportunityTitle}.",
            type: 'opportunity',
            priority: 'normal',
            actionUrl: $this->organizationOpportunityPath($opportunityId),
        );
    }

    // -----------------------------------------------------------------
    // Interview
    // -----------------------------------------------------------------

    /**
     * Student-facing: an interview was scheduled for the student's
     * application.
     *
     * @param  Interview  $interview  The just-created Interview (Phase
     *                                7A-4.2) — read here only to forward its
     *                                scheduling fields to `EmailService`;
     *                                never persisted or altered. The in-app
     *                                Notification's own copy/type/priority/
     *                                action_url below is unchanged by its
     *                                presence. Only `interview_type`,
     *                                `scheduled_at`, `duration_minutes`,
     *                                `meeting_link`, `location`,
     *                                `contact_phone` (Phase Final-QA-1),
     *                                and `interviewer_name` are ever read —
     *                                never `interviewer_email`/`rating`/
     *                                `decision`/`notes`/`company_feedback`.
     */
    public function notifyInterviewScheduled(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
        Interview $interview,
    ): Notification {
        $notification = $this->create(
            $studentUser,
            'Interview Scheduled',
            "An interview has been scheduled for your application to {$opportunityTitle}.",
            type: 'interview',
            priority: 'normal',
            actionUrl: $this->studentApplicationPath($applicationId),
        );

        $this->emails->sendInterviewScheduledEmail(
            $studentUser,
            $opportunityTitle,
            $applicationId,
            interviewType: $interview->interview_type,
            scheduledAt: $interview->scheduled_at,
            durationMinutes: $interview->duration_minutes,
            meetingLink: $interview->meeting_link,
            location: $interview->location,
            contactPhone: $interview->contact_phone,
            interviewerName: $interview->interviewer_name,
        );

        return $notification;
    }

    /**
     * Student-facing: an already-scheduled interview was rescheduled.
     *
     * @param  Interview  $interview  The already-updated Interview (Phase
     *                                7A-4.2, current/post-update values only
     *                                — see `EmailService::sendInterviewRescheduledEmail()`'s
     *                                own doc comment). Same field-safety
     *                                notes as `notifyInterviewScheduled()`
     *                                above apply here.
     */
    public function notifyInterviewRescheduled(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
        Interview $interview,
    ): Notification {
        $notification = $this->create(
            $studentUser,
            'Interview Rescheduled',
            "Your interview for {$opportunityTitle} has been rescheduled.",
            type: 'interview',
            priority: 'normal',
            actionUrl: $this->studentApplicationPath($applicationId),
        );

        $this->emails->sendInterviewRescheduledEmail(
            $studentUser,
            $opportunityTitle,
            $applicationId,
            interviewType: $interview->interview_type,
            scheduledAt: $interview->scheduled_at,
            durationMinutes: $interview->duration_minutes,
            meetingLink: $interview->meeting_link,
            location: $interview->location,
            contactPhone: $interview->contact_phone,
            interviewerName: $interview->interviewer_name,
        );

        return $notification;
    }

    // -----------------------------------------------------------------
    // Quiz
    // -----------------------------------------------------------------

    /**
     * Student-facing: a quiz was published and is now available to take.
     * Points directly at the Quiz screen (not Application Details) since
     * that's the actionable target for this specific event.
     */
    /**
     * @param  Quiz  $quiz  The just-published Quiz (Phase 7A-4.2) — read
     *                      here only to forward `passing_score`/
     *                      `time_limit_minutes` to `EmailService`; never
     *                      persisted or altered. `passing_score` is already
     *                      student-visible today (see docs/API.md's
     *                      "Student-visible Quiz fields" note), so this
     *                      carries no new exposure. Never reads question
     *                      data, `correct_answer`, or anything score/
     *                      grading-related.
     * @param  ?Carbon  $availableAt  Phase 10A.4B addendum — this
     *                      candidate's own frozen availability window,
     *                      when `$quiz` is a shared Opportunity template
     *                      (`AssessmentService::advanceToSharedQuiz()`).
     *                      `null` for the legacy ad-hoc "just published"
     *                      case, which has no availability window at all
     *                      — the message/email stay exactly as they were
     *                      before this addendum in that case.
     * @param  ?Carbon  $dueAt  Only meaningful alongside $availableAt.
     */
    public function notifyQuizPublished(
        User $studentUser,
        string $opportunityTitle,
        int $assessmentId,
        Quiz $quiz,
        ?Carbon $availableAt = null,
        ?Carbon $dueAt = null,
    ): Notification {
        $message = $availableAt === null
            ? "A quiz is now available for your application to {$opportunityTitle}."
            : "You have been selected to complete a technical assessment for "
                ."{$opportunityTitle}. Available from "
                .$availableAt->format('M j, Y g:i A')
                .($dueAt !== null ? ', deadline '.$dueAt->format('M j, Y g:i A') : '')
                .'.';

        $notification = $this->create(
            $studentUser,
            'Quiz Available',
            $message,
            type: 'assessment',
            priority: 'normal',
            actionUrl: $this->studentQuizPath($assessmentId),
        );

        $this->emails->sendQuizAvailableEmail(
            $studentUser,
            $opportunityTitle,
            $assessmentId,
            passingScore: $quiz->passing_score,
            timeLimitMinutes: $quiz->time_limit_minutes,
            availableAt: $availableAt,
            dueAt: $dueAt,
        );

        return $notification;
    }

    /**
     * Student-facing: a Quiz result has just been *released* to the
     * Student (Phase 10A.2) — never called at submission time itself; the
     * single caller is `QuizResultReleaseService::release()`, whether
     * triggered by immediate release, a manual Organization action, or a
     * scheduled release job, so this notification (and its email) can
     * never fire more than once per Assessment and never before the
     * Organization's configured release moment. Distinct from
     * `notifyQuizCompleted()` below, which is the organization-facing
     * counterpart of the *submission* event, not the release event — kept
     * as two methods rather than one shared call because the copy/
     * recipient/timing genuinely differ, the same reasoning
     * `notifyOfferAccepted()`/`notifyOfferDeclined()` already follow for
     * their own two-sided events.
     *
     * @param  bool  $passed  Drives only the email/notification copy —
     *                        never implies a recruitment decision. See
     *                        `QuizResultMail`'s own doc comment on why a
     *                        failed result never claims rejection.
     */
    public function notifyQuizResultAvailable(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
        bool $passed,
    ): Notification {
        $notification = $this->create(
            $studentUser,
            'Quiz Result Available',
            "Your quiz result for {$opportunityTitle} is now available.",
            type: 'assessment',
            priority: 'normal',
            actionUrl: $this->studentApplicationPath($applicationId),
        );

        $this->emails->sendQuizResultEmail(
            $studentUser,
            $opportunityTitle,
            $applicationId,
            passed: $passed,
        );

        return $notification;
    }

    /**
     * Student-facing: a completed Quiz's "Advance to Interview" decision
     * has just been *released* (Phase 10A.4A) — the one coherent
     * notification combining the assessment outcome and the real interview
     * details, replacing what would otherwise be two separate messages
     * (`notifyQuizResultAvailable()` + `notifyInterviewScheduled()`) for
     * the same release. Only ever called from
     * `QuizResultReleaseService::releaseInterviewDecision()`, so this can
     * never fire before the Organization's decision is actually released,
     * and never more than once per Assessment (the same once-only
     * guarantee `notifyQuizResultAvailable()` already had).
     *
     * @param  Interview  $interview  The already-existing, already-scheduled
     *                                follow-up Interview — read here only to
     *                                forward its scheduling fields to
     *                                `EmailService`, the same student-safe
     *                                subset `notifyInterviewScheduled()`
     *                                already uses.
     */
    public function notifyQuizDecisionInterview(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
        Interview $interview,
    ): Notification {
        $notification = $this->create(
            $studentUser,
            'Assessment Update',
            "You have been selected to continue to the interview stage for {$opportunityTitle}.",
            type: 'assessment',
            priority: 'high',
            actionUrl: $this->studentApplicationPath($applicationId),
        );

        $this->emails->sendAssessmentDecisionInterviewEmail(
            $studentUser,
            $opportunityTitle,
            $applicationId,
            interviewType: $interview->interview_type,
            scheduledAt: $interview->scheduled_at,
            durationMinutes: $interview->duration_minutes,
            meetingLink: $interview->meeting_link,
            location: $interview->location,
            contactPhone: $interview->contact_phone,
            interviewerName: $interview->interviewer_name,
        );

        return $notification;
    }

    /**
     * Organization-facing: a Quiz's scheduled (or otherwise attempted)
     * result release could not happen because the Organization hasn't
     * selected/readied a next-step decision yet (Phase 10A.4A) — never a
     * Student-facing effect. In-app only, deliberately — this is an
     * operational nudge for an organization already actively managing its
     * own pipeline, the same posture `notifyQuizCompleted()` already takes
     * for its own frequent, non-urgent organization-facing event. Sent at
     * most once per Assessment — see
     * `QuizResultReleaseService::sendDecisionReminder()`'s own dedup
     * guard.
     */
    public function notifyDecisionRequired(
        User $organizationUser,
        string $studentName,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $organizationUser,
            'Decision Required',
            "The scheduled result release for {$studentName}'s assessment ({$opportunityTitle}) ".
                'has passed, but a next-step decision has not been completed.',
            type: 'assessment',
            priority: 'high',
            actionUrl: $this->organizationApplicationPath($applicationId),
        );
    }

    /**
     * Organization-facing: a student completed a quiz for one of the
     * organization's applications.
     */
    public function notifyQuizCompleted(
        User $organizationUser,
        string $studentName,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $organizationUser,
            'Quiz Completed',
            "{$studentName} completed the quiz for {$opportunityTitle}.",
            type: 'assessment',
            priority: 'normal',
            actionUrl: $this->organizationApplicationPath($applicationId),
        );
    }

    // -----------------------------------------------------------------
    // Offer
    // -----------------------------------------------------------------

    /**
     * Student-facing: the organization sent a final Offer.
     *
     * @param  Offer  $offer  The just-created Offer (Phase 7A-4.1) — read
     *                        here only to forward its display fields
     *                        (start date, compensation, message) to
     *                        `EmailService`; never persisted or altered.
     *                        The in-app Notification's own copy/type/
     *                        priority/action_url below is unchanged by its
     *                        presence.
     */
    public function notifyOfferSent(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
        Offer $offer,
    ): Notification {
        $notification = $this->create(
            $studentUser,
            'Offer Received',
            "You received an offer for {$opportunityTitle}.",
            type: 'offer',
            priority: 'high',
            actionUrl: $this->studentApplicationPath($applicationId),
        );

        $this->emails->sendOfferReceivedEmail(
            $studentUser,
            $opportunityTitle,
            $applicationId,
            startDate: $offer->start_date,
            salaryAmount: $offer->salary_amount,
            salaryCurrency: $offer->salary_currency,
            salaryPeriod: $offer->salary_period,
            offerMessage: $offer->message,
        );

        return $notification;
    }

    /**
     * Organization-facing: the student accepted the Offer.
     */
    public function notifyOfferAccepted(
        User $organizationUser,
        string $studentName,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        $notification = $this->create(
            $organizationUser,
            'Offer Accepted',
            "{$studentName} accepted the offer for {$opportunityTitle}.",
            type: 'offer',
            priority: 'high',
            actionUrl: $this->organizationApplicationPath($applicationId),
        );

        $this->emails->sendOfferAcceptedEmail(
            $organizationUser,
            $studentName,
            $opportunityTitle,
            $applicationId,
        );

        return $notification;
    }

    /**
     * Organization-facing: the student declined the Offer.
     */
    public function notifyOfferDeclined(
        User $organizationUser,
        string $studentName,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        $notification = $this->create(
            $organizationUser,
            'Offer Declined',
            "{$studentName} declined the offer for {$opportunityTitle}.",
            type: 'offer',
            priority: 'high',
            actionUrl: $this->organizationApplicationPath($applicationId),
        );

        $this->emails->sendOfferDeclinedEmail(
            $organizationUser,
            $studentName,
            $opportunityTitle,
            $applicationId,
        );

        return $notification;
    }

    // -----------------------------------------------------------------
    // Messaging MVP
    // -----------------------------------------------------------------

    /**
     * Recipient-facing (either direction: Organization or Student): a new
     * message arrived in one of their conversations. Fires exactly once
     * per `MessagingService::sendMessage()` call, never to the sender.
     * Deliberately in-app only, no email -- see this phase's own
     * doc/report on why: a per-message email would spam an active
     * conversation, and this MVP has no per-conversation "already
     * notified, don't email again" state to throttle it safely. The
     * in-app notification (with its own unread badge) plus the
     * conversation list's own unread state already cover the "come back
     * and see this" need without an inbox full of one-line emails.
     *
     * The message body itself is deliberately never included in the
     * notification `message` text -- only the sender's display name, the
     * same "never leak the sensitive payload into a broadly-visible
     * summary" restraint this service already applies elsewhere (e.g.
     * `notifyInvitationReceived()` never repeats a private invitation
     * message body either, beyond what the invitation flow already
     * shows).
     */
    public function notifyNewMessage(
        User $recipientUser,
        string $senderDisplayName,
        Conversation $conversation,
        bool $recipientIsStudent,
    ): Notification {
        return $this->create(
            $recipientUser,
            'New Message',
            "New message from {$senderDisplayName}",
            type: 'message',
            priority: 'normal',
            actionUrl: $recipientIsStudent
                ? $this->studentConversationPath($conversation->id)
                : $this->organizationConversationPath($conversation->id),
        );
    }

    // -----------------------------------------------------------------
    // action_url helpers — kept in exact sync with the Flutter app's own
    // `AppRoutes` path constants (lib/routes/app_routes.dart). App-relative
    // paths only, never a full domain URL — see docs/ARCHITECTURE.md.
    // -----------------------------------------------------------------

    private function studentApplicationPath(int $applicationId): string
    {
        return "/student/applications/{$applicationId}";
    }

    private function studentQuizPath(int $assessmentId): string
    {
        return "/student/assessments/{$assessmentId}/quiz";
    }

    private function organizationApplicationPath(int $applicationId): string
    {
        return "/organization/applications/{$applicationId}";
    }

    private function studentInvitationsPath(): string
    {
        return '/student/invitations';
    }

    private function organizationOpportunityPath(int $opportunityId): string
    {
        return "/organization/opportunities/{$opportunityId}";
    }

    private function studentConversationPath(int $conversationId): string
    {
        return "/student/messages/{$conversationId}";
    }

    private function organizationConversationPath(int $conversationId): string
    {
        return "/organization/messages/{$conversationId}";
    }
}
