<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use InvalidArgumentException;

/**
 * The single backend API for creating in-app Notification rows (Phase
 * 7A-1). Every current business workflow (Application, Assessment/
 * Interview, Quiz, Offer) is expected to route its notification-worthy
 * events through this service's convenience methods rather than calling
 * `Notification::create()` directly, the same "one service owns this
 * concern" doctrine `AssessmentService`/`OfferService` already establish
 * for their own domains.
 *
 * **Not wired into any workflow yet.** `Organization\ApplicationController`,
 * `Student\ApplicationController`, `AssessmentService`,
 * `Organization\InterviewController`, `Organization\QuizController`,
 * `Student\QuizController`, and `OfferService` are all untouched in this
 * phase — none of them call this service. That wiring is Phase 7A-2. This
 * class is independently testable and complete on its own; it simply has no
 * callers yet.
 *
 * No Laravel Events/Listeners/Observers/Jobs/Mailables are used here —
 * every method is a plain synchronous call that inserts one row and
 * returns it, matching this project's current architecture doctrine and
 * scale (see docs/ARCHITECTURE.md).
 */
class NotificationService
{
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
     */
    public function notifyOfferSent(
        User $studentUser,
        string $opportunityTitle,
        int $applicationId,
    ): Notification {
        return $this->create(
            $studentUser,
            'Offer Received',
            "You received an offer for {$opportunityTitle}.",
            type: 'offer',
            priority: 'high',
            actionUrl: $this->studentApplicationPath($applicationId),
        );
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
