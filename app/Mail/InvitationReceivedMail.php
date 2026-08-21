<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Student-facing: sent after `Organization\InvitationController::store()`
 * successfully creates an Invitation (Phase 8B-3.1). Deliberately
 * constructed from primitives, not the `Invitation`/`Opportunity` models
 * themselves -- see `OfferReceivedMail`'s own doc comment for why this
 * project's workflow Mailables all follow that shape.
 */
class InvitationReceivedMail extends QueuedTransactionalMail
{
    public function __construct(
        public readonly string $studentName,
        public readonly string $organizationName,
        public readonly string $opportunityTitle,
        public readonly string $ctaUrl,
        public readonly ?string $invitationMessage = null,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to apply',
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.invitation_received');
    }
}
