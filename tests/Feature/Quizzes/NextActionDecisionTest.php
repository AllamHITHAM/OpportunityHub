<?php

namespace Tests\Feature\Quizzes;

use App\Mail\ApplicationRejectedMail;
use App\Mail\AssessmentDecisionInterviewMail;
use App\Mail\OfferReceivedMail;
use App\Mail\QuizResultMail;
use App\Models\Application;
use App\Models\Assessment;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10A.4A — the "next action" decision endpoints
 * (`POST .../assessments/{assessment}/next-action/{interview,offer,reject}`)
 * and the Student-visibility gate a staged "Advance to Interview" follow-up
 * Assessment needs before its own release. `ResultReleaseTest.php` covers
 * the release-mode timing/readiness semantics this file assumes; this file
 * is about the decision-staging endpoints themselves and what a Student can
 * and can't see before/after release.
 */
class NextActionDecisionTest extends TestCase
{
    use RefreshDatabase;

    // ---- Interview decision --------------------------------------------

    public function test_setting_the_interview_decision_requires_the_real_interview_scheduling_fields(): void
    {
        $scenario = $this->completedQuizScenario();

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/interview",
            [],
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['interview_type', 'scheduled_at']);
        $this->assertNull($scenario['assessment']->fresh()->next_action);
    }

    public function test_setting_the_interview_decision_creates_a_real_interview_assessment(): void
    {
        $scenario = $this->completedQuizScenario();

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/interview",
            $this->validInterviewPayload(),
        );

        $response->assertStatus(200)->assertJsonPath('success', true);

        $quizAssessment = $scenario['assessment']->fresh();
        $this->assertSame('interview', $quizAssessment->next_action);
        $this->assertNotNull($quizAssessment->next_action_assessment_id);
        $this->assertNotNull($quizAssessment->next_action_prepared_at);

        $interviewAssessment = Assessment::find($quizAssessment->next_action_assessment_id);
        $this->assertNotNull($interviewAssessment);
        $this->assertSame('interview', $interviewAssessment->type);
        $this->assertSame($quizAssessment->id, $interviewAssessment->origin_assessment_id);
        $this->assertNotNull($interviewAssessment->interview);
        $this->assertSame('online', $interviewAssessment->interview->interview_type);
    }

    /**
     * Test matrix item K: an unreleased staged Interview is hidden from
     * every Student-facing endpoint.
     */
    public function test_unreleased_interview_decision_is_hidden_from_every_student_facing_endpoint(): void
    {
        $scenario = $this->completedQuizScenario(releaseMode: 'manual');
        $this->stageInterviewDecision($scenario);

        Sanctum::actingAs($scenario['student']->user);

        // 1. The list endpoint must include the Quiz Assessment (Submitted,
        //    Result Pending) but never the staged Interview Assessment.
        $listResponse = $this->getJson('/api/student/assessments');
        $listResponse->assertStatus(200);
        $types = collect($listResponse->json('data'))->pluck('type');
        $this->assertContains('quiz', $types);
        $this->assertNotContains('interview', $types);

        // 2. Fetching the Interview Assessment directly by ID must 404, the
        //    same "not found" a wrong-owner request gets -- never revealing
        //    it exists at all.
        $interviewAssessmentId = $scenario['assessment']->fresh()->next_action_assessment_id;
        $this->getJson("/api/student/assessments/{$interviewAssessmentId}")
            ->assertStatus(404)
            ->assertJsonPath('message', 'Assessment not found');

        // 3. The legacy Student Interviews list must also exclude it.
        $interviewsResponse = $this->getJson('/api/student/interviews');
        $interviewsResponse->assertStatus(200);
        $this->assertCount(0, $interviewsResponse->json('data'));

        // 4. The Student dashboard's interview count must not include it.
        $dashboardResponse = $this->getJson('/api/student/dashboard');
        $dashboardResponse->assertStatus(200);
        $this->assertSame(0, $dashboardResponse->json('data.total_interviews'));
    }

    /**
     * Test matrix item L: the Organization can always see the prepared
     * (still-unreleased) Interview internally.
     */
    public function test_organization_can_see_the_prepared_interview_before_release(): void
    {
        $scenario = $this->completedQuizScenario(releaseMode: 'manual');
        $this->stageInterviewDecision($scenario);

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->getJson(
            "/api/organization/applications/{$scenario['application']->id}/assessment",
        );

        $response->assertStatus(200);
        $quizEntry = collect($response->json('data'))->firstWhere('type', 'quiz');
        $this->assertSame('interview', $quizEntry['next_action']);
        $this->assertNotNull($quizEntry['next_action_assessment']);
        $this->assertSame(
            'online',
            $quizEntry['next_action_assessment']['interview']['interview_type'],
        );
    }

    /**
     * Test matrix item M: releasing the Interview decision sends exactly
     * one combined communication -- never a separate quiz-result email and
     * never the plain "Interview Scheduled" email.
     */
    public function test_releasing_the_interview_decision_sends_exactly_one_combined_communication(): void
    {
        Mail::fake();
        $scenario = $this->completedQuizScenario(releaseMode: 'manual');
        $this->stageInterviewDecision($scenario);
        Mail::assertNothingQueued();

        Sanctum::actingAs($scenario['org']->user);
        $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result")
            ->assertStatus(200);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(AssessmentDecisionInterviewMail::class);
        Mail::assertNotQueued(QuizResultMail::class);

        $studentNotification = Notification::where('user_id', $scenario['student']->user->id)
            ->where('title', 'Assessment Update')
            ->first();
        $this->assertNotNull($studentNotification);

        // Now that it's released, the Interview Assessment is visible.
        Sanctum::actingAs($scenario['student']->user);
        $listResponse = $this->getJson('/api/student/assessments');
        $types = collect($listResponse->json('data'))->pluck('type');
        $this->assertContains('interview', $types);
    }

    public function test_a_student_cannot_set_the_next_action_decision(): void
    {
        $scenario = $this->completedQuizScenario();

        Sanctum::actingAs($scenario['student']->user);
        $response = $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/reject",
        );

        $response->assertStatus(403);
        $this->assertNull($scenario['assessment']->fresh()->next_action);
    }

    public function test_another_organization_cannot_set_the_next_action_decision(): void
    {
        $scenario = $this->completedQuizScenario();
        $otherOrg = $this->approvedOrganization();

        Sanctum::actingAs($otherOrg->user);
        $response = $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/reject",
        );

        $response->assertStatus(404);
        $this->assertNull($scenario['assessment']->fresh()->next_action);
    }

    // ---- Offer decision -------------------------------------------------

    /**
     * Test matrix item N: a prepared but unreleased Offer is hidden from
     * the Student -- because it simply doesn't exist as a real `Offer` row
     * yet.
     */
    public function test_preparing_the_offer_decision_creates_no_real_offer_yet(): void
    {
        Mail::fake();
        $scenario = $this->completedQuizScenario(releaseMode: 'manual');

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/offer",
            ['title' => 'Backend Developer', 'salary_amount' => 90000, 'salary_currency' => 'USD', 'salary_period' => 'yearly'],
        );

        $response->assertStatus(200);
        $this->assertDatabaseCount('offers', 0);
        Mail::assertNothingQueued();

        $quizAssessment = $scenario['assessment']->fresh();
        $this->assertSame('offer', $quizAssessment->next_action);
        $this->assertSame('Backend Developer', $quizAssessment->next_action_data['title']);

        Sanctum::actingAs($scenario['student']->user);
        $this->getJson("/api/student/applications/{$scenario['application']->id}/offer")
            ->assertStatus(404);
    }

    /**
     * Test matrix item O: releasing the Offer decision behaves exactly
     * once -- creates the real Offer, moves Application.status, and sends
     * the existing Offer notification/email, never a duplicate.
     */
    public function test_releasing_the_offer_decision_creates_the_real_offer_exactly_once(): void
    {
        Mail::fake();
        $scenario = $this->completedQuizScenario(releaseMode: 'manual');
        Sanctum::actingAs($scenario['org']->user);
        $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/offer",
            ['title' => 'Backend Developer'],
        )->assertStatus(200);

        $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result")
            ->assertStatus(200);

        $this->assertDatabaseCount('offers', 1);
        $scenario['application']->refresh();
        $this->assertSame('offer_sent', $scenario['application']->status);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(OfferReceivedMail::class);

        // A second release attempt is a clean 409, never a second Offer.
        $response = $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result");
        $response->assertStatus(409);
        $this->assertDatabaseCount('offers', 1);
    }

    // ---- Reject decision --------------------------------------------------

    /**
     * Test matrix item P: a prepared Reject decision never leaks early --
     * `Application.status` stays `in_assessment` until release.
     */
    public function test_preparing_the_reject_decision_does_not_change_application_status_yet(): void
    {
        Mail::fake();
        $scenario = $this->completedQuizScenario(releaseMode: 'manual');

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/reject",
        )->assertStatus(200);

        $scenario['application']->refresh();
        $this->assertSame('in_assessment', $scenario['application']->status);
        Mail::assertNothingQueued();
    }

    /**
     * Test matrix item Q: releasing the Reject decision is what actually
     * applies the real rejection transition.
     */
    public function test_releasing_the_reject_decision_changes_application_status(): void
    {
        Mail::fake();
        $scenario = $this->completedQuizScenario(releaseMode: 'manual');
        Sanctum::actingAs($scenario['org']->user);
        $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/reject",
        )->assertStatus(200);

        $this->putJson("/api/organization/assessments/{$scenario['assessment']->id}/release-result")
            ->assertStatus(200);

        $scenario['application']->refresh();
        $this->assertSame('rejected', $scenario['application']->status);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(ApplicationRejectedMail::class);

        // Assessment history is preserved -- never deleted by a rejection.
        $this->assertDatabaseHas('assessments', [
            'id' => $scenario['assessment']->id,
            'status' => 'completed',
        ]);
    }

    // ---- Historical / backward compatibility ---------------------------

    /**
     * Test matrix item Z: a historical Assessment released under the
     * pre-10A.4A rules (no `next_action` at all) remains readable and
     * released -- never retroactively hidden or required to gain a
     * decision after the fact.
     */
    public function test_a_historical_pre_10a4a_release_remains_readable(): void
    {
        $scenario = $this->completedQuizScenario();
        // Simulate a pre-10A.4A released row: no next_action, but
        // result_released_at already set.
        $scenario['assessment']->update(['result_released_at' => now()->subDays(10)]);

        Sanctum::actingAs($scenario['student']->user);
        $response = $this->getJson("/api/student/assessments/{$scenario['assessment']->id}");

        $response->assertStatus(200)->assertJsonPath('data.result', 'passed');
    }

    // ---- Decision correction before release ------------------------------

    /**
     * Regression guard: staging Interview then switching to Reject must not
     * leave the abandoned, still-`scheduled` (i.e. "active") Interview
     * Assessment behind -- otherwise it would permanently trip the
     * active-assessment invariant for every future Assessment on this
     * application, even though it was never released or seen by the
     * Student.
     */
    public function test_switching_away_from_a_staged_interview_discards_the_abandoned_interview_assessment(): void
    {
        $scenario = $this->completedQuizScenario();
        $this->stageInterviewDecision($scenario);
        $abandonedInterviewAssessmentId = $scenario['assessment']->fresh()->next_action_assessment_id;
        $this->assertNotNull(Assessment::find($abandonedInterviewAssessmentId));

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/reject",
        )->assertStatus(200)->assertJsonPath('data.next_action', 'reject');

        $this->assertNull(Assessment::find($abandonedInterviewAssessmentId));
        $this->assertNull($scenario['assessment']->fresh()->next_action_assessment_id);

        // The active-assessment invariant must not still be tripped by the
        // now-deleted Interview -- a genuinely new Assessment can be
        // created for this application again.
        $this->postJson(
            "/api/organization/applications/{$scenario['application']->id}/interview",
            $this->validInterviewPayload(),
        )->assertStatus(201);
    }

    /**
     * Re-staging Interview (e.g. to correct the date/time before release)
     * must succeed, not 409 against the Interview Assessment it's replacing.
     */
    public function test_restaging_the_interview_decision_replaces_the_previous_staged_interview(): void
    {
        $scenario = $this->completedQuizScenario();
        $this->stageInterviewDecision($scenario);
        $firstInterviewAssessmentId = $scenario['assessment']->fresh()->next_action_assessment_id;

        Sanctum::actingAs($scenario['org']->user);
        $response = $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/interview",
            [
                'interview_type' => 'onsite',
                'scheduled_at' => now()->addDays(7)->toDateTimeString(),
                'location' => '123 Main St',
            ],
        );

        $response->assertStatus(200);
        $this->assertNull(Assessment::find($firstInterviewAssessmentId));

        $updatedAssessment = $scenario['assessment']->fresh();
        $newInterviewAssessmentId = $updatedAssessment->next_action_assessment_id;
        $this->assertNotSame($firstInterviewAssessmentId, $newInterviewAssessmentId);
        $this->assertSame('onsite', Assessment::find($newInterviewAssessmentId)->interview->interview_type);
    }

    // ---- Scenario builder -----------------------------------------------

    /**
     * @return array{org: object, student: object, application: Application, assessment: Assessment, quiz: Quiz}
     */
    private function completedQuizScenario(string $releaseMode = 'manual'): array
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
        ]);
        $student = $this->studentWithProfileAndCv();

        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
        $application->status = 'in_assessment';
        $application->save();

        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'passing_score' => 50,
            'status' => 'published',
            'result_release_mode' => $releaseMode,
        ]);

        $quiz->attempts()->create([
            'application_id' => $application->id,
            'score' => 100,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
        ]);

        return [
            'org' => $org,
            'student' => $student,
            'application' => $application->fresh(),
            'assessment' => $assessment,
            'quiz' => $quiz,
        ];
    }

    private function validInterviewPayload(): array
    {
        return [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(5)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ];
    }

    private function stageInterviewDecision(array $scenario): void
    {
        Sanctum::actingAs($scenario['org']->user);
        $this->postJson(
            "/api/organization/assessments/{$scenario['assessment']->id}/next-action/interview",
            $this->validInterviewPayload(),
        )->assertStatus(200);
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
