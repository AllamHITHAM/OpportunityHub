<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Offer;
use App\Models\User;
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
 * **`EmailService` collaborator (Phase 7A-4.1).** This class remains the
 * single conceptual "which workflow event happened, who does it concern"
 * boundary — it still owns the in-app Notification's copy/type/priority/
 * action_url exactly as before. It now also decides *which* of its events
 * are important enough to also queue a transactional email, delegating the
 * actual Mailable/transport/after-commit mechanics to `EmailService`
 * (constructor-injected) rather than calling `Mail::` itself — mixing SMTP
 * transport concerns into this class would defeat the point of having a
 * separate `EmailService` at all. Only `notifyOfferSent()` queues an email
 * so far; the remaining event methods are unchanged and queue nothing
 * (Phase 7A-4.2 will extend the same pattern to them). Because
 * `EmailService::sendOfferReceivedEmail()` queues with after-commit
 * semantics (see `QueuedTransactionalMail`), calling it here — still inside
 * the same `DB::transaction()` as the Offer mutation — does not carry the
 * "an unexpected failure rolls back the business action" risk the in-app
 * Notification insert above still has: a queue-dispatch failure raises
 * immediately (see `EmailService`'s own doc comment on why that's
 * deliberate), but the actual SMTP send itself never runs until well after
 * this transaction has already committed, and its failure is isolated to
 * the queued job (see docs/BUSINESS_RULES.md section 8).
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
        return $this->create(
            $studentUser,
            'Application Update',
            "Your application for {$opportunityTitle} was not selected.",
            type: 'application',
            priority: 'high',
            actionUrl: $this->studentApplicationPath($applicationId),
        );
    }

    // -----------------------------------------------------------------
    // Interview
    // -----------------------------------------------------------------

    /**
     * Student-facing: an interview was scheduled for the student's
     * application.
     */
    public function notifyInterviewScheduled(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $studentUser,
            'Interview Scheduled',
            "An interview has been scheduled for your application to {$opportunityTitle}.",
            type: 'interview',
            priority: 'normal',
            actionUrl: $this->studentApplicationPath($applicationId),
        );
    }

    /**
     * Student-facing: an already-scheduled interview was rescheduled.
     */
    public function notifyInterviewRescheduled(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $studentUser,
            'Interview Rescheduled',
            "Your interview for {$opportunityTitle} has been rescheduled.",
            type: 'interview',
            priority: 'normal',
            actionUrl: $this->studentApplicationPath($applicationId),
        );
    }

    // -----------------------------------------------------------------
    // Quiz
    // -----------------------------------------------------------------

    /**
     * Student-facing: a quiz was published and is now available to take.
     * Points directly at the Quiz screen (not Application Details) since
     * that's the actionable target for this specific event.
     */
    public function notifyQuizPublished(
        User $studentUser,
        string $opportunityTitle,
        int $assessmentId,
    ): Notification {
        return $this->create(
            $studentUser,
            'Quiz Available',
            "A quiz is now available for your application to {$opportunityTitle}.",
            type: 'assessment',
            priority: 'normal',
            actionUrl: $this->studentQuizPath($assessmentId),
        );
    }

    /**
     * Student-facing: the student's own quiz submission has been graded and
     * a result is available. Distinct from `notifyQuizCompleted()` below,
     * which is the organization-facing counterpart of the same underlying
     * event (Phase 7A-2) — kept as two methods rather than one shared call
     * because the copy/recipient genuinely differ, the same reasoning
     * `notifyOfferAccepted()`/`notifyOfferDeclined()` already follow for
     * their own two-sided events.
     */
    public function notifyQuizResultAvailable(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $studentUser,
            'Quiz Result Available',
            "Your quiz result for {$opportunityTitle} is now available.",
            type: 'assessment',
            priority: 'normal',
            actionUrl: $this->studentApplicationPath($applicationId),
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
        return $this->create(
            $organizationUser,
            'Offer Accepted',
            "{$studentName} accepted the offer for {$opportunityTitle}.",
            type: 'offer',
            priority: 'high',
            actionUrl: $this->organizationApplicationPath($applicationId),
        );
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
        return $this->create(
            $organizationUser,
            'Offer Declined',
            "{$studentName} declined the offer for {$opportunityTitle}.",
            type: 'offer',
            priority: 'high',
            actionUrl: $this->organizationApplicationPath($applicationId),
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
}
