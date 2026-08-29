<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Student-facing: sent exactly once, from
 * `QuizResultReleaseService::releaseInterviewDecision()` (Phase 10A.4A),
 * when a completed Quiz's "Advance to Interview" decision is released —
 * combining the assessment outcome and the real interview details into one
 * coherent message, deliberately never split into a separate "you passed
 * the quiz" email and a separate "interview scheduled" email for the same
 * release (see `QuizResultReleaseService`'s own doc comment).
 *
 * Deliberately constructed from primitives, not the `Interview`/
 * `Application`/`Opportunity` models themselves — see `OfferReceivedMail`'s
 * own doc comment on why. The interview fields accepted here are the exact
 * same student-safe subset `InterviewScheduledMail` already uses —
 * `interviewer_email`/`rating`/`decision`/`notes`/`company_feedback` are
 * never accepted by this constructor at all.
 *
 * Never promises employment — "selected to continue to the interview
 * stage" is a recruitment-process update, not an offer.
 */
class AssessmentDecisionInterviewMail extends QueuedTransactionalMail
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
            subject: "Assessment Update — Interview Invitation — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.assessment_decision_interview',
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
