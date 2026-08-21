<?php

namespace Tests\Feature\AI;

use App\Models\CV;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\StudentSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonPath('message', 'Text could not be extracted from this CV.');
        Http::assertNothingSent();
    }

    public function test_a_cv_with_blank_parsed_text_returns_a_controlled_422(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);
        $cv = $this->cvFor($student->profile->id, '   ');

        $response = $this->postJson("/api/student/cvs/{$cv->id}/extract-skills");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Text could not be extracted from this CV.');
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
    // Helpers
    // -----------------------------------------------------------------

    private function fakeGroq(array $skillsWithConfidence): void
    {
        $skills = [];
        foreach ($skillsWithConfidence as $name => $confidence) {
            $skills[] = ['name' => $name, 'confidence' => $confidence];
        }

        Http::fake([
            'api.groq.com/*' => Http::response([
                'id' => 'chatcmpl-test',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode(['skills' => $skills]),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);
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
