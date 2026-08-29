<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Student-facing: sent after `Organization\QuizController::publish()`
 * successfully publishes a quiz (Phase 7A-4.2), and, as of Phase 10A.4B's
 * addendum, reused verbatim when a candidate is individually advanced to
 * an Opportunity's shared Quiz template
 * (`AssessmentService::advanceToSharedQuiz()`) -- the same "one real email,
 * reused" principle this codebase already follows elsewhere, rather than a
 * parallel "assignment email" template.
 *
 * `passingScore` is required (not nullable) -- `Quiz.passing_score` is a
 * required field at creation time (see `docs/API.md`'s Assessment-creation
 * validation rules) and is already returned to students today ("a
 * deliberate v1 product decision: the passing threshold is a transparent,
 * known-in-advance assessment rule, not a grading internal" -- see
 * `docs/API.md`'s "Student-visible Quiz fields" note). `timeLimitMinutes` is
 * nullable -- a quiz may have no time limit at all.
 *
 * `availableAt`/`dueAt` (Phase 10A.4B addendum) are this specific
 * candidate's own frozen availability window -- `null` for the legacy
 * ad-hoc "just published" case (no window exists), in which case the
 * rendered email is byte-for-byte what it was before this addendum.
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
        public readonly ?Carbon $availableAt = null,
        public readonly ?Carbon $dueAt = null,
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
