<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by AiSkillExtractionService for any AI-provider-side failure --
 * missing configuration, network/timeout errors, a non-2xx provider
 * response, or a malformed/invalid structured response. The calling
 * controller always maps this to a safe 503, and the message is written
 * to be shown to the end user as-is -- it never carries provider
 * internals, API keys, or raw stack traces. Technical detail is logged
 * server-side by the service before this is thrown.
 */
class AiSkillExtractionException extends Exception
{
}
