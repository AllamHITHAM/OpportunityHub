<?php

namespace Tests\Feature\AI;

use App\Models\CV;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\StudentSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-6: `POST /api/student/cvs/{cv}/extract-skills`. Fakes the
 * outbound AI call throughout -- see AiSkillExtractionServiceTest for the
 * service's own scenario coverage; this file covers ownership, profile,
 * parsed_text-availability, response-shape, and no-mutation guarantees at
 * the HTTP layer.
 */
class CvSkillExtractionEndpointTest extends TestCase
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

    public function test_the_owning_student_can_extract_skills_from_their_own_cv(): void
    {
        Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, 'Proficient in AutoCAD.');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.skills.0.name', 'AutoCAD')
            ->assertJsonPath('data.skills.0.confidence', 0.9)
            ->assertJsonPath('data.skills.0.is_available', true)
            ->assertJsonPath('data.skills.0.already_added', false)
            ->assertJsonPath('data.skills.0.suggestion_id', null)
            ->assertJsonPath('data.skills.0.suggestion_status', null);
    }

    public function test_an_unmatched_skill_returns_a_pending_suggestion(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, 'Skilled in Primavera P6.');
        $this->fakeGroq(['Primavera P6' => 0.6]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200)
            ->assertJsonPath('data.skills.0.skill_id', null)
            ->assertJsonPath('data.skills.0.is_available', false)
            ->assertJsonPath('data.skills.0.suggestion_status', 'pending');
        $this->assertNotNull($response->json('data.skills.0.suggestion_id'));
        $this->assertDatabaseCount('skill_suggestions', 1);
    }

    public function test_a_student_cannot_extract_skills_from_another_students_cv(): void
    {
        $owner = $this->studentWithProfile();
        $requester = $this->studentWithProfile();
        Sanctum::actingAs($requester->user);
        $cv = $this->cvFor($owner->profile->id, 'Some text.');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_a_non_student_role_is_rejected(): void
    {
        $org = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        Sanctum::actingAs($org);
        $student = $this->studentWithProfile();
        $cv = $this->cvFor($student->profile->id, 'Some text.');

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $student = $this->studentWithProfile();
        $cv = $this->cvFor($student->profile->id, 'Some text.');

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(401);
    }

    public function test_a_student_without_a_completed_profile_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($user);
        $other = $this->studentWithProfile();
        $cv = $this->cvFor($other->profile->id, 'Some text.');

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(404);
    }

    public function test_a_cv_with_null_parsed_text_returns_a_controlled_422(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, null);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422)
            ->assertJsonPath('message', "We couldn't find enough readable text in this PDF to analyze it.");
        Http::assertNothingSent();
    }

    public function test_a_cv_with_blank_parsed_text_returns_a_controlled_422(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, '   ');

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422)
            ->assertJsonPath('message', "We couldn't find enough readable text in this PDF to analyze it.");
        Http::assertNothingSent();
    }

    public function test_a_cv_with_nonblank_but_insufficient_text_returns_a_controlled_422(): void
    {
        // Phase 8A-6.2: short but non-empty text (e.g. a stray watermark
        // or a single heading picked up from an otherwise-image PDF) is
        // still rejected before any AI call, same as fully blank text.
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, 'Confidential.');

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422)
            ->assertJsonPath('message', "We couldn't find enough readable text in this PDF to analyze it.");
        Http::assertNothingSent();
    }

    public function test_a_legacy_cv_with_no_parsed_text_is_handled_safely_not_a_crash(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        // A pre-Phase-8A-5 row: a manually-typed legacy `file_path`, never
        // parsed -- parsed_text is null, exactly like the null case above.
        $cv = CV::create([
            'student_id' => $student->profile->id,
            'title' => 'Legacy CV',
            'file_path' => 'C:\\old\\resume.pdf',
            'parsed_text' => null,
        ]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422);
    }

    public function test_the_response_never_contains_parsed_text(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, 'Some very specific CV body text.');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200)->assertJsonMissingPath('data.parsed_text');
        $this->assertStringNotContainsString('Some very specific CV body text.', $response->getContent());
    }

    public function test_an_already_added_skill_is_flagged_in_the_response(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $skill = Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        StudentSkill::create([
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
            'level' => 'advanced',
        ]);
        $cv = $this->cvFor($student->profile->id, 'Proficient in AutoCAD.');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200)->assertJsonPath('data.skills.0.already_added', true);
    }

    public function test_extraction_never_creates_a_student_skill_row(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        Skill::create(['name' => 'AutoCAD', 'category' => 'design']);
        $cv = $this->cvFor($student->profile->id, 'Proficient in AutoCAD.');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $this->postJson("/api/student/cvs/{$cv->id}/extract-skills")->assertStatus(200);

        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_extraction_never_modifies_the_cv_row(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, 'Proficient in AutoCAD.');
        $this->fakeGroq(['AutoCAD' => 0.9]);
        $originalUpdatedAt = $cv->updated_at;

        $this->postJson("/api/student/cvs/{$cv->id}/extract-skills")->assertStatus(200);

        $this->assertSame($originalUpdatedAt->timestamp, $cv->fresh()->updated_at->timestamp);
        $this->assertSame('Proficient in AutoCAD.', $cv->fresh()->parsed_text);
    }

    public function test_existing_student_skills_are_unchanged_by_extraction(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $skill = Skill::create(['name' => 'Python', 'category' => 'programming']);
        $existing = StudentSkill::create([
            'student_id' => $student->profile->id,
            'skill_id' => $skill->id,
            'level' => 'expert',
            'years_of_experience' => 5,
        ]);
        $cv = $this->cvFor($student->profile->id, 'Proficient in AutoCAD.');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $this->postJson("/api/student/cvs/{$cv->id}/extract-skills")->assertStatus(200);

        $this->assertDatabaseHas('student_skills', [
            'id' => $existing->id,
            'skill_id' => $skill->id,
            'level' => 'expert',
        ]);
    }

    public function test_a_provider_failure_leaves_all_database_state_unchanged(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, 'Proficient in AutoCAD.');
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(503);
        $this->assertDatabaseCount('student_skills', 0);
        $this->assertDatabaseHas('cvs', ['id' => $cv->id, 'title' => 'My CV']);
    }

    // -----------------------------------------------------------------
    // Phase 8A-6.2: CV/resume document-validation gate
    // -----------------------------------------------------------------

    public function test_a_technical_chapter_is_rejected_as_not_a_cv(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, <<<'TEXT'
            Chapter 4: Object-Oriented Programming Fundamentals

            This chapter introduces the core concepts of object-oriented
            programming using Java and C++. We begin by examining how
            classes encapsulate data and behavior. A MySQL database is
            used throughout the accompanying exercises to illustrate
            persistence. By the end of this chapter, the reader should
            understand inheritance, polymorphism, and encapsulation.
            TEXT);
        $this->fakeClassifierOnly(false);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422)->assertJsonPath(
            'message',
            "This document doesn't appear to be a CV or resume. Upload a CV to use AI Skill Analysis.",
        );
        Http::assertSentCount(1);
        $this->assertDatabaseCount('cv_skill_evidence', 0);
        $this->assertDatabaseCount('skill_suggestions', 0);
        $this->assertDatabaseCount('student_skills', 0);
    }

    public function test_lecture_notes_are_rejected_as_not_a_cv(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, <<<'TEXT'
            Lecture 9 — Introduction to Relational Databases

            Today we cover normalization, primary and foreign keys, and
            basic SQL queries. Students should review the MySQL
            documentation before next week's lab session. Homework:
            complete exercises 1 through 5 in the course workbook.
            TEXT);
        $this->fakeClassifierOnly(false);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422)->assertJsonPath(
            'message',
            "This document doesn't appear to be a CV or resume. Upload a CV to use AI Skill Analysis.",
        );
        Http::assertSentCount(1);
        $this->assertDatabaseCount('cv_skill_evidence', 0);
        $this->assertDatabaseCount('skill_suggestions', 0);
    }

    public function test_a_research_article_is_rejected_as_not_a_cv(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, <<<'TEXT'
            Abstract: This paper presents a comparative analysis of
            caching strategies in distributed systems built with Flutter
            and C++. We evaluate throughput and latency across several
            configurations and conclude that hybrid caching outperforms
            purely local strategies in high-concurrency scenarios.
            TEXT);
        $this->fakeClassifierOnly(false);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422)->assertJsonPath(
            'message',
            "This document doesn't appear to be a CV or resume. Upload a CV to use AI Skill Analysis.",
        );
        Http::assertSentCount(1);
    }

    public function test_a_normal_cv_passes_classification_and_reaches_extraction(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, <<<'TEXT'
            Jane Student
            jane.student@example.com

            Education
            BSc Computer Science, State University, 2022–2026

            Experience
            Backend Intern, Acme Corp, Summer 2025 — built REST APIs in Flutter and MySQL.

            Skills
            Flutter, MySQL, C++
            TEXT);
        $this->fakeGroq(['Flutter' => 0.9, 'MySQL' => 0.85, 'C++' => 0.8]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200)->assertJsonPath('success', true);
        Http::assertSentCount(2);
    }

    public function test_a_sparse_graduate_cv_with_no_work_experience_still_passes(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, <<<'TEXT'
            Ahmad Ali
            Recent Graduate

            Education
            BSc Civil Engineering, Tech University, 2022–2026

            Projects
            Final-year project: reinforced concrete design using AutoCAD.

            Skills
            AutoCAD, Project Management
            TEXT);
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_classifier_provider_failure_returns_a_temporary_error_not_a_rejection(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, 'Proficient in AutoCAD and structural design.');
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(503);
        $this->assertNotSame(
            "This document doesn't appear to be a CV or resume. Upload a CV to use AI Skill Analysis.",
            $response->json('message'),
        );
        $this->assertDatabaseCount('cv_skill_evidence', 0);
        $this->assertDatabaseCount('skill_suggestions', 0);
    }

    public function test_ownership_is_still_checked_before_the_classification_gate(): void
    {
        $owner = $this->studentWithProfile();
        $requester = $this->studentWithProfile();
        Sanctum::actingAs($requester->user);
        $cv = $this->cvFor($owner->profile->id, 'Proficient in AutoCAD and structural design, education and experience.');
        $this->fakeGroq(['AutoCAD' => 0.9]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(404);
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Phase 8A-6.3: concurrency lock + end-to-end retry
    // -----------------------------------------------------------------

    public function test_a_concurrent_analysis_of_the_same_cv_is_rejected_with_a_controlled_409(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor(
            $student->profile->id,
            'Proficient in AutoCAD and structural design, education and experience.',
        );
        $this->fakeGroq(['AutoCAD' => 0.9]);

        // Simulates another in-flight request already analyzing this exact CV.
        $lock = Cache::lock("cv-extract-skills:{$cv->id}", 90);
        $this->assertTrue($lock->get());

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(409)->assertJsonPath(
            'message',
            'This CV is already being analyzed. Please wait for it to finish.',
        );
        Http::assertNothingSent();

        $lock->release();
    }

    public function test_the_lock_is_released_after_completion_so_a_later_request_may_proceed(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor(
            $student->profile->id,
            'Proficient in AutoCAD and structural design, education and experience.',
        );
        $classifyResponse = $this->chatCompletionResponse([
            'is_cv' => true,
            'document_type' => 'resume',
            'reason' => 'Contains education and experience sections.',
        ]);
        $extractResponse = $this->chatCompletionResponse([
            'skills' => [['name' => 'AutoCAD', 'confidence' => 0.9]],
        ]);
        // One combined sequence covering both requests below (a fresh
        // classify+extract pair each) -- avoids relying on whether
        // re-calling Http::fake() mid-test fully resets prior state.
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push($classifyResponse)
                ->push($extractResponse)
                ->push($classifyResponse)
                ->push($extractResponse),
        ]);

        $this->postJson("/api/student/cvs/{$cv->id}/extract-skills")->assertStatus(200);

        // The lock was released after the first request finished -- a
        // second, later request for the same CV is never blocked.
        $this->postJson("/api/student/cvs/{$cv->id}/extract-skills")->assertStatus(200);
    }

    public function test_a_different_cvs_lock_does_not_block_this_one(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $lockedCv = $this->cvFor(
            $student->profile->id,
            'Proficient in AutoCAD and structural design, education and experience.',
        );
        $cv = $this->cvFor(
            $student->profile->id,
            'Skilled in MySQL, with education and project experience described here.',
        );
        $this->fakeGroq(['MySQL' => 0.9]);

        $lock = Cache::lock("cv-extract-skills:{$lockedCv->id}", 90);
        $this->assertTrue($lock->get());

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200);
        $lock->release();
    }

    public function test_a_transient_failure_partway_through_is_retried_then_succeeds_end_to_end(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor(
            $student->profile->id,
            'Proficient in AutoCAD and structural design, education and experience.',
        );
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                // classification succeeds on the first attempt...
                ->push($this->chatCompletionResponse([
                    'is_cv' => true,
                    'document_type' => 'resume',
                    'reason' => 'Contains education and experience sections.',
                ]))
                // ...extraction fails once transiently...
                ->push(['error' => ['message' => 'down']], 500)
                // ...then succeeds on retry.
                ->push($this->chatCompletionResponse(['skills' => [['name' => 'AutoCAD', 'confidence' => 0.9]]])),
        ]);

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(200)->assertJsonPath('data.skills.0.name', 'AutoCAD');
        Http::assertSentCount(3);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Fakes a passing classification followed by the given extraction
     * result — the real request-order the controller now makes (Phase
     * 8A-6.2: classify, then extract).
     */
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
            'api.groq.com/*' => Http::sequence()
                ->push($this->chatCompletionResponse([
                    'is_cv' => true,
                    'document_type' => 'resume',
                    'reason' => 'Contains education and experience sections.',
                ]))
                ->push($this->chatCompletionResponse($decodedSkillsPayload)),
        ]);
    }

    private function fakeClassifierOnly(bool $isCv): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response($this->chatCompletionResponse([
                'is_cv' => $isCv,
                'document_type' => $isCv ? 'resume' : 'other',
                'reason' => $isCv ? 'Contains education and experience sections.' : 'Reads as instructional text, not a personal history.',
            ]), 200),
        ]);
    }

    private function chatCompletionResponse(array $decodedPayload): array
    {
        return [
            'id' => 'chatcmpl-test',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($decodedPayload),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    private function cvFor(int $studentId, ?string $parsedText): CV
    {
        return CV::create([
            'student_id' => $studentId,
            'title' => 'My CV',
            'file_path' => "cvs/{$studentId}/irrelevant.pdf",
            'parsed_text' => $parsedText,
        ]);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
