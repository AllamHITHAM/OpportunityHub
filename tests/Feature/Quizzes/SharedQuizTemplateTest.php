<?php

namespace Tests\Feature\Quizzes;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10A.4B — the shared Quiz template: one Quiz definition authored
 * once per Opportunity (`quizzes.opportunity_id`) and referenced by every
 * candidate's own Assessment (`assessments.quiz_id`), instead of each
 * candidate getting a private copy. Covers the Opportunity-level
 * create/update/publish/results endpoints, candidate advancement
 * (`AssessmentService::advanceToSharedQuiz()`), and the isolation
 * guarantees a shared Quiz must never weaken: separate scores, separate
 * answers, separate decisions, separate release timing per candidate.
 * `NextActionDecisionTest`/`ResultReleaseTest` already cover decision-aware
 * release itself in depth; this file only re-confirms it stays
 * candidate-scoped once the underlying Quiz is shared.
 */
class SharedQuizTemplateTest extends TestCase
{
    use RefreshDatabase;

    // ---- Opportunity-level template CRUD ---------------------------------

    public function test_organization_can_create_a_shared_quiz_template_for_an_opportunity(): void
    {
        $scenario = $this->quizOpportunityScenario();

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/opportunities/{$scenario['opportunity']->id}/quiz",
            $this->validQuizPayload(),
        );

        $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
        $this->assertSame(
            $scenario['opportunity']->id,
            Quiz::find($response->json('data.id'))->opportunity_id,
        );
    }

    public function test_a_second_shared_quiz_template_is_rejected(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->createSharedQuiz($scenario);

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson(
            "/api/organization/opportunities/{$scenario['opportunity']->id}/quiz",
            $this->validQuizPayload(),
        )->assertStatus(409);
    }

    public function test_another_organization_cannot_access_this_opportunitys_quiz(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->createSharedQuiz($scenario);
        $otherOrg = $this->approvedOrganization();

        Sanctum::actingAs($otherOrg->user);
        $this->getJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz")
            ->assertStatus(404);
        $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'Hijacked?',
            'type' => 'true_false',
            'correct_answer' => 'True',
        ])->assertStatus(404);
    }

    public function test_publishing_requires_at_least_one_question(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->createSharedQuiz($scenario);

        Sanctum::actingAs($scenario['org']->user);
        $this->putJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/publish")
            ->assertStatus(422);
    }

    public function test_publishing_the_shared_quiz_succeeds_with_a_question(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->createSharedQuiz($scenario);
        $this->addQuestion($scenario, $quiz);

        Sanctum::actingAs($scenario['org']->user);
        $this->putJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/publish")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'published');
    }

    public function test_published_shared_quiz_settings_cannot_be_modified(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);

        Sanctum::actingAs($scenario['org']->user);
        $this->putJson(
            "/api/organization/opportunities/{$scenario['opportunity']->id}/quiz",
            $this->validQuizPayload(['title' => 'Changed Title']),
        )->assertStatus(422);
        $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'New question after publish?',
            'type' => 'true_false',
            'correct_answer' => 'True',
        ])->assertStatus(422);
    }

    // ---- Candidate advancement --------------------------------------------

    public function test_advancing_a_candidate_before_the_quiz_is_published_is_rejected(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->createSharedQuiz($scenario);
        $candidate = $this->shortlistedApplication($scenario['opportunity']);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/applications/{$candidate['application']->id}/quiz-assessment",
        );

        $response->assertStatus(422)
            ->assertJsonPath('message', "This opportunity's quiz is not published yet.");
    }

    public function test_advancing_two_candidates_creates_no_new_quiz_rows(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $a = $this->shortlistedApplication($scenario['opportunity']);
        $b = $this->shortlistedApplication($scenario['opportunity']);

        Sanctum::actingAs($scenario['org']->user);
        $this->assertSame(1, Quiz::count());

        $responseA = $this->postJson("/api/organization/applications/{$a['application']->id}/quiz-assessment");
        $responseB = $this->postJson("/api/organization/applications/{$b['application']->id}/quiz-assessment");

        $responseA->assertStatus(201)->assertJsonPath('data.quiz.id', $quiz->id);
        $responseB->assertStatus(201)->assertJsonPath('data.quiz.id', $quiz->id);
        $this->assertSame(1, Quiz::count());
        $this->assertSame($quiz->id, Assessment::find($responseA->json('data.id'))->quiz_id);
        $this->assertSame($quiz->id, Assessment::find($responseB->json('data.id'))->quiz_id);
    }

    public function test_advancing_a_candidate_sends_the_quiz_available_notification_only_to_that_candidate(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $a = $this->shortlistedApplication($scenario['opportunity']);
        $b = $this->shortlistedApplication($scenario['opportunity']);

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson("/api/organization/applications/{$a['application']->id}/quiz-assessment")
            ->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $a['student']->user->id,
            'title' => 'Quiz Available',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $b['student']->user->id,
            'title' => 'Quiz Available',
        ]);
    }

    // ---- Per-candidate isolation on a shared quiz -------------------------

    public function test_two_candidates_on_the_same_shared_quiz_get_independent_attempts_and_scores(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $a = $this->advanceCandidate($scenario, $quiz);
        $b = $this->advanceCandidate($scenario, $quiz);

        Sanctum::actingAs($a['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(200);

        Sanctum::actingAs($b['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'False']],
        ])->assertStatus(200);

        $aAttempt = Assessment::find($a['assessment']->id)->fresh();
        $bAttempt = Assessment::find($b['assessment']->id)->fresh();
        $this->assertSame('passed', $aAttempt->result);
        $this->assertSame('failed', $bAttempt->result);
    }

    public function test_a_student_cannot_start_a_shared_quiz_they_were_never_advanced_to(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $this->advanceCandidate($scenario, $quiz);
        $notAdvanced = $this->studentWithProfileAndCv();

        Sanctum::actingAs($notAdvanced->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(404);
    }

    public function test_organizations_single_candidate_quiz_view_never_leaks_another_candidates_attempt(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $a = $this->advanceCandidate($scenario, $quiz);
        $b = $this->advanceCandidate($scenario, $quiz);

        Sanctum::actingAs($a['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->getJson("/api/organization/assessments/{$b['assessment']->id}/quiz");

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.attempts'));
    }

    public function test_correct_answer_is_hidden_from_the_student_on_a_shared_quiz(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $a = $this->advanceCandidate($scenario, $quiz);

        Sanctum::actingAs($a['student']->user);
        $response = $this->getJson("/api/student/assessments/{$a['assessment']->id}/quiz");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('correct_answer', $response->json('data.questions.0'));
    }

    /**
     * Test matrix item 18 (section 33): candidate A may release while
     * candidate B remains pending, even though both share one Quiz row.
     */
    public function test_one_candidates_release_never_affects_another_candidate_on_the_same_shared_quiz(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario, releaseMode: 'manual');
        $a = $this->advanceCandidate($scenario, $quiz);
        $b = $this->advanceCandidate($scenario, $quiz);

        foreach ([$a, $b] as $candidate) {
            Sanctum::actingAs($candidate['student']->user);
            $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
            $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
                'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
            ])->assertStatus(200);
        }

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson("/api/organization/assessments/{$a['assessment']->id}/next-action/reject")
            ->assertStatus(200);
        $this->putJson("/api/organization/assessments/{$a['assessment']->id}/release-result")
            ->assertStatus(200);

        $this->assertNotNull(Assessment::find($a['assessment']->id)->result_released_at);
        $this->assertNull(Assessment::find($b['assessment']->id)->result_released_at);
    }

    // ---- Results dashboard --------------------------------------------------

    public function test_results_endpoint_returns_every_candidates_score_result_and_decision(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $a = $this->advanceCandidate($scenario, $quiz);
        $b = $this->advanceCandidate($scenario, $quiz);

        Sanctum::actingAs($a['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->getJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/results");

        $response->assertStatus(200);
        $rows = collect($response->json('data.candidates'))->keyBy('application_id');
        $this->assertSame('passed', $rows[$a['application']->id]['result']);
        $this->assertSame(100, $rows[$a['application']->id]['score']);
        $this->assertNull($rows[$b['application']->id]['result']);
        $this->assertNull($rows[$b['application']->id]['score']);
    }

    /**
     * Submission Timestamp Fix — the results row's own real
     * `quiz_attempts.submitted_at` (the table's only "submitted" signal),
     * not a fabricated value. Not-yet-submitted candidate B must get
     * `null`, never a duplicated/guessed value.
     */
    public function test_results_endpoint_exposes_the_real_submitted_at_timestamp(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $a = $this->advanceCandidate($scenario, $quiz);
        $b = $this->advanceCandidate($scenario, $quiz);

        Sanctum::actingAs($a['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->getJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/results");

        $response->assertStatus(200);
        $rows = collect($response->json('data.candidates'))->keyBy('application_id');
        $submittedAt = $rows[$a['application']->id]['submitted_at'];
        $this->assertNotNull($submittedAt);
        $this->assertEqualsWithDelta(
            now()->timestamp,
            \Carbon\Carbon::parse($submittedAt)->timestamp,
            5,
        );
        $this->assertNull($rows[$b['application']->id]['submitted_at']);
    }

    public function test_results_endpoint_never_exposes_answers_or_correct_answers(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $this->advanceCandidate($scenario, $quiz);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->getJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/results");

        $response->assertStatus(200);
        $raw = $response->getContent();
        $this->assertStringNotContainsString('correct_answer', $raw);
        $this->assertStringNotContainsString('"answers"', $raw);
    }

    public function test_another_organization_cannot_access_the_results_endpoint(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $otherOrg = $this->approvedOrganization();

        Sanctum::actingAs($otherOrg->user);
        $this->getJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/results")
            ->assertStatus(404);
    }

    public function test_a_student_cannot_access_the_results_endpoint(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($student->user);
        $this->getJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/results")
            ->assertStatus(403);
    }

    // ---- Scenario builders ------------------------------------------------

    /**
     * @return array{org: object, opportunity: Opportunity}
     */
    private function quizOpportunityScenario(): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
            'recruitment_process' => 'quiz',
        ]);

        return ['org' => $org, 'opportunity' => $opportunity];
    }

    private function validQuizPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Fundamentals',
            'passing_score' => 70,
            // Phase 10A.4B addendum — required for every shared template.
            'availability_delay_days' => 2,
            'availability_time' => '10:00',
            'submission_window_hours' => 48,
        ], $overrides);
    }

    private function createSharedQuiz(array $scenario): Quiz
    {
        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/opportunities/{$scenario['opportunity']->id}/quiz",
            $this->validQuizPayload(),
        )->assertStatus(201);

        return Quiz::find($response->json('data.id'));
    }

    private function addQuestion(array $scenario, Quiz $quiz): void
    {
        Sanctum::actingAs($scenario['org']->user);
        $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'Is this a true/false question?',
            'type' => 'true_false',
            'correct_answer' => 'True',
        ])->assertStatus(201);
    }

    /**
     * Creates, adds one question to, and publishes the shared Quiz. Stores
     * the created Question on `$scenario['question']` (by-reference-ish via
     * returning the Quiz only, callers re-fetch the question from
     * `Quiz::questions`) so `advanceCandidate()`'s submit helper can answer
     * it deterministically.
     */
    private function publishedSharedQuiz(array &$scenario, string $releaseMode = 'immediate'): Quiz
    {
        $quiz = $this->createSharedQuiz($scenario);
        if ($releaseMode !== 'immediate') {
            $quiz->update(['result_release_mode' => $releaseMode]);
        }
        $this->addQuestion($scenario, $quiz);
        $quiz->refresh();
        $scenario['question'] = $quiz->questions->first();

        Sanctum::actingAs($scenario['org']->user);
        $this->putJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/publish")
            ->assertStatus(200);

        return $quiz->fresh();
    }

    /**
     * @return array{student: object, application: Application}
     */
    private function shortlistedApplication(Opportunity $opportunity, ?object $student = null): array
    {
        $student = $student ?? $this->studentWithProfileAndCv();

        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
        $application->status = 'shortlisted';
        $application->save();

        return ['student' => $student, 'application' => $application->fresh()];
    }

    /**
     * Shortlists a fresh candidate and advances them to the (already
     * published) shared quiz in one step.
     *
     * @return array{student: object, application: Application, assessment: Assessment}
     */
    private function advanceCandidate(array $scenario, Quiz $quiz): array
    {
        $candidate = $this->shortlistedApplication($scenario['opportunity']);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/applications/{$candidate['application']->id}/quiz-assessment",
        )->assertStatus(201);

        $assessment = Assessment::find($response->json('data.id'));
        // This file predates the Phase 10A.4B addendum's availability window
        // and only exercises shared-quiz isolation/CRUD behavior, not timing
        // -- force the candidate's frozen window open immediately so
        // start()/submit() behave as they did before the addendum. Timing
        // itself is covered by its own dedicated test file.
        $assessment->forceFill([
            'available_at' => now()->subMinute(),
            'due_at' => now()->addDays(30),
        ])->save();

        return [
            'student' => $candidate['student'],
            'application' => $candidate['application'],
            'assessment' => $assessment->fresh(),
        ];
    }

    private function approvedOrganization(): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Hiring Co',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $cv = $profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/my-cv.pdf']);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }
}
