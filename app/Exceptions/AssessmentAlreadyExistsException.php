<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by AssessmentService when an application already has an *active*
 * (non-final) Assessment -- an application with only finalized Assessment
 * history (e.g. a completed Quiz) does NOT throw this; that's exactly the
 * Phase 10A.3 "Advance to Interview" case. Detected inside the creation
 * transaction, after row-locking the parent Application
 * (`AssessmentService::lockApplication()`), which is what makes this the
 * final concurrency authority as of Phase 10A.3 -- there is no longer a
 * database unique constraint to fall back on (`assessments.application_id`
 * stopped being unique once Assessment history was allowed; see that
 * migration's own doc comment). Carries no HTTP concerns -- each calling
 * controller decides its own user-facing 409 message (the legacy and
 * generic endpoints intentionally word this differently).
 */
class AssessmentAlreadyExistsException extends Exception
{
    public function __construct(string $message = 'An assessment already exists for this application.')
    {
        parent::__construct($message);
    }
}
