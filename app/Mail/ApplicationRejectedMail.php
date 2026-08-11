<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Student-facing: sent after `Organization\ApplicationController::updateStatus()`
 * moves an application to `rejected` (Phase 7A-4.2) -- the generic
 * rejection path, not an Offer decline (`OfferDeclinedMail` covers that
 * separate event; the two are structurally mutually exclusive for the same
 * application, see `NotificationService::notifyApplicationRejected()`'s own
 * doc comment).
 *
 * v1 has no student-visible rejection-reason field -- there is nothing to
 * carry here beyond the opportunity title, and this constructor
 * deliberately has no parameter that could be mistaken for one (no
 * `match_score`, no assessment feedback). Wording stays respectful and
 * concise rather than clinical.
 */
class ApplicationRejectedMail extends QueuedTransactionalMail
{
    public function __construct(
        public readonly string $studentName,
        public readonly string $opportunityTitle,
        public readonly string $ctaUrl,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Application Update — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.application_rejected');
    }
}
