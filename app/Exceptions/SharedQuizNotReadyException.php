<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by `AssessmentService::advanceToSharedQuiz()` (Phase 10A.4B) when
 * the Opportunity has no shared Quiz template at all, or has one that isn't
 * `published` yet -- an Opportunity that requires Quiz must never silently
 * send a candidate into a missing/unfinished Quiz. Carries no HTTP concerns;
 * the calling controller maps this to a clear 422.
 */
class SharedQuizNotReadyException extends Exception
{
    public function __construct(string $message = "This opportunity's quiz is not published yet.")
    {
        parent::__construct($message);
    }
}
