<?php

namespace App\Services;

use App\Mail\ApplicationRejectedMail;
use App\Mail\InterviewRescheduledMail;
use App\Mail\InterviewScheduledMail;
use App\Mail\OfferAcceptedMail;
use App\Mail\OfferDeclinedMail;
use App\Mail\OfferReceivedMail;
use App\Mail\QuizAvailableMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Owns queued transactional email (Phase 7A-4.1) -- transport concerns
 * (which Mailable, which queue, after-commit semantics -- see
 * `QueuedTransactionalMail`) and frontend-link composition. Deliberately
 * does not decide *which* business events warrant an email or resolve who
 * the recipient/event arguments are -- that stays `NotificationService`'s
 * job (see that class's own doc comment for the full boundary rationale);
 * this service is only ever called from inside one of its convenience
 * methods, never directly from a controller or domain service.
 *
 * Seven workflow emails exist as of Phase 7A-4.2: Offer Received (the
 * Phase 7A-4.1 pilot), Interview Scheduled, Interview Rescheduled, Quiz
 * Available, Application Rejected, Offer Accepted, Offer Declined. Four
 * events remain in-app only (Application Submitted, Application
 * Shortlisted, Quiz Completed, Quiz Result Available) -- no method exists
 * here for them; see `NotificationService` for the full matrix.
 *
 * Every call here queues (`Mail::queue()`), never sends synchronously
 * (`Mail::send()`) -- an SMTP failure must never block or roll back the
 * business transaction the caller is still inside of (see
 * `QueuedTransactionalMail`'s own doc comment on the after-commit
 * mechanism that makes this safe). `MAIL_MAILER=log` in this phase means no
 * live SMTP connection is ever attempted; queued jobs render to the log
 * channel once a queue worker processes them.
 *
 * Deliberately six explicit methods below, not one generic
 * `sendByEventType()` dispatcher -- each event's required data genuinely
 * differs (an Interview email needs scheduling fields, an Offer-response
 * email needs a student name, an Application-rejection email needs
 * neither), and a generic map would just push that same per-event
 * branching somewhere less legible.
 */
class EmailService
{
    /**
     * Queues an "Offer Received" email to the student who received
     * `$offer`. Deliberately takes primitives, not the `Offer`/`Opportunity`
     * models themselves -- see `OfferReceivedMail`'s own doc comment on why.
     *
     * @param  User  $recipient  The student's own account -- `$recipient->email`
     *                           is the only address ever used; there is no
     *                           separate "contact email" concept for a
     *                           student.
     */
    public function sendOfferReceivedEmail(
        User $recipient,
        string $opportunityTitle,
        int $applicationId,
        ?Carbon $startDate = null,
        ?string $salaryAmount = null,
        ?string $salaryCurrency = null,
        ?string $salaryPeriod = null,
        ?string $offerMessage = null,
    ): void {
        Mail::to($recipient->email)->queue(new OfferReceivedMail(
            studentName: $recipient->name,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $this->studentApplicationUrl($applicationId),
            startDate: $startDate,
            salaryAmount: $salaryAmount,
            salaryCurrency: $salaryCurrency,
            salaryPeriod: $salaryPeriod,
            offerMessage: $offerMessage,
        ));
    }

    /**
     * Queues an "Interview Scheduled" email to the student. Deliberately
     * takes primitives, not the `Interview` model itself -- see
     * `InterviewScheduledMail`'s own doc comment on why, and on exactly
     * which Interview fields are safe to accept here at all.
     *
     * `$durationMinutes` is nullable even though `interviews.duration_minutes`
     * has a DB-level default (60) -- the freshly-created `Interview` model
     * passed through `NotificationService::notifyInterviewScheduled()` only
     * reflects that default once actually re-fetched from the database,
     * which this phase deliberately doesn't force (no extra query solely
     * for email content); the email simply omits the duration line when
     * it isn't in memory, matching this event's own "duration if available"
     * content spec.
     */
    public function sendInterviewScheduledEmail(
        User $recipient,
        string $opportunityTitle,
        int $applicationId,
        string $interviewType,
        Carbon $scheduledAt,
        ?int $durationMinutes = null,
        ?string $meetingLink = null,
        ?string $location = null,
        ?string $interviewerName = null,
    ): void {
        Mail::to($recipient->email)->queue(new InterviewScheduledMail(
            studentName: $recipient->name,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $this->studentApplicationUrl($applicationId),
            interviewType: $interviewType,
            scheduledAt: $scheduledAt,
            durationMinutes: $durationMinutes,
            meetingLink: $meetingLink,
            location: $location,
            interviewerName: $interviewerName,
        ));
    }

    /**
     * Queues an "Interview Rescheduled" email to the student, carrying only
     * the interview's *current* (post-update) values -- see
     * `InterviewRescheduledMail`'s own doc comment.
     */
    public function sendInterviewRescheduledEmail(
        User $recipient,
        string $opportunityTitle,
        int $applicationId,
        string $interviewType,
        Carbon $scheduledAt,
        ?int $durationMinutes = null,
        ?string $meetingLink = null,
        ?string $location = null,
        ?string $interviewerName = null,
    ): void {
        Mail::to($recipient->email)->queue(new InterviewRescheduledMail(
            studentName: $recipient->name,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $this->studentApplicationUrl($applicationId),
            interviewType: $interviewType,
            scheduledAt: $scheduledAt,
            durationMinutes: $durationMinutes,
            meetingLink: $meetingLink,
            location: $location,
            interviewerName: $interviewerName,
        ));
    }

    /**
     * Queues a "Quiz Available" email to the student. `$assessmentId`
     * (not `$applicationId`) drives the CTA -- it points directly at the
     * Student Quiz route, the same `action_url` target
     * `NotificationService::notifyQuizPublished()` already uses for the
     * in-app notification.
     */
    public function sendQuizAvailableEmail(
        User $recipient,
        string $opportunityTitle,
        int $assessmentId,
        int $passingScore,
        ?int $timeLimitMinutes = null,
    ): void {
        Mail::to($recipient->email)->queue(new QuizAvailableMail(
            studentName: $recipient->name,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $this->studentQuizUrl($assessmentId),
            passingScore: $passingScore,
            timeLimitMinutes: $timeLimitMinutes,
        ));
    }

    /**
     * Queues an "Application Update" (rejection) email to the student.
     */
    public function sendApplicationRejectedEmail(
        User $recipient,
        string $opportunityTitle,
        int $applicationId,
    ): void {
        Mail::to($recipient->email)->queue(new ApplicationRejectedMail(
            studentName: $recipient->name,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $this->studentApplicationUrl($applicationId),
        ));
    }

    /**
     * Queues an "Offer Accepted" email to the organization account that
     * sent the Offer. `$recipient` is the organization's own account (the
     * email's greeting target); `$studentName` is the applicant who
     * accepted, mentioned in the body -- the two are never the same person.
     */
    public function sendOfferAcceptedEmail(
        User $recipient,
        string $studentName,
        string $opportunityTitle,
        int $applicationId,
    ): void {
        Mail::to($recipient->email)->queue(new OfferAcceptedMail(
            recipientName: $recipient->name,
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $this->organizationApplicationUrl($applicationId),
        ));
    }

    /**
     * Queues an "Offer Declined" email to the organization account that
     * sent the Offer. Same recipient/data shape as
     * `sendOfferAcceptedEmail()` -- see that method's own doc comment.
     */
    public function sendOfferDeclinedEmail(
        User $recipient,
        string $studentName,
        string $opportunityTitle,
        int $applicationId,
    ): void {
        Mail::to($recipient->email)->queue(new OfferDeclinedMail(
            recipientName: $recipient->name,
            studentName: $studentName,
            opportunityTitle: $opportunityTitle,
            ctaUrl: $this->organizationApplicationUrl($applicationId),
        ));
    }

    /**
     * Turns the same app-relative path `NotificationService` already uses
     * for this event's in-app `action_url` into an absolute link a real
     * email client can open. Never calls `env()` directly -- only
     * `config('app.frontend_url')`, which itself reads `FRONTEND_URL`.
     */
    private function studentApplicationUrl(int $applicationId): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/student/applications/{$applicationId}";
    }

    /**
     * Same as `studentApplicationUrl()`, but for the Student Quiz route --
     * addressed by Assessment ID, not Application ID (mirrors
     * `NotificationService`'s own `studentQuizPath()`).
     */
    private function studentQuizUrl(int $assessmentId): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/student/assessments/{$assessmentId}/quiz";
    }

    /**
     * Same as `studentApplicationUrl()`, but for the Organization
     * Application Details route (mirrors `NotificationService`'s own
     * `organizationApplicationPath()`).
     */
    private function organizationApplicationUrl(int $applicationId): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/organization/applications/{$applicationId}";
    }
}
