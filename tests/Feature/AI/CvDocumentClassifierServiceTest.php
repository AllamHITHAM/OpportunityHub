<?php

namespace Tests\Feature\AI;

use App\Exceptions\AiSkillExtractionException;
use App\Services\CvDocumentClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 8A-6.2: CvDocumentClassifierService in isolation. Every test fakes
 * the outbound HTTP call -- the real Groq API is never called in
 * automated tests, exactly like AiSkillExtractionServiceTest.
 */
class CvDocumentClassifierServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.groq.api_key', 'test-api-key');
        Config::set('services.groq.model', 'openai/gpt-oss-120b');
        // Phase 8A-6.3: real retry backoff is real wall-clock seconds --
        // tests exercise the retry count/logic, never the real delay.
        Config::set('services.groq.retry_delays_ms', [0, 0]);
    }

    public function test_a_cv_like_response_returns_true(): void
    {
        $this->fakeClassifier(true);

        $result = app(CvDocumentClassifierService::class)->isCv('John Doe. Education: BSc Computer Science. Experience: Intern at Acme.');

        $this->assertTrue($result);
    }

    public function test_a_non_cv_response_returns_false(): void
    {
        $this->fakeClassifier(false);

        $result = app(CvDocumentClassifierService::class)->isCv('Chapter 4: Introduction to Object-Oriented Programming in Java and C++.');

        $this->assertFalse($result);
    }

    public function test_the_request_is_sent_to_the_groq_endpoint(): void
    {
        $this->fakeClassifier(true);

        app(CvDocumentClassifierService::class)->isCv('...');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions');
    }

    public function test_the_request_carries_a_bearer_authorization_header(): void
    {
        $this->fakeClassifier(true);

        app(CvDocumentClassifierService::class)->isCv('...');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_the_configured_model_is_sent_in_the_request(): void
    {
        Config::set('services.groq.model', 'openai/gpt-oss-20b');
        $this->fakeClassifier(true);

        app(CvDocumentClassifierService::class)->isCv('...');

        Http::assertSent(fn ($request) => $request->data()['model'] === 'openai/gpt-oss-20b');
    }

    public function test_only_the_given_text_is_sent_never_any_student_data(): void
    {
        $this->fakeClassifier(true);

        app(CvDocumentClassifierService::class)->isCv('Skilled in AutoCAD, secret@example.com should never appear here anyway.');

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            $this->assertStringNotContainsString('match_score', $payload);
            $this->assertStringNotContainsString('password', $payload);

            return true;
        });
    }

    public function test_a_very_long_text_is_truncated_before_sending(): void
    {
        $this->fakeClassifier(true);
        $longText = str_repeat('A', 20000);

        app(CvDocumentClassifierService::class)->isCv($longText);

        Http::assertSent(function ($request) {
            $userMessage = collect($request->data()['messages'])->firstWhere('role', 'user');

            $this->assertLessThanOrEqual(6000, strlen($userMessage['content']));

            return true;
        });
    }

    public function test_malformed_json_in_the_response_throws(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'not valid json {{{']],
                ],
            ], 200),
        ]);

        $this->expectException(AiSkillExtractionException::class);
        app(CvDocumentClassifierService::class)->isCv('...');
    }

    public function test_a_response_missing_the_is_cv_key_throws(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode(['document_type' => 'resume', 'reason' => 'x']),
                    ],
                ]],
            ], 200),
        ]);

        $this->expectException(AiSkillExtractionException::class);
        app(CvDocumentClassifierService::class)->isCv('...');
    }

    public function test_a_non_boolean_is_cv_value_throws(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode(['is_cv' => 'yes', 'document_type' => 'resume', 'reason' => 'x']),
                    ],
                ]],
            ], 200),
        ]);

        $this->expectException(AiSkillExtractionException::class);
        app(CvDocumentClassifierService::class)->isCv('...');
    }

    public function test_a_5xx_from_the_provider_throws(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'server error']], 500)]);

        $this->expectException(AiSkillExtractionException::class);
        app(CvDocumentClassifierService::class)->isCv('...');
    }

    // -----------------------------------------------------------------
    // Phase 8A-6.3: retry-with-backoff for transient provider outcomes
    // -----------------------------------------------------------------

    public function test_a_429_is_retried_and_succeeds(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'rate limited']], 429)
                ->push($this->classifierResponseBody(true)),
        ]);

        $result = app(CvDocumentClassifierService::class)->isCv('...');

        $this->assertTrue($result);
        Http::assertSentCount(2);
    }

    public function test_a_5xx_is_retried_and_succeeds(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'down']], 503)
                ->push($this->classifierResponseBody(false)),
        ]);

        $result = app(CvDocumentClassifierService::class)->isCv('...');

        $this->assertFalse($result);
        Http::assertSentCount(2);
    }

    public function test_a_401_is_never_retried(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'unauthorized']], 401)]);

        try {
            app(CvDocumentClassifierService::class)->isCv('...');
        } catch (AiSkillExtractionException) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_retries_are_exhausted_after_three_attempts_then_fails_cleanly(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        try {
            app(CvDocumentClassifierService::class)->isCv('...');
            $this->fail('Expected AiSkillExtractionException.');
        } catch (AiSkillExtractionException $e) {
            $this->assertSame(
                'AI skill extraction is currently unavailable. Please try again later.',
                $e->getMessage(),
            );
        }

        Http::assertSentCount(3);
    }

    public function test_a_connection_failure_throws(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(AiSkillExtractionException::class);
        app(CvDocumentClassifierService::class)->isCv('...');
    }

    public function test_missing_api_key_configuration_throws_without_calling_the_provider(): void
    {
        Config::set('services.groq.api_key', null);
        Http::fake();

        $this->expectException(AiSkillExtractionException::class);
        app(CvDocumentClassifierService::class)->isCv('...');

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function fakeClassifier(bool $isCv): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response($this->classifierResponseBody($isCv), 200),
        ]);
    }

    /**
     * The raw chat-completion response body an `Http::sequence()` entry
     * needs — used directly whenever a test needs to sequence a failing
     * attempt followed by a real classification result.
     */
    private function classifierResponseBody(bool $isCv): array
    {
        return [
            'id' => 'chatcmpl-test',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode([
                        'is_cv' => $isCv,
                        'document_type' => $isCv ? 'resume' : 'other',
                        'reason' => $isCv ? 'Contains education and experience sections.' : 'Reads as instructional text, not a personal history.',
                    ]),
                ],
                'finish_reason' => 'stop',
            ]],
        ];
    }
}
