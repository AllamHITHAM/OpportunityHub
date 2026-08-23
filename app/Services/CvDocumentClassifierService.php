<?php

namespace App\Services;

use App\Exceptions\AiSkillExtractionException;
use App\Services\Concerns\RetriesTransientAiProviderCalls;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8A-6.2: a narrow, bounded AI classification gate that runs BEFORE
 * `AiSkillExtractionService` inside `CVController::extractSkills()` --
 * determines whether a CV's already-extracted text is genuinely
 * resume/CV-like content, so a lecture chapter, textbook excerpt, article,
 * or any other unrelated document that merely mentions technical terms can
 * never be attributed to a student as their own skills. The mere presence
 * of programming-language/tool names is deliberately NOT the signal this
 * looks for -- see the system prompt below.
 *
 * Reuses the exact same configured AI provider (Groq) and HTTP client
 * pattern as `AiSkillExtractionService` -- no second provider, no new
 * configuration keys. Phase 8A-6.3: also shares that service's bounded
 * retry-with-backoff for a transient provider outcome (see
 * `RetriesTransientAiProviderCalls`). A classification-provider failure
 * (missing config, network error, non-retryable response, malformed
 * structured output, or exhausting all retries) throws the same
 * `AiSkillExtractionException` that skill extraction itself throws, which
 * `CVController` maps to the same safe `503` -- a provider failure is
 * never reported to the student as "not a CV".
 *
 * Writes nothing to any table -- purely a read-only classification.
 */
class CvDocumentClassifierService
{
    use RetriesTransientAiProviderCalls;

    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /** Per-attempt timeout — see AiSkillExtractionService's own doc comment on retry bounding. */
    private const TIMEOUT_SECONDS = 20;

    /**
     * Only the first slice of the text is needed to judge document type --
     * keeps the classification request small and bounded regardless of how
     * long the CV's full extracted text is.
     */
    private const MAX_EXCERPT_CHARS = 6000;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You determine whether a piece of text is genuinely a CV/resume describing one real person's own background, as opposed to any other kind of document -- a textbook chapter, lecture notes, a research paper or article, an assignment, documentation, or any other unrelated content -- that merely happens to mention technical, professional, or academic terms.

        A real CV/resume typically presents one person's own history through some combination of: contact/identity information, an education history, work or internship experience, projects, skills, certifications, or a professional summary/objective -- usually organized as a personal timeline with associated dates, written in first person or as personal biographical fact, not as instructional or explanatory prose written to teach a reader about a subject.

        The mere presence of technical terms (programming languages, tools, frameworks, or technologies) is NOT sufficient evidence that a document is a CV -- a textbook chapter or lecture notes can freely mention the exact same terms while explaining them to a reader, without attributing them to any person's own experience. Judge document-level structure and intent, not keyword presence.

        Do not require every section (contact info, education, experience, projects, skills, certifications) to be present -- a real CV may legitimately omit some of them (e.g. a student CV with no paid work experience yet). Judge whether the text as a whole reads as one person's own career/academic history.

        Respond only with the requested structured data -- no chain-of-thought, no explanation outside the schema.
        PROMPT;

    /**
     * @throws AiSkillExtractionException on any provider/config/parsing
     *   failure -- never returns a value on failure. Callers must treat a
     *   thrown exception as "temporarily unavailable", never as evidence
     *   the document isn't a CV.
     */
    public function isCv(string $text): bool
    {
        $apiKey = config('services.groq.api_key');
        $model = config('services.groq.model');

        if (! is_string($apiKey) || $apiKey === '') {
            Log::error('CV document classification failed: GROQ_API_KEY is not configured.');

            throw new AiSkillExtractionException('AI skill extraction is not currently available.');
        }

        $body = $this->requestBody($text, $model);

        $response = $this->sendWithRetry(
            fn () => Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::API_URL, $body),
            'CV document classification failed',
        );

        if ($response->failed()) {
            Log::error('CV document classification failed: the AI provider returned an error response.', [
                'status' => $response->status(),
            ]);

            throw new AiSkillExtractionException('AI skill extraction is currently unavailable. Please try again later.');
        }

        return $this->parseResult($response->json());
    }

    private function requestBody(string $text, string $model): array
    {
        return [
            'model' => $model,
            'max_completion_tokens' => 300,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::SYSTEM_PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => mb_substr($text, 0, self::MAX_EXCERPT_CHARS),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'cv_document_classification',
                    'strict' => true,
                    'schema' => $this->outputSchema(),
                ],
            ],
        ];
    }

    private function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_cv' => ['type' => 'boolean'],
                'document_type' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['is_cv', 'document_type', 'reason'],
            'additionalProperties' => false,
        ];
    }

    private function parseResult(?array $body): bool
    {
        $text = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($text)) {
            Log::error('CV document classification failed: malformed provider response (no message content).');

            throw new AiSkillExtractionException('AI skill extraction returned an unexpected response.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded) || ! array_key_exists('is_cv', $decoded) || ! is_bool($decoded['is_cv'])) {
            Log::error('CV document classification failed: malformed provider response (invalid structure).');

            throw new AiSkillExtractionException('AI skill extraction returned an unexpected response.');
        }

        return $decoded['is_cv'];
    }
}
