<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by Student\QuizController::submit() when `quiz.time_limit_minutes`
 * is set and `now()` is past `attempt.started_at + time_limit_minutes`, with
 * no grace period. Never based on any client-supplied timing -- the
 * Flutter countdown is purely a UI convenience and is never trusted for
 * enforcement.
 */
class QuizTimeLimitExpiredException extends Exception
{
    public function __construct(string $message = 'Quiz time limit has expired')
    {
        parent::__construct($message);
    }
}
