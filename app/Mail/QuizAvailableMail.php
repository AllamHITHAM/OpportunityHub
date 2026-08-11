<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Student-facing: sent after `Organization\QuizController::publish()`
 * successfully publishes a quiz (Phase 7A-4.2).
 *
 * `passingScore` is required (not nullable) -- `Quiz.passing_score` is a
 * required field at creation time (see `docs/API.md`'s Assessment-creation
 * validation rules) and is already returned to students today ("a
 * deliberate v1 product decision: the passing threshold is a transparent,
 * known-in-advance assessment rule, not a grading internal" -- see
 * `docs/API.md`'s "Student-visible Quiz fields" note). `timeLimitMinutes` is
 * nullable -- a quiz may have no time limit at all.
 *
 * Never accepts question data, `correct_answer`, a score, or any other
 * grading-internal field -- there is nothing in this constructor a future
 * caller could accidentally leak one through.
 */
class QuizAvailableMail extends QueuedTransactionalMail
{
    public function __construct(
        public readonly string $studentName,
        public readonly string $opportunityTitle,
        public readonly string $ctaUrl,
        public readonly int $passingScore,
        public readonly ?int $timeLimitMinutes = null,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Quiz Available — {$this->opportunityTitle}",
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.quiz_available');
    }
}
