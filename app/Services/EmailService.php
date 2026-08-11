<?php

namespace App\Services;

use App\Mail\OfferReceivedMail;
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
 * Only one workflow email exists as of this phase: Offer Received. The
 * remaining six (Interview Scheduled/Rescheduled, Quiz Available,
 * Application Rejected, Offer Accepted, Offer Declined) are Phase 7A-4.2.
 *
 * Every call here queues (`Mail::queue()`), never sends synchronously
 * (`Mail::send()`) -- an SMTP failure must never block or roll back the
 * business transaction the caller is still inside of (see
 * `QueuedTransactionalMail`'s own doc comment on the after-commit
 * mechanism that makes this safe). `MAIL_MAILER=log` in this phase means no
 * live SMTP connection is ever attempted; queued jobs render to the log
 * channel once a queue worker processes them.
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
     * Turns the same app-relative path `NotificationService` already uses
     * for this event's in-app `action_url` into an absolute link a real
     * email client can open. Never calls `env()` directly -- only
     * `config('app.frontend_url')`, which itself reads `FRONTEND_URL`.
     */
    private function studentApplicationUrl(int $applicationId): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/student/applications/{$applicationId}";
    }
}
