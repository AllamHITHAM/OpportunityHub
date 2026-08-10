<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by OfferService::sendOffer() when an application already has an
 * Offer -- either detected by the pre-check
 * (`$application->offer()->exists()`) or by translating a
 * `offers.application_id` unique-constraint violation caught during the
 * creation transaction (the final concurrency authority against a genuine
 * race between two send attempts). Carries no HTTP concerns -- the calling
 * controller decides the user-facing 409 message, matching
 * AssessmentAlreadyExistsException's own precedent.
 */
class OfferAlreadyExistsException extends Exception
{
    public function __construct(string $message = 'An offer already exists for this application.')
    {
        parent::__construct($message);
    }
}
