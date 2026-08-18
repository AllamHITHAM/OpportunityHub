<?php

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown by CvTextExtractor::extract() when a managed CV PDF's text
 * genuinely cannot be extracted -- the file is missing, the path isn't a
 * managed upload, or the PDF itself can't be opened at all (corrupt,
 * encrypted/password-protected). Never thrown for a valid PDF that simply
 * has no extractable text (e.g. a scanned/image-only PDF) -- that's a
 * normal empty-string result, not a failure (see CvTextExtractor's own doc
 * comment). Caught internally by Student\CVController::store(), which
 * treats it as "leave parsed_text null" rather than rejecting the upload
 * -- the underlying parser exception is never exposed to an API response.
 */
class CvTextExtractionException extends Exception
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
