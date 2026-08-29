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
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10A.4B addendum -- each candidate's own frozen availability window
 * (`available_at`/`due_at`), computed once at "Advance to Quiz" time from
 * the shared Quiz's policy (`availability_delay_days`/`availability_time`/
 * `submission_window_hours`), and the Start/submit-time enforcement built
 * on top of it. `SharedQuizTemplateTest` already covers the shared-quiz
 * template itself (CRUD/publish/candidate isolation/results/ownership) in
 * depth; this file only covers the addendum's own timing behavior, and
 * does not re-duplicate coverage that already exists there.
 */
class QuizAvailabilityWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_candidate_assignment_calculates_available_at_and_due_at_correctly(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 15:00:00'));
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario, [
            'availability_delay_days' => 2,
            'availability_time' => '10:00',
            'submission_window_hours' => 48,
        ]);
        $candidate = $this->shortlistedApplication($scenario['opportunity']);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson("/api/organization/applications/{$candidate['application']->id}/quiz-assessment")
            ->assertStatus(201);

        $assessment = Assessment::find($response->json('data.id'));
        $this->assertSame('2026-01-12 10:00:00', $assessment->available_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-14 10:00:00', $assessment->due_at->format('Y-m-d H:i:s'));
    }

    public function test_two_candidates_assigned_on_different_dates_get_different_windows_on_the_same_shared_quiz(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);

        Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));
        $a = $this->advanceCandidateRaw($scenario);

        Carbon::setTestNow(Carbon::parse('2026-01-15 09:00:00'));
        $b = $this->advanceCandidateRaw($scenario);

        $aAssessment = $a['assessment']->fresh();
        $bAssessment = $b['assessment']->fresh();

        $this->assertFalse($aAssessment->available_at->eq($bAssessment->available_at));
        $this->assertFalse($aAssessment->due_at->eq($bAssessment->due_at));
    }

    public function test_advancing_multiple_candidates_creates_no_additional_questions(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);

        $this->advanceCandidateRaw($scenario);
        $this->advanceCandidateRaw($scenario);
        $this->advanceCandidateRaw($scenario);

        $this->assertSame(1, $scenario['quiz']->fresh()->questions()->count());
    }

    public function test_student_sees_the_real_schedule_immediately_after_assignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 15:00:00'));
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateRaw($scenario);

        Sanctum::actingAs($candidate['student']->user);
        $response = $this->getJson("/api/student/assessments/{$candidate['assessment']->id}/quiz");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.available_at'));
        $this->assertNotNull($response->json('data.due_at'));
    }

    public function test_questions_are_not_returned_before_the_availability_window_opens(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 15:00:00'));
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateRaw($scenario);

        Sanctum::actingAs($candidate['student']->user);
        $response = $this->getJson("/api/student/assessments/{$candidate['assessment']->id}/quiz");

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.questions'));
    }

    public function test_start_before_available_at_is_rejected(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-03 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-01 09:59:59'));
        Sanctum::actingAs($candidate['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This assessment is not available yet.');
    }

    public function test_start_after_due_at_with_no_attempt_is_rejected(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-03 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-03 10:00:01'));
        Sanctum::actingAs($candidate['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'The submission deadline for this assessment has passed.');
    }

    public function test_start_at_or_after_available_at_succeeds(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-03 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-01 10:00:00'));
        Sanctum::actingAs($candidate['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
    }

    public function test_submit_before_the_effective_cutoff_succeeds(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario, ['time_limit_minutes' => 60]);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-03 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-01 10:00:00'));
        Sanctum::actingAs($candidate['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-02-01 10:30:00'));
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(200);
    }

    public function test_submit_after_the_personal_time_limit_expires_is_rejected(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario, ['time_limit_minutes' => 10]);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-10 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-01 10:00:00'));
        Sanctum::actingAs($candidate['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-02-01 10:11:00'));
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(422)->assertJsonPath('message', 'Quiz time limit has expired');
    }

    /**
     * The addendum's own worked example: an 18:00 deadline, a 17:50 start,
     * and a 30-minute personal timer (which alone would allow until 18:20)
     * -- the earlier of the two constraints wins, so the cutoff stays
     * 18:00, and the rejection message reflects the deadline, not the
     * timer.
     */
    public function test_a_due_at_earlier_than_the_personal_timer_binds_the_effective_cutoff(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario, ['time_limit_minutes' => 30]);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 09:00:00'),
            dueAt: Carbon::parse('2026-02-01 18:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-01 17:50:00'));
        Sanctum::actingAs($candidate['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-02-01 18:05:00'));
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(422)->assertJsonPath('message', 'The submission deadline for this assessment has passed.');
    }

    public function test_a_missed_deadline_never_produces_an_automatic_result(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-02 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-03 00:00:00'));

        $assessment = $candidate['assessment']->fresh();
        $this->assertNull($assessment->result);
        $this->assertNull($assessment->completed_at);
    }

    public function test_a_missed_deadline_never_auto_rejects_the_application(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-02 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-03 00:00:00'));

        $this->assertSame('in_assessment', $candidate['application']->fresh()->status);
    }

    public function test_result_release_still_works_normally_after_a_timed_submission(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $quiz = $this->publishedSharedQuiz($scenario, ['result_release_mode' => 'manual']);
        $candidate = $this->advanceCandidateWithWindow(
            $scenario,
            availableAt: Carbon::parse('2026-02-01 10:00:00'),
            dueAt: Carbon::parse('2026-02-03 10:00:00'),
        );

        Carbon::setTestNow(Carbon::parse('2026-02-01 10:00:00'));
        Sanctum::actingAs($candidate['student']->user);
        $this->postJson("/api/student/quizzes/{$quiz->id}/start")->assertStatus(201);
        $this->postJson("/api/student/quizzes/{$quiz->id}/submit", [
            'answers' => [['question_id' => $scenario['question']->id, 'answer' => 'True']],
        ])->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson("/api/organization/assessments/{$candidate['assessment']->id}/next-action/reject")
            ->assertStatus(200);
        $this->putJson("/api/organization/assessments/{$candidate['assessment']->id}/release-result")
            ->assertStatus(200);

        $this->assertNotNull($candidate['assessment']->fresh()->result_released_at);
    }

    public function test_legacy_assessment_with_no_availability_window_is_always_available(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $org->profile->opportunities()->create([
            'title' => 'Legacy Role',
            'description' => 'Predates the addendum.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
            'recruitment_process' => 'quiz',
        ]);
        $student = $this->studentWithProfileAndCv();
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
        $application->status = 'shortlisted';
        $application->save();

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'quiz',
            'quiz' => ['title' => 'Legacy Quiz', 'passing_score' => 70],
        ])->assertStatus(201);
        $quizId = $response->json('data.quiz.id');
        $this->postJson("/api/organization/quizzes/{$quizId}/questions", [
            'prompt' => 'True or false?',
            'type' => 'true_false',
            'correct_answer' => 'True',
        ])->assertStatus(201);
        $this->putJson("/api/organization/quizzes/{$quizId}/publish")->assertStatus(200);

        $assessment = Assessment::find($response->json('data.id'));
        $this->assertNull($assessment->available_at);
        $this->assertNull($assessment->due_at);

        Sanctum::actingAs($student->user);
        $this->postJson("/api/student/quizzes/{$quizId}/start")->assertStatus(201);
    }

    public function test_available_at_and_due_at_serialize_deterministically_in_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10 15:00:00'));
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateRaw($scenario);
        $assessment = $candidate['assessment']->fresh();

        Sanctum::actingAs($candidate['student']->user);
        $response = $this->getJson("/api/student/assessments/{$candidate['assessment']->id}/quiz")
            ->assertStatus(200);

        $this->assertTrue(
            Carbon::parse($response->json('data.available_at'))->eq($assessment->available_at),
        );
        $this->assertTrue(
            Carbon::parse($response->json('data.due_at'))->eq($assessment->due_at),
        );
    }

    public function test_another_organization_cannot_view_this_candidates_timing(): void
    {
        $scenario = $this->quizOpportunityScenario();
        $this->publishedSharedQuiz($scenario);
        $candidate = $this->advanceCandidateRaw($scenario);
        $otherOrg = $this->approvedOrganization();

        Sanctum::actingAs($otherOrg->user);
        $this->getJson("/api/organization/assessments/{$candidate['assessment']->id}/quiz")
            ->assertStatus(404);
    }

    // ---- Scenario builders ------------------------------------------------

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
            'availability_delay_days' => 2,
            'availability_time' => '10:00',
            'submission_window_hours' => 48,
        ], $overrides);
    }

    private function publishedSharedQuiz(array &$scenario, array $payloadOverrides = []): Quiz
    {
        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/opportunities/{$scenario['opportunity']->id}/quiz",
            $this->validQuizPayload($payloadOverrides),
        )->assertStatus(201);
        $quiz = Quiz::find($response->json('data.id'));

        $this->postJson("/api/organization/quizzes/{$quiz->id}/questions", [
            'prompt' => 'Is this a true/false question?',
            'type' => 'true_false',
            'correct_answer' => 'True',
        ])->assertStatus(201);
        $quiz->refresh();
        $scenario['question'] = $quiz->questions->first();
        $scenario['quiz'] = $quiz;

        $this->putJson("/api/organization/opportunities/{$scenario['opportunity']->id}/quiz/publish")
            ->assertStatus(200);

        return $quiz->fresh();
    }

    private function shortlistedApplication(Opportunity $opportunity): array
    {
        $student = $this->studentWithProfileAndCv();

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
     * Shortlists a fresh candidate and advances them to the shared quiz,
     * leaving whatever `available_at`/`due_at` the real policy calculation
     * produces from the current (possibly `Carbon::setTestNow()`-frozen)
     * moment -- for tests about the calculation itself.
     */
    private function advanceCandidateRaw(array $scenario): array
    {
        $candidate = $this->shortlistedApplication($scenario['opportunity']);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/applications/{$candidate['application']->id}/quiz-assessment",
        )->assertStatus(201);

        return [
            'student' => $candidate['student'],
            'application' => $candidate['application'],
            'assessment' => Assessment::find($response->json('data.id')),
        ];
    }

    /**
     * Shortlists a fresh candidate, advances them, then overwrites the
     * resulting Assessment's frozen window with an exact, test-controlled
     * `available_at`/`due_at` -- for tests about Start/submit enforcement,
     * which need precise, arbitrary boundaries rather than whatever the
     * real policy calculation happens to produce from "now".
     */
    private function advanceCandidateWithWindow(array $scenario, Carbon $availableAt, ?Carbon $dueAt): array
    {
        $candidate = $this->advanceCandidateRaw($scenario);
        $candidate['assessment']->forceFill([
            'available_at' => $availableAt,
            'due_at' => $dueAt,
        ])->save();
        $candidate['assessment'] = $candidate['assessment']->fresh();

        return $candidate;
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
