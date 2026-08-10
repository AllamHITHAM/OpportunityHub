<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by Student\QuizController::submit() when no QuizAttempt exists yet
 * for the application -- Phase 6B-3 deliberately requires Start before
 * Submit (no "submit implicitly starts" shortcut), so this is always a
 * genuine business rejection, not a missing-resource 404.
 */
class QuizAttemptNotStartedException extends Exception
{
    public function __construct(string $message = 'Start the quiz before submitting.')
    {
        parent::__construct($message);
    }
}
