<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Student-facing: sent after `OfferService::sendOffer()` successfully
 * creates an Offer (Phase 7A-4.1 pilot -- the only workflow email enabled
 * so far, see `EmailService::sendOfferReceivedEmail()` for how this is
 * built/queued).
 *
 * Deliberately constructed from primitives, not the `Offer`/`Application`/
 * `Opportunity` models themselves -- keeps the queued payload small and
 * keeps this class from silently growing a dependency on unrelated model
 * fields as those models evolve. `EmailService` is the one place that
 * extracts these primitives from the real models.
 */
class OfferReceivedMail extends QueuedTransactionalMail
{
    public function __construct(
        public readonly string $studentName,
        public readonly string $opportunityTitle,
        public readonly string $ctaUrl,
        public readonly ?Carbon $startDate = null,
        public readonly ?string $salaryAmount = null,
        public readonly ?string $salaryCurrency = null,
        public readonly ?string $salaryPeriod = null,
        public readonly ?string $offerMessage = null,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Offer Received — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.offer_received',
            with: [
                'compensation' => $this->formattedCompensation(),
            ],
        );
    }

    /**
     * "USD 1500.00 / month" only when amount, currency, and period are ALL
     * present -- every Offer term is optional at creation time (see
     * `SendOfferRequest`), so a partial combination (e.g. an amount with no
     * currency) is a real, reachable state that must never render as
     * malformed/half-blank compensation text.
     */
    private function formattedCompensation(): ?string
    {
        if ($this->salaryAmount === null || $this->salaryCurrency === null || $this->salaryPeriod === null) {
            return null;
        }

        return "{$this->salaryCurrency} {$this->salaryAmount} / {$this->periodLabel()}";
    }

    private function periodLabel(): string
    {
        return match ($this->salaryPeriod) {
            'yearly' => 'year',
            'monthly' => 'month',
            default => $this->salaryPeriod,
        };
    }
}
