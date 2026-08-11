<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Student-facing: sent after `AssessmentService::createInterviewAssessment()`
 * successfully schedules an interview (Phase 7A-4.2).
 *
 * Deliberately constructed from primitives, not the `Interview`/
 * `Application`/`Opportunity` models themselves -- see `OfferReceivedMail`'s
 * own doc comment (Phase 7A-4.1) on why; `EmailService` is the one place
 * that extracts these primitives from the real models. `interviewerName` is
 * the only Interview field here that isn't already safe by construction --
 * `interviewer_email`/`rating`/`decision`/`notes`/`company_feedback` are
 * never accepted by this constructor at all, so there is no risk of a
 * future caller accidentally forwarding one (see
 * `docs/API.md`'s "Student-visible Interview fields" note: `interviewer_name`
 * is already returned to students today, unlike those other fields).
 */
class InterviewScheduledMail extends QueuedTransactionalMail
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
        public readonly ?string $interviewerName = null,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Interview Scheduled — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.interview_scheduled',
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
