<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by AssessmentService when an application's current status does
 * not permit creating an assessment (only `shortlisted` and, for legacy
 * compatibility, `interview_scheduled` are allowed -- see
 * AssessmentService::ALLOWED_SOURCE_STATUSES). Notably this also covers
 * `in_assessment`: once an application reaches that generic state a real
 * Assessment already exists, so a second one is never permitted. Carries
 * no HTTP concerns -- each calling controller decides its own user-facing
 * 422 message (the legacy and generic endpoints intentionally word this
 * differently).
 */
class InvalidAssessmentSourceStatusException extends Exception
{
    public function __construct(string $message = 'The application is not in a status that allows creating an assessment.')
    {
        parent::__construct($message);
    }
}
