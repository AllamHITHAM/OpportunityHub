<?php

namespace App\Services;

use App\Exceptions\AiSkillExtractionException;
use App\Models\CV;
use App\Models\CvSkillEvidence;
use App\Models\Skill;
use App\Models\SkillSuggestion;
use App\Models\StudentSkill;
use App\Services\Concerns\RetriesTransientAiProviderCalls;
use App\Support\SkillNameNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a CV's already-extracted text (CvTextExtractor, Phase 8A-5) to the
 * configured AI provider and returns structured, catalog-mapped skill
 * suggestions. This is the only place in the app that talks to an AI
 * provider.
 *
 * Deliberately narrow: never mutates StudentSkill, CV, Skill, or
 * match_score, and never sends notifications or emails. Callers (the
 * extract-skills endpoint) are responsible for turning accepted
 * suggestions into real StudentSkill rows through the existing Student
 * Skill endpoint -- this service is suggestion-only.
 *
 * Phase 8A-6.1: the only writes this service ever performs are (a)
 * idempotent CvSkillEvidence rows recording that a CV's extraction really
 * did identify a given catalog Skill, and (b) idempotent pending
 * SkillSuggestion rows for names that don't match the catalog. Neither
 * ever creates a Skill or a StudentSkill, and neither is ever
 * self-approved by the AI -- only an Admin (SkillSuggestionController)
 * can turn a suggestion into a real Skill.
 *
 * v1 provider: Groq (OpenAI-compatible Chat Completions API), called
 * directly over HTTP via Laravel's Http client rather than an SDK -- see
 * docs/ARCHITECTURE.md "AI CV Skill Extraction" for the reasoning.
 * Provider-specific code is confined entirely to this class, so swapping
 * providers later only means rewriting this file.
 */
class AiSkillExtractionService
{
    use RetriesTransientAiProviderCalls;

    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    /**
     * Per-attempt timeout. Phase 8A-6.3: a transient provider outcome
     * (429/408/5xx/connection failure) is now retried with backoff (see
     * RetriesTransientAiProviderCalls) rather than failing on the very
     * first hiccup -- still bounded overall (at most 3 attempts), since
     * this runs synchronously inside an interactive Student action.
     */
    private const TIMEOUT_SECONDS = 25;

    private const MAX_SKILLS = 20;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You extract technical and professional skills that are explicitly supported by the text of a CV/resume.

        Rules:
        - Only extract skills the text clearly demonstrates -- through explicit mentions, named tools/technologies, or described work. Never infer a skill from a job title alone, and never fabricate a skill the text does not support.
        - Do not infer or comment on personality traits, protected characteristics, suitability, or hiring fit. Extract skills only.
        - Normalize skill names to their common industry form (e.g. "AutoCAD", "Microsoft Excel", "Python").
        - Assign each skill a confidence score between 0.0 and 1.0 reflecting how directly the text supports it.
        - Return at most 20 skills, ordered by confidence, highest first.
        - Output only the requested structured data -- no chain-of-thought, no explanation, no text outside the schema.
        PROMPT;

    /**
     * @return array<int, array{name: string, confidence: float, skill_id: ?int, is_available: bool, already_added: bool, suggestion_id: ?int, suggestion_status: ?string}>
     *
     * @throws AiSkillExtractionException on any provider/config/parsing failure. Never throws for a
     *                                     CV with empty parsed_text -- callers must check that first
     *                                     (see CVController::extractSkills, which returns a 422 before
     *                                     this is ever called).
     */
    public function extractSkills(CV $cv): array
    {
        $apiKey = config('services.groq.api_key');
        $model = config('services.groq.model');

        if (! is_string($apiKey) || $apiKey === '') {
            Log::error('AI skill extraction failed: GROQ_API_KEY is not configured.');

            throw new AiSkillExtractionException('AI skill extraction is not currently available.');
        }

        $body = $this->requestBody((string) $cv->parsed_text, $model);

        // Phase 8A-6.3: transient outcomes (429/408/5xx/connection
        // failure) are retried with backoff inside sendWithRetry() --
        // what comes back here is either a genuine success or a
        // non-retryable failure (e.g. 401).
        $response = $this->sendWithRetry(
            fn () => Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::API_URL, $body),
            'AI skill extraction failed',
        );

        if ($response->failed()) {
            Log::error('AI skill extraction failed: the AI provider returned an error response.', [
                'status' => $response->status(),
            ]);

            throw new AiSkillExtractionException('AI skill extraction is currently unavailable. Please try again later.');
        }

        $skills = $this->parseSkills($response->json());

        return $this->mapToCatalog($skills, $cv);
    }

    /**
     * Builds the outbound AI request (OpenAI-compatible Chat Completions
     * shape). Sends ONLY the CV's parsed text -- never the student's name,
     * email, password, tokens, match_score, offers, application status,
     * interview feedback, or any other profile/application data. This is
     * the entirety of what the provider ever receives.
     */
    private function requestBody(string $parsedText, string $model): array
    {
        return [
            'model' => $model,
            'max_completion_tokens' => 2048,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::SYSTEM_PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => $parsedText,
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'cv_skill_extraction',
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
                'skills' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => ['name', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['skills'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Validates, normalizes, deduplicates (case-insensitively, keeping the
     * highest-confidence occurrence), and caps the provider's structured
     * output.
     *
     * @return array<int, array{name: string, confidence: float}>
     */
    private function parseSkills(?array $body): array
    {
        $text = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($text)) {
            Log::error('AI skill extraction failed: malformed provider response (no message content).');

            throw new AiSkillExtractionException('AI skill extraction returned an unexpected response.');
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded) || ! isset($decoded['skills']) || ! is_array($decoded['skills'])) {
            Log::error('AI skill extraction failed: malformed provider response (invalid structure).');

            throw new AiSkillExtractionException('AI skill extraction returned an unexpected response.');
        }

        $seen = [];

        foreach ($decoded['skills'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $confidence = $entry['confidence'] ?? 0;
            $confidence = is_numeric($confidence) ? (float) $confidence : 0.0;
            $confidence = max(0.0, min(1.0, $confidence));

            $key = SkillNameNormalizer::normalize($name);

            if (! isset($seen[$key]) || $confidence > $seen[$key]['confidence']) {
                $seen[$key] = ['name' => $name, 'confidence' => $confidence];
            }
        }

        $skills = array_values($seen);

        usort($skills, static fn (array $a, array $b) => $b['confidence'] <=> $a['confidence']);

        return array_slice($skills, 0, self::MAX_SKILLS);
    }

    /**
     * Maps each AI-suggested name to the existing Admin-owned Skill
     * catalog by exact normalized match. Never creates a new Skill record
     * from a catalog miss -- instead (Phase 8A-6.1) it reuses or creates a
     * pending SkillSuggestion for Admin review, so the catalog can grow
     * without requiring an Admin to invent every new skill manually,
     * while the AI itself can never create or approve one.
     *
     * A catalog hit also records (idempotently) a CvSkillEvidence row --
     * the only thing that later lets the Student Skill endpoint accept a
     * `source: cv_ai` claim for this exact (CV, skill) pair.
     *
     * @param  array<int, array{name: string, confidence: float}>  $skills
     * @return array<int, array{name: string, confidence: float, skill_id: ?int, is_available: bool, already_added: bool, suggestion_id: ?int, suggestion_status: ?string}>
     */
    private function mapToCatalog(array $skills, CV $cv): array
    {
        $catalog = Skill::all(['id', 'name'])
            ->keyBy(fn (Skill $skill) => SkillNameNormalizer::normalize($skill->name));

        $matchedIds = [];

        $mapped = array_map(function (array $skill) use ($catalog, $cv, &$matchedIds) {
            $normalized = SkillNameNormalizer::normalize($skill['name']);
            $skillRecord = $catalog->get($normalized);

            if ($skillRecord !== null) {
                $matchedIds[] = $skillRecord->id;

                CvSkillEvidence::firstOrCreate(
                    ['cv_id' => $cv->id, 'skill_id' => $skillRecord->id],
                    ['student_id' => $cv->student_id],
                );

                return [
                    'name' => $skill['name'],
                    'confidence' => $skill['confidence'],
                    'skill_id' => $skillRecord->id,
                    'is_available' => true,
                    'already_added' => false,
                    'suggestion_id' => null,
                    'suggestion_status' => null,
                ];
            }

            // No catalog match -- never create a Skill here. Reuse an
            // existing pending suggestion for this normalized name (so
            // repeated Analyze CV calls, including from other students,
            // never create duplicates), or create a new pending one.
            $suggestion = SkillSuggestion::firstOrCreate(
                ['normalized_name' => $normalized, 'status' => 'pending'],
                ['name' => $skill['name'], 'source' => 'ai_cv'],
            );

            return [
                'name' => $skill['name'],
                'confidence' => $skill['confidence'],
                'skill_id' => null,
                'is_available' => false,
                'already_added' => false,
                'suggestion_id' => $suggestion->id,
                'suggestion_status' => $suggestion->status,
            ];
        }, $skills);

        if ($matchedIds === []) {
            return $mapped;
        }

        $addedSkillIds = StudentSkill::where('student_id', $cv->student_id)
            ->whereIn('skill_id', $matchedIds)
            ->pluck('skill_id')
            ->all();

        return array_map(function (array $skill) use ($addedSkillIds) {
            $skill['already_added'] = $skill['skill_id'] !== null
                && in_array($skill['skill_id'], $addedSkillIds, true);

            return $skill;
        }, $mapped);
    }
}
