<?php

namespace Tests\Feature\AI;

use App\Exceptions\AiSkillExtractionException;
use App\Models\CV;
use App\Models\CvSkillEvidence;
use App\Models\Skill;
use App\Models\SkillSuggestion;
use App\Models\StudentProfile;
use App\Models\StudentSkill;
use App\Models\User;
use App\Services\AiSkillExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 8A-6: AiSkillExtractionService. Every test fakes the outbound HTTP
 * call via Http::fake() -- the real Groq API is never called in automated
 * tests (see the phase report for the separate, manual, credential-gated
 * smoke test).
 */
class AiSkillExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.groq.api_key', 'test-api-key');
        Config::set('services.groq.model', 'openai/gpt-oss-120b');
    }

    public function test_a_successful_structured_response_is_returned(): void
    {
        Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        $cv = $this->cvWithParsedText('Proficient in AutoCAD and Revit.');

        $this->fakeGroq(['AutoCAD' => 0.95]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertCount(1, $skills);
        $this->assertSame('AutoCAD', $skills[0]['name']);
        $this->assertSame(0.95, $skills[0]['confidence']);
    }

    public function test_the_request_is_sent_to_the_groq_endpoint(): void
    {
        $cv = $this->cvWithParsedText('...');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        app(AiSkillExtractionService::class)->extractSkills($cv);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions');
    }

    public function test_the_request_carries_a_bearer_authorization_header(): void
    {
        $cv = $this->cvWithParsedText('...');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        app(AiSkillExtractionService::class)->extractSkills($cv);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_the_configured_model_is_sent_in_the_request(): void
    {
        Config::set('services.groq.model', 'openai/gpt-oss-20b');
        $cv = $this->cvWithParsedText('...');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        app(AiSkillExtractionService::class)->extractSkills($cv);

        Http::assertSent(fn ($request) => $request->data()['model'] === 'openai/gpt-oss-20b');
    }

    public function test_dedup_is_case_insensitive_and_keeps_the_highest_confidence(): void
    {
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroqRaw([
            'skills' => [
                ['name' => 'python', 'confidence' => 0.4],
                ['name' => 'Python', 'confidence' => 0.9],
                ['name' => 'PYTHON', 'confidence' => 0.6],
            ],
        ]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertCount(1, $skills);
        $this->assertSame(0.9, $skills[0]['confidence']);
    }

    public function test_output_is_capped_at_twenty_skills(): void
    {
        $cv = $this->cvWithParsedText('...');

        $entries = [];
        for ($i = 0; $i < 30; $i++) {
            $entries[] = ['name' => "Skill {$i}", 'confidence' => $i / 30];
        }
        $this->fakeGroqRaw(['skills' => $entries]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertCount(20, $skills);
    }

    public function test_confidence_is_clamped_and_defaulted(): void
    {
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroqRaw([
            'skills' => [
                ['name' => 'Over', 'confidence' => 5],
                ['name' => 'Under', 'confidence' => -3],
                ['name' => 'NonNumeric', 'confidence' => 'high'],
            ],
        ]);

        $byName = collect(app(AiSkillExtractionService::class)->extractSkills($cv))->keyBy('name');

        $this->assertSame(1.0, $byName['Over']['confidence']);
        $this->assertSame(0.0, $byName['Under']['confidence']);
        $this->assertSame(0.0, $byName['NonNumeric']['confidence']);
    }

    public function test_blank_names_are_discarded(): void
    {
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroqRaw([
            'skills' => [
                ['name' => '   ', 'confidence' => 0.8],
                ['name' => 'Real Skill', 'confidence' => 0.8],
            ],
        ]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertCount(1, $skills);
        $this->assertSame('Real Skill', $skills[0]['name']);
    }

    public function test_an_existing_catalog_skill_is_marked_available_with_its_id(): void
    {
        $skill = Skill::create(['name' => 'Microsoft Excel', 'category' => 'office']);
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroq(['Microsoft Excel' => 0.7]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertSame($skill->id, $skills[0]['skill_id']);
        $this->assertTrue($skills[0]['is_available']);
        $this->assertNull($skills[0]['suggestion_id']);
        $this->assertNull($skills[0]['suggestion_status']);
    }

    public function test_a_baseline_catalog_skill_creates_no_suggestion(): void
    {
        Skill::create(['name' => 'Microsoft Excel', 'category' => 'office']);
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroq(['Microsoft Excel' => 0.7]);

        app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertDatabaseCount('skill_suggestions', 0);
    }

    public function test_an_existing_catalog_match_records_cv_skill_evidence(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        $cv = $this->cvWithParsedText('Proficient in AutoCAD.', $student->profile->id);

        $this->fakeGroq(['AutoCAD' => 0.9]);

        app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertDatabaseHas('cv_skill_evidence', [
            'cv_id' => $cv->id,
            'skill_id' => $skill->id,
            'student_id' => $student->profile->id,
        ]);
    }

    public function test_a_repeated_extraction_reuses_the_same_evidence_row_not_a_duplicate(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        $cv = $this->cvWithParsedText('Proficient in AutoCAD.', $student->profile->id);

        $this->fakeGroq(['AutoCAD' => 0.9]);
        app(AiSkillExtractionService::class)->extractSkills($cv);
        app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertSame(1, CvSkillEvidence::where('cv_id', $cv->id)->where('skill_id', $skill->id)->count());
    }

    public function test_an_unmatched_skill_has_a_null_skill_id_and_is_unavailable(): void
    {
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroq(['Primavera P6' => 0.6]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertNull($skills[0]['skill_id']);
        $this->assertFalse($skills[0]['is_available']);
    }

    public function test_an_unmatched_skill_creates_exactly_one_pending_suggestion(): void
    {
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroq(['Primavera P6' => 0.6]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertNotNull($skills[0]['suggestion_id']);
        $this->assertSame('pending', $skills[0]['suggestion_status']);
        $this->assertDatabaseCount('skill_suggestions', 1);
        $this->assertDatabaseHas('skill_suggestions', [
            'name' => 'Primavera P6',
            'normalized_name' => 'primavera p6',
            'source' => 'ai_cv',
            'status' => 'pending',
        ]);
    }

    public function test_a_repeated_extraction_reuses_the_same_pending_suggestion(): void
    {
        $cv = $this->cvWithParsedText('...');
        $this->fakeGroq(['Primavera P6' => 0.6]);
        $first = app(AiSkillExtractionService::class)->extractSkills($cv);

        $second = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertSame($first[0]['suggestion_id'], $second[0]['suggestion_id']);
        $this->assertDatabaseCount('skill_suggestions', 1);
    }

    public function test_a_different_students_extraction_reuses_the_same_pending_suggestion(): void
    {
        $studentA = $this->studentWithProfile();
        $studentB = $this->studentWithProfile();
        $cvA = $this->cvWithParsedText('...', $studentA->profile->id);
        $cvB = $this->cvWithParsedText('...', $studentB->profile->id);
        $this->fakeGroq(['Primavera P6' => 0.6]);
        $first = app(AiSkillExtractionService::class)->extractSkills($cvA);

        $second = app(AiSkillExtractionService::class)->extractSkills($cvB);

        $this->assertSame($first[0]['suggestion_id'], $second[0]['suggestion_id']);
        $this->assertDatabaseCount('skill_suggestions', 1);
    }

    public function test_case_and_whitespace_variations_dedup_to_the_same_suggestion(): void
    {
        $cv = $this->cvWithParsedText('...');
        $this->fakeGroq(['primavera p6' => 0.5]);
        $first = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->fakeGroq(['  PRIMAVERA P6  ' => 0.6]);
        $second = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertSame($first[0]['suggestion_id'], $second[0]['suggestion_id']);
        $this->assertDatabaseCount('skill_suggestions', 1);
    }

    public function test_once_a_suggestion_is_approved_a_later_extraction_resolves_it_as_catalog_available(): void
    {
        $cv = $this->cvWithParsedText('...');
        $this->fakeGroq(['Primavera P6' => 0.6]);
        $first = app(AiSkillExtractionService::class)->extractSkills($cv);
        $suggestion = SkillSuggestion::find($first[0]['suggestion_id']);
        $approvedSkill = Skill::create(['name' => 'Primavera P6']);
        $suggestion->update(['status' => 'approved', 'approved_skill_id' => $approvedSkill->id]);

        $second = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertSame($approvedSkill->id, $second[0]['skill_id']);
        $this->assertTrue($second[0]['is_available']);
        $this->assertNull($second[0]['suggestion_id']);
    }

    public function test_a_skill_the_student_already_has_is_marked_already_added(): void
    {
        $student = $this->studentWithProfile();
        $skill = Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        StudentSkill::create([
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
            'level' => 'intermediate',
        ]);
        $cv = $this->cvWithParsedText('...', $student->profile->id);

        $this->fakeGroq(['AutoCAD' => 0.9]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertTrue($skills[0]['already_added']);
    }

    public function test_a_skill_the_student_does_not_have_yet_is_not_marked_already_added(): void
    {
        Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroq(['AutoCAD' => 0.9]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertFalse($skills[0]['already_added']);
    }

    public function test_an_empty_skills_array_returns_an_empty_result(): void
    {
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroqRaw(['skills' => []]);

        $skills = app(AiSkillExtractionService::class)->extractSkills($cv);

        $this->assertSame([], $skills);
    }

    public function test_malformed_json_in_the_response_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'not valid json {{{']],
                ],
            ], 200),
        ]);

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_a_response_missing_the_skills_key_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        $this->fakeGroqRaw(['unexpected' => 'shape']);

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_a_response_missing_the_choices_array_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        Http::fake(['api.groq.com/*' => Http::response(['id' => 'chatcmpl-123'], 200)]);

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_a_401_from_the_provider_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'unauthorized']], 401)]);

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_a_403_from_the_provider_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'forbidden']], 403)]);

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_a_429_rate_limit_from_the_provider_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_a_5xx_from_the_provider_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'server error']], 500)]);

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_a_connection_failure_throws(): void
    {
        $cv = $this->cvWithParsedText('...');

        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);
    }

    public function test_missing_api_key_configuration_throws_without_calling_the_provider(): void
    {
        Config::set('services.groq.api_key', null);
        $cv = $this->cvWithParsedText('...');

        Http::fake();

        $this->expectException(AiSkillExtractionException::class);
        app(AiSkillExtractionService::class)->extractSkills($cv);

        Http::assertNothingSent();
    }

    public function test_only_the_parsed_text_is_sent_to_the_provider_never_sensitive_student_data(): void
    {
        $student = $this->studentWithProfile('secret@example.com');
        $cv = $this->cvWithParsedText('Skilled in AutoCAD.', $student->profile->id);

        $this->fakeGroq(['AutoCAD' => 0.9]);

        app(AiSkillExtractionService::class)->extractSkills($cv);

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            $this->assertStringNotContainsString('secret@example.com', $payload);
            $this->assertStringNotContainsString('password', $payload);
            $this->assertStringNotContainsString('match_score', $payload);
            $this->assertStringContainsString('Skilled in AutoCAD.', $payload);

            return true;
        });
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function fakeGroq(array $skillsWithConfidence): void
    {
        $skills = [];
        foreach ($skillsWithConfidence as $name => $confidence) {
            $skills[] = ['name' => $name, 'confidence' => $confidence];
        }

        $this->fakeGroqRaw(['skills' => $skills]);
    }

    private function fakeGroqRaw(array $decodedSkillsPayload): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'id' => 'chatcmpl-test',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode($decodedSkillsPayload),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);
    }

    private function cvWithParsedText(string $text, ?int $studentId = null): CV
    {
        $studentId ??= $this->studentWithProfile()->profile->id;

        return CV::create([
            'student_id' => $studentId,
            'title' => 'My CV',
            'file_path' => "cvs/{$studentId}/irrelevant.pdf",
            'parsed_text' => $text,
        ]);
    }

    private function studentWithProfile(?string $email = null): object
    {
        $user = User::factory()->create(array_merge([
            'role' => 'student',
            'status' => 'active',
        ], $email !== null ? ['email' => $email] : []));

        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
