<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by OfferService::acceptOffer()/declineOffer() when the Offer's
 * `status` is no longer `sent` -- checked only after acquiring a row lock
 * inside the response transaction, so a genuine concurrent accept/decline
 * race is caught the same way as a simple repeat request: exactly one
 * response ever wins, and the second is always this exception, never a
 * silent overwrite. Offer v1 has no re-response/undo, so this is always
 * terminal for the current Offer.
 */
class OfferAlreadyRespondedException extends Exception
{
    public function __construct(string $message = 'This offer has already been responded to.')
    {
        parent::__construct($message);
    }
}
