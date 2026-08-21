<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Student-facing: sent after `Organization\InterviewController::update()`
 * changes an already-scheduled interview's date/time or format (Phase
 * 7A-4.2). Only ever built from the interview's *current* (post-update)
 * values -- `NotificationService::notifyInterviewRescheduled()` never
 * carries the previous scheduling values at all (it only compares them to
 * decide whether this event fires), so there is nothing old to leak here.
 *
 * Same field shape as `InterviewScheduledMail` (see that class's own doc
 * comment on why `interviewerName` specifically is safe to include) --
 * kept as a separate class rather than a shared parameterized one because
 * the subject/copy genuinely differ, the same reasoning
 * `OfferAcceptedMail`/`OfferDeclinedMail` already follow.
 */
class InterviewRescheduledMail extends QueuedTransactionalMail
{
    public function __construct(
        public readonly string $studentName,
        public readonly string $opportunityTitle,
        public readonly string $ctaUrl,
        public readonly string $interviewType,
        public readonly Carbon $scheduledAt,
        public readonly ?int $durationMinutes = null,
        public readonly ?string $meetingLink = null,
        public readonly ?string $location = null,
        public readonly ?string $contactPhone = null,
        public readonly ?string $interviewerName = null,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Interview Rescheduled — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.interview_rescheduled',
            with: [
                'interviewTypeLabel' => $this->interviewTypeLabel(),
            ],
        );
    }

    private function interviewTypeLabel(): string
    {
        return match ($this->interviewType) {
            'online' => 'Online',
            'onsite' => 'Onsite',
            'phone' => 'Phone',
            default => ucfirst($this->interviewType),
        };
    }
}
