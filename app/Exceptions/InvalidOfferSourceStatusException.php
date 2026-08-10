<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by OfferService::sendOffer() whenever the application isn't
 * eligible to receive an Offer yet -- covering three distinct preconditions
 * with one exception type, each given its own message at the throw site
 * rather than splitting into three exception classes (mirrors
 * InvalidAssessmentSourceStatusException's own single-exception,
 * custom-message pattern):
 *
 *  - `application.status !== 'in_assessment'` (wrong stage entirely, or
 *    already `offer_sent`/`accepted`/`rejected`/`withdrawn`/...).
 *  - no Assessment exists for the application at all.
 *  - an Assessment exists but `assessment.status !== 'completed'` (still
 *    `pending`/`scheduled`/`in_progress`/`declined`/`cancelled`).
 *
 * Deliberately never checks `assessment.result` -- see OfferService's own
 * doc comment for why a `failed`/`waiting` result must not block sending.
 */
class InvalidOfferSourceStatusException extends Exception
{
    public function __construct(string $message = 'This application is not eligible to receive an offer.')
    {
        parent::__construct($message);
    }
}
