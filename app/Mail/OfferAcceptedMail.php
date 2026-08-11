<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Organization-facing: sent after a student accepts an Offer via
 * `OfferService::acceptOffer()` (Phase 7A-4.2). The recipient is the
 * organization account -- `$recipientName` greets them, `$studentName`
 * (the applicant, not the recipient) is mentioned in the body.
 *
 * Never includes CV/private student-profile contents or internal
 * assessment data -- this constructor only ever receives the student's
 * name and the opportunity title, the same minimal shape
 * `NotificationService::notifyOfferAccepted()`'s existing in-app copy
 * already uses.
 */
class OfferAcceptedMail extends QueuedTransactionalMail
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
            subject: "Offer Accepted — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.offer_accepted');
    }
}
