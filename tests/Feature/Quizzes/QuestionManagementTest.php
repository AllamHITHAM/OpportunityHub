<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 6B-1: organization Question authoring (add/update/delete), draft
 * mutability, and ownership protection.
 */
class QuestionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_add_a_multiple_choice_question(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", $this->mcqPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Question added successfully')
            ->assertJsonPath('data.prompt', 'What is the capital of France?')
            ->assertJsonPath('data.type', 'multiple_choice')
            ->assertJsonPath('data.options', ['Paris', 'London', 'Berlin'])
            ->assertJsonPath('data.correct_answer', 'Paris')
            ->assertJsonPath('data.points', 1);

        $this->assertDatabaseHas('questions', [
            'quiz_id' => $quiz->id,
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'correct_answer' => 'Paris',
        ]);
    }

    public function test_organization_can_add_a_true_false_question(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'The sky is blue.',
            'type' => 'true_false',
            'correct_answer' => 'True',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'true_false')
            ->assertJsonPath('data.correct_answer', 'True')
            ->assertJsonPath('data.options', null);

        $this->assertDatabaseHas('questions', [
            'quiz_id' => $quiz->id,
            'type' => 'true_false',
            'correct_answer' => 'True',
            'options' => null,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function trueFalseCasingVariants(): array
    {
        return [
            'lowercase true' => ['true', 'True'],
            'uppercase TRUE' => ['TRUE', 'True'],
            'lowercase false' => ['false', 'False'],
            'uppercase FALSE' => ['FALSE', 'False'],
        ];
    }

    #[DataProvider('trueFalseCasingVariants')]
    public function test_true_false_correct_answer_is_canonicalized(string $submitted, string $expected): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'The sky is blue.',
            'type' => 'true_false',
            'correct_answer' => $submitted,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.correct_answer', $expected);
    }

    public function test_true_false_correct_answer_must_be_true_or_false(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'The sky is blue.',
            'type' => 'true_false',
            'correct_answer' => 'Maybe',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['correct_answer']);

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_multiple_choice_requires_at_least_two_options(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris'],
            'correct_answer' => 'Paris',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['options']);
    }

    public function test_multiple_choice_requires_options_at_all(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'correct_answer' => 'Paris',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['options']);
    }

    public function test_multiple_choice_correct_answer_must_match_a_submitted_option(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Madrid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['correct_answer']);

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_prompt_is_required(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'type' => 'true_false',
            'correct_answer' => 'True',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['prompt']);
    }

    public function test_type_must_be_multiple_choice_or_true_false(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'Explain polymorphism.',
            'type' => 'short_answer',
            'correct_answer' => 'n/a',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_points_must_be_at_least_one(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", array_merge(
            $this->mcqPayload(),
            ['points' => 0]
        ));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['points']);
    }

    public function test_points_defaults_to_one_when_omitted(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", $this->mcqPayload());

        $response->assertStatus(201)->assertJsonPath('data.points', 1);
    }

    public function test_position_defaults_to_zero_when_omitted(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", $this->mcqPayload());

        $response->assertStatus(201)->assertJsonPath('data.position', 0);
    }

    public function test_organization_can_update_a_question(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $question = $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/quizzes/{$quiz->id}/questions/{$question->id}",
            array_merge($this->mcqPayload(), ['prompt' => 'What is the capital of Germany?', 'correct_answer' => 'Berlin'])
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Question updated successfully')
            ->assertJsonPath('data.prompt', 'What is the capital of Germany?')
            ->assertJsonPath('data.correct_answer', 'Berlin');

        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'prompt' => 'What is the capital of Germany?',
            'correct_answer' => 'Berlin',
        ]);
    }

    public function test_organization_can_delete_a_question(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $question = $quiz->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($org->user);

        $response = $this->deleteJson("/api/organization/quizzes/{$quiz->id}/questions/{$question->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Question deleted successfully');

        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }

    public function test_wrong_organization_cannot_add_a_question(): void
    {
        $orgA = $this->approvedOrganization();
        $quiz = $this->quizFor($orgA);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", $this->mcqPayload());

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');

        $this->assertDatabaseCount('questions', 0);
    }

    public function test_wrong_organization_cannot_update_a_question(): void
    {
        $orgA = $this->approvedOrganization();
        $quiz = $this->quizFor($orgA);
        $question = $quiz->questions()->create($this->mcqAttributes());

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->putJson(
            "/api/organization/quizzes/{$quiz->id}/questions/{$question->id}",
            $this->mcqPayload()
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');
    }

    public function test_wrong_organization_cannot_delete_a_question(): void
    {
        $orgA = $this->approvedOrganization();
        $quiz = $this->quizFor($orgA);
        $question = $quiz->questions()->create($this->mcqAttributes());

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->deleteJson("/api/organization/quizzes/{$quiz->id}/questions/{$question->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Quiz not found');

        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_question_belonging_to_a_different_quiz_returns_404_on_update(): void
    {
        $org = $this->approvedOrganization();
        $quizA = $this->quizFor($org);
        $quizB = $this->quizFor($org);
        $questionOnA = $quizA->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/quizzes/{$quizB->id}/questions/{$questionOnA->id}",
            $this->mcqPayload()
        );

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Question not found');
    }

    public function test_question_belonging_to_a_different_quiz_returns_404_on_delete(): void
    {
        $org = $this->approvedOrganization();
        $quizA = $this->quizFor($org);
        $quizB = $this->quizFor($org);
        $questionOnA = $quizA->questions()->create($this->mcqAttributes());

        Sanctum::actingAs($org->user);

        $response = $this->deleteJson("/api/organization/quizzes/{$quizB->id}/questions/{$questionOnA->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Question not found');

        $this->assertDatabaseHas('questions', ['id' => $questionOnA->id]);
    }

    public function test_cannot_add_a_question_to_a_published_quiz(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $quiz->questions()->create($this->mcqAttributes());
        $quiz->status = 'published';
        $quiz->save();

        Sanctum::actingAs($org->user);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", $this->mcqPayload());

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Published quizzes cannot be modified');

        $this->assertDatabaseCount('questions', 1);
    }

    public function test_cannot_update_a_question_on_a_published_quiz(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $question = $quiz->questions()->create($this->mcqAttributes());
        $quiz->status = 'published';
        $quiz->save();

        Sanctum::actingAs($org->user);

        $response = $this->putJson(
            "/api/organization/quizzes/{$quiz->id}/questions/{$question->id}",
            array_merge($this->mcqPayload(), ['prompt' => 'Changed?'])
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Published quizzes cannot be modified');

        $this->assertDatabaseHas('questions', ['id' => $question->id, 'prompt' => 'What is the capital of France?']);
    }

    public function test_cannot_delete_a_question_on_a_published_quiz(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);
        $question = $quiz->questions()->create($this->mcqAttributes());
        $quiz->status = 'published';
        $quiz->save();

        Sanctum::actingAs($org->user);

        $response = $this->deleteJson("/api/organization/quizzes/{$quiz->id}/questions/{$question->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Published quizzes cannot be modified');

        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_guest_receives_401(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", $this->mcqPayload());

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_student_receives_403(): void
    {
        $org = $this->approvedOrganization();
        $quiz = $this->quizFor($org);

        $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($studentUser);

        $response = $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", $this->mcqPayload());

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function opportunityFor(object $org, array $overrides = []): Opportunity
    {
        return $org->profile->opportunities()->create(array_merge([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ], $overrides));
    }

    private function applicationFor(Opportunity $opportunity, string $status = 'shortlisted'): Application
    {
        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $studentProfile = StudentProfile::create(['user_id' => $studentUser->id]);

        $cv = $studentProfile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        $application = Application::create([
            'student_id' => $studentProfile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    private function quizFor(object $org): Quiz
    {
        $application = $this->applicationFor($this->opportunityFor($org), 'shortlisted');

        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'pending',
            'result' => null,
        ]);

        return $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'instructions' => 'Choose the best answer.',
            'time_limit_minutes' => 30,
            'passing_score' => 70,
            'status' => 'draft',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mcqPayload(): array
    {
        return [
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcqAttributes(): array
    {
        return array_merge($this->mcqPayload(), ['points' => 1, 'position' => 0]);
    }
}
