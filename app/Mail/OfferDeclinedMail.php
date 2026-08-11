<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Organization-facing: sent after a student declines an Offer via
 * `OfferService::declineOffer()` (Phase 7A-4.2). Same shape as
 * `OfferAcceptedMail` -- see that class's own doc comment -- kept separate
 * because the subject/copy genuinely differ.
 *
 * v1 has no decline-reason field -- there is nothing to carry here beyond
 * who declined and for what opportunity, and this constructor deliberately
 * has no parameter that could be mistaken for an invented reason.
 */
class OfferDeclinedMail extends QueuedTransactionalMail
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $studentName,
        public readonly string $opportunityTitle,
        public readonly string $ctaUrl,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Offer Declined — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.offer_declined');
    }
}
