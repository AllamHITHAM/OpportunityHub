<?php

namespace Tests\Feature\Quizzes;

use App\Jobs\ReleaseQuizResultJob;
use App\Mail\ApplicationRejectedMail;
use App\Mail\AssessmentDecisionInterviewMail;
use App\Mail\QuizResultMail;
use App\Models\Application;
use App\Models\Assessment;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\QuizResultReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10A.2: `Quiz.result_release_mode`/`result_release_at` and the
 * Student-visibility gate they drive (`Assessment.result_released_at`,
 * `QuizResultReleaseService`). Grading itself is unchanged (see
 * `SubmitQuizTest`) -- this file is entirely about *when the already-
 * graded result becomes visible*, independent of that.
 *
 * **Phase 10A.4A rewrite**: a release-mode/time condition is no longer
 * sufficient on its own — the Organization must also have selected and
 * readied a real next-step decision (`next_action`) for the same
 * Assessment. Every test below that used to expect an automatic release at
 * submit time with no decision involved has been updated to reflect that;
 * see `QuizResultReleaseService`'s own doc comment for the full rule. The
 * old `notifyQuizResultAvailable()`/`QuizResultMail` pair is no longer
 * invoked by the standard release path at all — released communications
 * now come from the decision-specific channel (`AssessmentDecisionInterviewMail`,
 * the existing `OfferReceivedMail` via `OfferService::sendOffer()`, or the
 * existing `ApplicationRejectedMail`) — see `NextActionDecisionTest.php`
 * for the dedicated decision-flow test matrix (staging, readiness,
 * Student-visibility gating, notification/email correctness).
 */
class ResultReleaseTest extends TestCase
{
    use RefreshDatabase;

    // ---- Immediate (default) ------------------------------------------

    public function test_immediate_mode_with_no_decision_at_submit_time_remains_pending(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'immediate');
        $this->start($scenario);

        $response = $this->submit($scenario, $this->allCorrectAnswers($scenario));

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('score', $response->json('data'));
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertNotQueued(QuizResultMail::class);
        Mail::assertNotQueued(ApplicationRejectedMail::class);
        Mail::assertNotQueued(AssessmentDecisionInterviewMail::class);
    }

    public function test_immediate_mode_releases_the_moment_a_ready_decision_is_set(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'immediate');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);

        $this->rejectDecision($scenario)->assertStatus(200);

        $this->assertNotNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertQueued(ApplicationRejectedMail::class);
    }

    public function test_immediate_release_mode_is_the_default_when_omitted(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: null);

        $this->assertSame('immediate', $scenario['quiz']->fresh()->result_release_mode);
    }

    // ---- Manual ----------------------------------------------------------

    public function test_manual_release_mode_hides_score_from_the_submit_response(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);

        $response = $this->submit($scenario, $this->allCorrectAnswers($scenario));

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('score', $response->json('data'));
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertNotQueued(QuizResultMail::class);
    }

    public function test_manual_mode_with_a_ready_decision_still_remains_pending_until_explicit_release(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        // Even though the decision is now ready, manual mode never
        // auto-releases -- only an explicit Release action does.
        $this->rejectDecision($scenario)->assertStatus(200);

        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertNotQueued(ApplicationRejectedMail::class);
    }

    public function test_manual_release_mode_hides_result_when_refetching_the_assessment(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        Sanctum::actingAs($scenario['student']->user);
        $response = $this->getJson("/api/student/assessments/{$scenario['assessment']->id}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('result', $response->json('data'));
    }

    public function test_manual_release_mode_still_stores_the_real_score_internally(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        $attempt = QuizAttempt::where('quiz_id', $scenario['quiz']->id)->firstOrFail();
        $this->assertSame(100, $attempt->score);
        $this->assertDatabaseHas('assessments', [
            'id' => $scenario['assessment']->id,
            'result' => 'passed',
        ]);
    }

    public function test_organization_can_manually_release_a_ready_reject_decision(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);
        $this->rejectDecision($scenario)->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertNotNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertQueued(ApplicationRejectedMail::class);

        $scenario['application']->refresh();
        $this->assertSame('rejected', $scenario['application']->status);

        $studentNotification = Notification::where('user_id', $scenario['student']->user->id)
            ->where('title', 'Application Update')
            ->first();
        $this->assertNotNull($studentNotification);
    }

    public function test_manual_release_before_submission_is_rejected(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result");

        $response->assertStatus(422);
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
    }

    public function test_manual_release_with_no_decision_selected_is_rejected_with_clear_validation(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result");

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'A next-step decision (Advance to Interview, Proceed to Offer, or Reject) must be '.
                    'selected and ready before releasing this result.'
            );
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
    }

    public function test_releasing_an_already_released_result_returns_409(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);
        $this->rejectDecision($scenario)->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result")
            ->assertStatus(200);

        $response = $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result");

        $response->assertStatus(409);
    }

    public function test_another_organizations_release_attempt_is_denied(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        $otherOrg = $this->approvedOrganization();
        Sanctum::actingAs($otherOrg->user);
        $response = $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result");

        $response->assertStatus(404);
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
    }

    public function test_a_student_cannot_release_their_own_result(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        Sanctum::actingAs($scenario['student']->user);
        $response = $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result");

        $response->assertStatus(403);
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
    }

    // ---- Scheduled ---------------------------------------------------

    public function test_scheduled_release_in_the_future_hides_the_result_at_submit(): void
    {
        Mail::fake();
        Bus::fake();
        $scenario = $this->twoQuestionScenario(
            releaseMode: 'scheduled',
            releaseAt: now()->addDay(),
        );
        $this->start($scenario);

        $response = $this->submit($scenario, $this->allCorrectAnswers($scenario));

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('score', $response->json('data'));
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertNotQueued(QuizResultMail::class);
    }

    public function test_scheduled_release_job_is_dispatched_with_a_delay_at_publish_time(): void
    {
        Bus::fake();
        $releaseAt = now()->addDay();
        $scenario = $this->twoQuestionScenario(releaseMode: 'scheduled', releaseAt: $releaseAt, publish: false);

        Sanctum::actingAs($scenario['org']->user);
        $this->putJson("/api/organization/quizzes/{$scenario['quiz']->id}/publish")->assertStatus(200);

        // Phase 10A.4B: dispatched by Assessment id, not Quiz id -- see
        // `ReleaseQuizResultJob`'s own doc comment.
        Bus::assertDispatched(
            ReleaseQuizResultJob::class,
            fn (ReleaseQuizResultJob $job) => $job->assessmentId === $scenario['assessment']->id,
        );
    }

    /**
     * Test matrix item A (section 33): scheduled time not reached + decision
     * ready -> no release.
     */
    public function test_scheduled_time_not_reached_with_a_ready_decision_does_not_release(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'scheduled', releaseAt: now()->addDay());
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        $this->rejectDecision($scenario)->assertStatus(200);

        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertNotQueued(ApplicationRejectedMail::class);
    }

    /**
     * Test matrix item B/C (section 33): scheduled time reached + no
     * decision -> no Student release, and the Organization gets a reminder.
     * Item D: the reminder is not duplicated on a repeated job run.
     */
    public function test_scheduled_time_reached_with_no_decision_sends_organization_reminder_not_student_release(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'scheduled', releaseAt: now()->addMinute());
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        (new ReleaseQuizResultJob($scenario['assessment']->id))->handle(app(QuizResultReleaseService::class));

        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertNotQueued(QuizResultMail::class);
        Mail::assertNotQueued(ApplicationRejectedMail::class);

        $reminder = Notification::where('user_id', $scenario['org']->user->id)
            ->where('title', 'Decision Required')
            ->first();
        $this->assertNotNull($reminder);
        $this->assertNotNull($scenario['assessment']->fresh()->decision_reminder_sent_at);

        // Item D: running the job again (a queue retry) must not create a
        // second reminder.
        (new ReleaseQuizResultJob($scenario['assessment']->id))->handle(app(QuizResultReleaseService::class));
        $this->assertSame(
            1,
            Notification::where('user_id', $scenario['org']->user->id)
                ->where('title', 'Decision Required')
                ->count(),
        );
    }

    /**
     * Test matrix item E (section 33): decision selected after the
     * scheduled time already passed -> immediate release, no waiting for
     * another day or a re-scheduled job.
     */
    public function test_decision_completed_after_scheduled_time_has_passed_releases_immediately(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'scheduled', releaseAt: now()->addSeconds(1));
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        sleep(2);
        // The job never ran (simulating a worker delay/forgotten decision)
        // -- the decision-completion action itself must detect the passed
        // time and release right away.
        $this->rejectDecision($scenario)->assertStatus(200);

        $this->assertNotNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertQueued(ApplicationRejectedMail::class);
    }

    public function test_the_scheduled_job_releases_when_the_decision_is_already_ready(): void
    {
        Mail::fake();
        // A short real delay -- `attemptRelease()` re-checks
        // `result_release_at->isPast()` itself (defense-in-depth beyond
        // trusting the queue's own `->delay()` wait), so this test must
        // actually let that little bit of wall-clock time pass, the same
        // way the pre-existing "past due" tests below already do. 4
        // seconds, not 1 -- three real HTTP requests (start/submit/reject)
        // happen before the "still pending" assertion below, and a
        // too-thin margin here made this test flaky/order-dependent on the
        // full suite's own bootstrap cost.
        $scenario = $this->twoQuestionScenario(releaseMode: 'scheduled', releaseAt: now()->addSeconds(4));
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);
        $this->rejectDecision($scenario)->assertStatus(200);
        // rejectDecision() itself already attempts release, but the time
        // hasn't arrived yet -- confirm it's still pending before the job.
        $this->assertNull($scenario['assessment']->fresh()->result_released_at);

        sleep(5);
        (new ReleaseQuizResultJob($scenario['assessment']->id))->handle(app(QuizResultReleaseService::class));

        $this->assertNotNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertQueued(ApplicationRejectedMail::class);
    }

    public function test_the_scheduled_job_is_a_no_op_when_the_student_never_submitted(): void
    {
        Mail::fake();
        $scenario = $this->twoQuestionScenario(releaseMode: 'scheduled', releaseAt: now()->addMinute());

        (new ReleaseQuizResultJob($scenario['assessment']->id))->handle(app(QuizResultReleaseService::class));

        $this->assertNull($scenario['assessment']->fresh()->result_released_at);
        Mail::assertNotQueued(QuizResultMail::class);
        // Ungraded -- no reminder either, there's nothing for the
        // Organization to act on yet.
        $this->assertNull($scenario['assessment']->fresh()->decision_reminder_sent_at);
    }

    // ---- Organization visibility is never gated -----------------------

    public function test_organization_always_sees_the_real_score_regardless_of_release_mode(): void
    {
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->getJson("/api/organization/assessments/{$scenario['assessment']->id}/quiz");

        $response->assertStatus(200);
        $attempts = $response->json('data.attempts');
        $this->assertCount(1, $attempts);
        $this->assertSame(100, $attempts[0]['score']);
    }

    public function test_the_application_scoped_assessment_endpoint_also_exposes_the_real_score(): void
    {
        // The endpoint Application Details actually calls
        // (`GET /organization/applications/{application}/assessment`),
        // distinct from the quiz-specific endpoint tested above -- both
        // must expose the Organization's own unrestricted view.
        $scenario = $this->twoQuestionScenario(releaseMode: 'manual');
        $this->start($scenario);
        $this->submit($scenario, $this->allCorrectAnswers($scenario))->assertStatus(200);

        Sanctum::actingAs($scenario['org']->user);
        $applicationId = $scenario['assessment']->application_id;
        $response = $this->getJson("/api/organization/applications/{$applicationId}/assessment");

        $response->assertStatus(200);
        // Phase 10A.3: `data` is now the full Assessment history array --
        // one element here, since this scenario only ever creates the one
        // Quiz assessment.
        $attempts = $response->json('data.0.quiz.attempts');
        $this->assertCount(1, $attempts);
        $this->assertSame(100, $attempts[0]['score']);
    }

    // ---- Helpers -----------------------------------------------------

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

    private function opportunityFor(object $org): Opportunity
    {
        return $org->profile->opportunities()->create([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);
    }

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $cv = $profile->cvs()->create(['title' => 'My CV', 'file_path' => 'cvs/my-cv.pdf']);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }

    private function applicationForStudent(Opportunity $opportunity, object $student): Application
    {
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
        $application->status = 'in_assessment';
        $application->save();

        return $application;
    }

    /**
     * @return array{student: object, org: object, application: Application, assessment: Assessment, quiz: Quiz, mcq: \App\Models\Question, tf: \App\Models\Question}
     */
    private function twoQuestionScenario(
        ?string $releaseMode = 'immediate',
        ?Carbon $releaseAt = null,
        int $passingScore = 50,
        bool $publish = true,
    ): array {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student);

        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'pending',
            'result' => null,
        ]);

        $quizAttributes = [
            'title' => 'Backend Fundamentals',
            'passing_score' => $passingScore,
            'status' => 'draft',
        ];
        if ($releaseMode !== null) {
            $quizAttributes['result_release_mode'] = $releaseMode;
        }
        if ($releaseAt !== null) {
            $quizAttributes['result_release_at'] = $releaseAt;
        }

        $quiz = $assessment->quiz()->create($quizAttributes);

        $mcq = $quiz->questions()->create([
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
            'points' => 1,
            'position' => 0,
        ]);
        $tf = $quiz->questions()->create([
            'prompt' => 'The sky is blue.',
            'type' => 'true_false',
            'correct_answer' => 'True',
            'points' => 1,
            'position' => 1,
        ]);

        if ($publish) {
            Sanctum::actingAs($org->user);
            $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);
        }

        return [
            'org' => $org,
            'student' => $student,
            'application' => $application,
            'assessment' => $assessment->fresh('application'),
            'quiz' => $quiz->fresh(),
            'mcq' => $mcq,
            'tf' => $tf,
        ];
    }

    private function allCorrectAnswers(array $scenario): array
    {
        return [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ];
    }

    private function start(array $scenario): void
    {
        Sanctum::actingAs($scenario['student']->user);
        $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/start")->assertStatus(201);
    }

    private function submit(array $scenario, array $answers): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($scenario['student']->user);

        return $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/submit", [
            'answers' => $answers,
        ]);
    }

    private function rejectDecision(array $scenario): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($scenario['org']->user);

        return $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/reject",
        );
    }
}
