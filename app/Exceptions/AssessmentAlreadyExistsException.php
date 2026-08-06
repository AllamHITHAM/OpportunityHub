<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by AssessmentService when an application already has an
 * Assessment -- either detected by the pre-check
 * (`$application->assessment()->exists()`) or by translating a
 * `assessments.application_id` unique-constraint violation caught during
 * the creation transaction (the final concurrency authority). Carries no
 * HTTP concerns -- each calling controller decides its own user-facing 409
 * message (the legacy and generic endpoints intentionally word this
 * differently).
 */
class AssessmentAlreadyExistsException extends Exception
{
    public function __construct(string $message = 'An assessment already exists for this application.')
    {
        parent::__construct($message);
    }
}
