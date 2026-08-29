<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Student-facing: sent only when a Quiz result is actually *released* to
 * the Student (Phase 10A.2) — never at submission time. See
 * `App\Services\QuizResultReleaseService`, the single place this is
 * queued from (immediate release at submit, a manual Organization release
 * action, or a scheduled release job — all three funnel through that one
 * service so this email is never duplicated or sent early).
 *
 * `$passed` drives only the copy — never a recruitment decision. A failed
 * result never claims the Organization has rejected the application (it
 * hasn't: passing/failing a Quiz never changes `Application.status`, see
 * `docs/BUSINESS_RULES.md`); the Organization retains full authority over
 * the actual next step, so both branches of `emails.quiz_result` say only
 * that the Organization will follow up, never promising an outcome.
 */
class QuizResultMail extends QueuedTransactionalMail
{
    public function __construct(
        public readonly string $studentName,
        public readonly string $opportunityTitle,
        public readonly string $ctaUrl,
        public readonly bool $passed,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Assessment Result — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.quiz_result');
    }
}
