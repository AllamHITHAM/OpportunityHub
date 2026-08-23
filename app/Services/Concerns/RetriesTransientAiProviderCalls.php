<?php

namespace App\Services\Concerns;

use App\Exceptions\AiSkillExtractionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8A-6.3: shared, bounded retry-with-backoff for the outbound Groq
 * HTTP call, used by both AiSkillExtractionService and
 * CvDocumentClassifierService (the only two places this app ever calls an
 * AI provider). Root cause this closes: previously any transient
 * hiccup -- a rate limit (429), a request timeout (408), a 5xx, or a bare
 * connection failure -- failed the whole Analyze attempt on the very
 * first try, even though the exact same request often succeeds a moment
 * later (confirmed by the reported "succeeds after waiting" behavior).
 *
 * Retries ONLY a transient outcome, up to 3 total attempts, with a short
 * backoff between attempts (configurable via
 * `services.groq.retry_delays_ms`, default ~2s then ~4s). Everything else
 * -- a non-retryable 4xx (e.g. 401 from a bad API key), a malformed
 * response body, or exhausting all 3 attempts -- surfaces immediately as
 * the exact same safe `AiSkillExtractionException` this app already
 * throws for a provider failure; callers still map that to the same
 * `503`. This trait never decides "not a CV" or "insufficient text" --
 * those are business outcomes read from a genuinely successful (2xx,
 * well-formed) response, entirely outside its scope.
 */
trait RetriesTransientAiProviderCalls
{
    private const MAX_ATTEMPTS = 3;

    /**
     * Runs [$send] (expected to perform one real outbound HTTP call and
     * return its Response, or throw on a connection-level failure) with
     * retry-with-backoff applied only to a transient outcome. Returns the
     * first response that isn't a transient failure -- which may still be
     * a genuine, non-retryable failure (e.g. 401) the caller must check
     * for itself via `$response->failed()`.
     *
     * @throws AiSkillExtractionException if every attempt fails
     *   transiently, or the final connection attempt itself throws.
     */
    private function sendWithRetry(callable $send, string $logContext): Response
    {
        $delaysMs = config('services.groq.retry_delays_ms', [2000, 4000]);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $isLastAttempt = $attempt === self::MAX_ATTEMPTS;

            try {
                $response = $send();
            } catch (Throwable $e) {
                if ($isLastAttempt) {
                    Log::error("{$logContext}: request to the AI provider could not be completed.", [
                        'exception' => $e->getMessage(),
                        'attempts' => $attempt,
                    ]);

                    throw new AiSkillExtractionException('AI skill extraction is currently unavailable. Please try again later.');
                }

                Log::warning("{$logContext}: connection attempt failed, retrying.", [
                    'attempt' => $attempt,
                    'exception' => $e->getMessage(),
                ]);
                $this->waitBeforeRetry($delaysMs, $attempt);

                continue;
            }

            if (! $this->isTransientFailure($response)) {
                return $response;
            }

            if ($isLastAttempt) {
                Log::error("{$logContext}: the AI provider kept returning a transient error after {$attempt} attempts.", [
                    'status' => $response->status(),
                ]);

                throw new AiSkillExtractionException('AI skill extraction is currently unavailable. Please try again later.');
            }

            Log::warning("{$logContext}: transient provider error, retrying.", [
                'attempt' => $attempt,
                'status' => $response->status(),
            ]);
            $this->waitBeforeRetry($delaysMs, $attempt);
        }

        // Unreachable -- the loop above always returns or throws by the
        // final iteration -- kept only so every code path has a return.
        throw new AiSkillExtractionException('AI skill extraction is currently unavailable. Please try again later.');
    }

    /**
     * A rate limit (429), a request timeout (408), or any 5xx are the
     * only outcomes treated as "worth retrying" -- every other 4xx (401,
     * 400, 403, ...) reflects a persistent problem (bad credentials,
     * malformed request) a retry cannot fix, so it's returned to the
     * caller immediately instead.
     */
    private function isTransientFailure(Response $response): bool
    {
        return $response->status() === 429
            || $response->status() === 408
            || $response->serverError();
    }

    private function waitBeforeRetry(array $delaysMs, int $attempt): void
    {
        $delayMs = $delaysMs[$attempt - 1] ?? end($delaysMs);
        if ($delayMs > 0) {
            usleep((int) $delayMs * 1000);
        }
    }
}
