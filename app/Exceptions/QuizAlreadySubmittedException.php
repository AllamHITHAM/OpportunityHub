<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by Student\QuizController::submit() when the attempt's
 * `submitted_at` is already set -- checked only after acquiring a row lock
 * inside the grading transaction, so a genuine concurrent double-submit is
 * caught the same way as a simple repeat request. Quiz v1 has no retakes,
 * so this is always terminal for the current attempt.
 */
class QuizAlreadySubmittedException extends Exception
{
    public function __construct(string $message = 'Quiz has already been submitted')
    {
        parent::__construct($message);
    }
}
