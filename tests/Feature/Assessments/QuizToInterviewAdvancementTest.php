<?php

namespace Tests\Feature\Assessments;

use App\Mail\InterviewScheduledMail;
use App\Models\Application;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 10A.3 — the end-to-end "Advance to Interview" story: a completed
 * Quiz followed by a real, newly-created Interview Assessment for the same
 * Application, using nothing but the existing generic/legacy Assessment-
 * creation endpoints (`AssessmentService::createInterviewAssessment()`,
 * unchanged in every way except its now-widened active-assessment guard —
 * see that class's own doc comment). No dedicated "advance" endpoint exists
 * or is needed.
 *
 * Covers backend test-matrix items 4–10 and 14 from the Phase 10A.3 spec:
 * completed Quiz -> Interview creation succeeds; the completed Quiz and its
 * QuizAttempt both survive, untouched; Offer eligibility correctly follows
 * the *latest* Assessment through the whole chain (direct Offer after a
 * completed Quiz with no follow-up; blocked once a follow-up Interview is
 * scheduled; allowed again once that Interview completes); Reject preserves
 * the full history; and exactly one Interview-scheduled notification/email
 * fires for the advancement, the same as any other Interview creation.
 */
class QuizToInterviewAdvancementTest extends TestCase
{
    use RefreshDatabase;

    public function test_advancing_a_completed_quiz_to_interview_succeeds_and_preserves_the_quiz(): void
    {
        $scenario = $this->completedQuizScenario();
        $application = $scenario['application'];

        Sanctum::actingAs($scenario['org']->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('assessments', 2);

        // The completed Quiz Assessment is untouched -- not deleted, not
        // overwritten, still `completed` with its original result.
        $quizAssessment = $scenario['quizAssessment']->fresh();
        $this->assertSame('completed', $quizAssessment->status);
        $this->assertSame('passed', $quizAssessment->result);
        $this->assertNotNull($quizAssessment->completed_at);

        // The legacy endpoint's `data` is the Interview itself (its own ID
        // sequence, separate from Assessment IDs) -- `data.assessment_id`
        // is the new Assessment row.
        $interviewAssessmentId = $response->json('data.assessment_id');
        $this->assertNotSame($quizAssessment->id, $interviewAssessmentId);

        $application->refresh();
        $this->assertSame('in_assessment', $application->status);

        // `Application::assessment` (latest) now resolves to the new
        // Interview, not the older completed Quiz.
        $this->assertSame($interviewAssessmentId, $application->assessment->id);
        $this->assertSame('interview', $application->assessment->type);
    }

    public function test_the_quiz_attempt_survives_advancement_to_interview(): void
    {
        $scenario = $this->completedQuizScenario();
        $application = $scenario['application'];

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ])->assertStatus(201);

        $this->assertDatabaseHas('quiz_attempts', [
            'id' => $scenario['attempt']->id,
            'quiz_id' => $scenario['quiz']->id,
            'application_id' => $application->id,
        ]);
        $this->assertDatabaseCount('quiz_attempts', 1);
        $this->assertDatabaseHas('quizzes', ['id' => $scenario['quiz']->id]);
    }

    public function test_a_completed_quiz_with_no_follow_up_can_still_receive_a_direct_offer(): void
    {
        $scenario = $this->completedQuizScenario();
        $application = $scenario['application'];

        Sanctum::actingAs($scenario['org']->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'title' => 'Backend Developer',
        ]);

        $response->assertStatus(201);
        $application->refresh();
        $this->assertSame('offer_sent', $application->status);
    }

    /**
     * The critical Phase 10A.3 regression this whole eligibility rewrite
     * exists to prevent: once a completed Quiz has a follow-up Interview
     * that is still open, an old completed Quiz must never again satisfy
     * Offer eligibility on its own.
     */
    public function test_offer_is_blocked_once_a_follow_up_interview_is_scheduled_but_not_yet_completed(): void
    {
        $scenario = $this->completedQuizScenario();
        $application = $scenario['application'];

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ])->assertStatus(201);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'title' => 'Backend Developer',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath(
                'message',
                'An offer can only be sent once the assessment is completed.'
            );
        $application->refresh();
        $this->assertSame('in_assessment', $application->status);
    }

    public function test_offer_is_allowed_again_once_the_follow_up_interview_completes(): void
    {
        $scenario = $this->completedQuizScenario();
        $application = $scenario['application'];

        Sanctum::actingAs($scenario['org']->user);
        $created = $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ]);
        $interviewId = $created->json('data.id');

        $this->putJson("/api/organization/interviews/{$interviewId}/complete", [
            'decision' => 'passed',
        ])->assertStatus(200);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", [
            'title' => 'Backend Developer',
        ]);

        $response->assertStatus(201);
        $application->refresh();
        $this->assertSame('offer_sent', $application->status);
    }

    public function test_rejecting_after_a_completed_quiz_preserves_assessment_history(): void
    {
        $scenario = $this->completedQuizScenario();
        $application = $scenario['application'];

        Sanctum::actingAs($scenario['org']->user);

        $response = $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ]);

        $response->assertStatus(200);
        $application->refresh();
        $this->assertSame('rejected', $application->status);

        $this->assertDatabaseHas('assessments', [
            'id' => $scenario['quizAssessment']->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('quiz_attempts', ['id' => $scenario['attempt']->id]);
    }

    public function test_advancing_to_interview_sends_exactly_one_interview_scheduled_notification_and_email(): void
    {
        Mail::fake();
        $scenario = $this->completedQuizScenario();
        $application = $scenario['application'];

        $this->assertDatabaseCount('notifications', 0);

        Sanctum::actingAs($scenario['org']->user);
        $this->postJson("/api/organization/applications/{$application->id}/interview", [
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ])->assertStatus(201);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $scenario['student']->user->id,
            'type' => 'interview',
        ]);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(InterviewScheduledMail::class);
    }

    // ---- Scenario builder -----------------------------------------------

    /**
     * A shortlisted application that has already gone all the way through
     * a completed, passed Quiz (via the real Choose Assessment -> author ->
     * publish -> start -> submit workflow's *effects*, constructed directly
     * here since exercising every one of those endpoints isn't this file's
     * concern -- see StoreQuizAssessmentTest/SubmitQuizTest for that).
     *
     * @return array{
     *     org: object, student: object, application: Application,
     *     quizAssessment: \App\Models\Assessment, quiz: \App\Models\Quiz,
     *     attempt: \App\Models\QuizAttempt,
     * }
     */
    private function completedQuizScenario(): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);
        $application->status = 'shortlisted';
        $application->save();

        $quizAssessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
            'result_released_at' => now(),
        ]);
        $application->status = 'in_assessment';
        $application->save();

        $quiz = $quizAssessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'passing_score' => 50,
            'status' => 'published',
        ]);

        $attempt = $quiz->attempts()->create([
            'application_id' => $application->id,
            'score' => 100,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
        ]);

        return [
            'org' => $org,
            'student' => $student,
            'application' => $application->fresh(),
            'quizAssessment' => $quizAssessment,
            'quiz' => $quiz,
            'attempt' => $attempt,
        ];
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

    private function studentWithProfileAndCv(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        $cv = $profile->cvs()->create([
            'title' => 'My CV',
            'file_path' => 'cvs/my-cv.pdf',
        ]);

        return (object) ['user' => $user, 'profile' => $profile, 'cv' => $cv];
    }
}
