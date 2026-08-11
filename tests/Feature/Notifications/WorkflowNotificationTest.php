<?php

namespace Tests\Feature\Notifications;

use App\Models\Application;
use App\Models\Assessment;
use App\Models\Interview;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\Quiz;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 7A-2: proves `NotificationService` is actually wired into the real
 * recruitment workflow -- one notification per real business transition,
 * none for a failed/blocked/duplicate/idempotent request, correct
 * recipient, and correct `action_url`. Exercises every integration point
 * through its real HTTP endpoint (or, for the two entry paths that share
 * `AssessmentService::createInterviewAssessment()`, both of them), never the
 * service in isolation -- `NotificationServiceTest` (Phase 7A-1) already
 * covers the service's own copy/type/priority/action_url logic directly.
 */
class WorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    // ===================================================================
    // A. Application events
    // ===================================================================

    public function test_student_applying_notifies_the_organization_exactly_once(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($student->user);
        $response = $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ]);
        $response->assertStatus(201);
        $applicationId = $response->json('data.id');

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($org->user->id, $notification->user_id);
        $this->assertSame('New Application', $notification->title);
        $this->assertSame(
            'A new application was submitted for Backend Developer.',
            $notification->message,
        );
        $this->assertSame('application', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame("/organization/applications/{$applicationId}", $notification->action_url);
    }

    public function test_no_student_notification_is_created_for_the_students_own_application_submission(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($student->user);
        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $this->assertSame(0, Notification::where('user_id', $student->user->id)->count());
    }

    public function test_a_duplicate_apply_creates_no_additional_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($student->user);
        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);
        $this->assertDatabaseCount('notifications', 1);

        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(409);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_failed_apply_creates_no_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['status' => 'closed']);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($student->user);
        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(404);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_shortlisting_an_application_notifies_the_student_exactly_once(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'pending');

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'shortlisted',
        ])->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($student->user->id, $notification->user_id);
        $this->assertSame('Application Shortlisted', $notification->title);
        $this->assertSame('You have been shortlisted for Backend Developer.', $notification->message);
        $this->assertSame('application', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame("/student/applications/{$application->id}", $notification->action_url);
    }

    public function test_repeated_shortlisted_request_creates_no_duplicate_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'pending');

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'shortlisted',
        ])->assertStatus(200);
        $this->assertDatabaseCount('notifications', 1);

        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'shortlisted',
        ])->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_shortlisting_another_organizations_application_creates_no_notification(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'pending');

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'shortlisted',
        ])->assertStatus(404);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_rejecting_an_application_notifies_the_student_exactly_once(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($student->user->id, $notification->user_id);
        $this->assertSame('Application Update', $notification->title);
        $this->assertSame('Your application for Backend Developer was not selected.', $notification->message);
        $this->assertSame('application', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame("/student/applications/{$application->id}", $notification->action_url);
    }

    public function test_repeated_rejected_request_creates_no_duplicate_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(200);
        $this->assertDatabaseCount('notifications', 1);

        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
    }

    /**
     * Section 5: an Offer decline must never additionally create the
     * generic "Application Update" rejection notification -- only
     * `notifyOfferDeclined()` (organization-facing) fires. Structurally
     * guaranteed by the Phase 6C-4 integrity rule: once an Offer exists,
     * the generic status endpoint this test's sibling tests exercise is
     * blocked entirely (409), so the two paths can never both notify for
     * the same application.
     */
    public function test_offer_decline_does_not_create_a_generic_application_rejected_notification(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);

        $this->assertSame(0, Notification::where('title', 'Application Update')->count());
        $this->assertSame(1, Notification::where('title', 'Offer Declined')->count());
    }

    // ===================================================================
    // B. Interview events
    // ===================================================================

    public function test_successful_interview_assessment_creation_notifies_the_student_exactly_once(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/interview", $this->interviewPayload())
            ->assertStatus(201);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($student->user->id, $notification->user_id);
        $this->assertSame('Interview Scheduled', $notification->title);
        $this->assertSame(
            'An interview has been scheduled for your application to Backend Developer.',
            $notification->message,
        );
        $this->assertSame('interview', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame("/student/applications/{$application->id}", $notification->action_url);
    }

    /**
     * Section 6: `Organization\AssessmentController::store()` (generic
     * `type=interview`) delegates to the exact same
     * `AssessmentService::createInterviewAssessment()` the legacy dedicated
     * route above does -- the notification must still fire exactly once,
     * proving it's emitted from the shared service boundary, not
     * duplicated per controller.
     */
    public function test_the_generic_assessment_endpoint_also_creates_exactly_one_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/assessments", [
            'type' => 'interview',
            'interview' => $this->interviewPayload(),
        ])->assertStatus(201);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('Interview Scheduled', Notification::first()->title);
    }

    public function test_failed_interview_creation_creates_no_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        // `pending` is not an allowed source status for Assessment creation.
        $application = $this->applicationForStudent($opportunity, $student, 'pending');

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/interview", $this->interviewPayload())
            ->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_changing_scheduled_at_notifies_the_student_exactly_once(): void
    {
        [$org, $student, $application, $interview] = $this->scheduledInterview();

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/interviews/{$interview->id}", array_merge(
            $this->interviewPayload(),
            ['scheduled_at' => now()->addDays(10)->toDateTimeString()],
        ))->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($student->user->id, $notification->user_id);
        $this->assertSame('Interview Rescheduled', $notification->title);
        $this->assertSame('Your interview for Backend Developer has been rescheduled.', $notification->message);
        $this->assertSame('interview', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame("/student/applications/{$application->id}", $notification->action_url);
    }

    public function test_idempotent_update_with_the_same_scheduling_data_creates_no_notification(): void
    {
        [$org, , , $interview] = $this->scheduledInterview();

        Sanctum::actingAs($org->user);
        // The exact same interview_type/scheduled_at already on the row --
        // only interviewer_name (not rescheduling-relevant) changes.
        $this->putJson("/api/organization/interviews/{$interview->id}", array_merge(
            $this->interviewPayload(),
            [
                'scheduled_at' => $interview->scheduled_at->toDateTimeString(),
                'interviewer_name' => 'Jane Recruiter',
            ],
        ))->assertStatus(200);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_wrong_ownership_update_creates_no_notification(): void
    {
        [, , , $interview] = $this->scheduledInterview();
        $otherOrg = $this->approvedOrganization();

        Sanctum::actingAs($otherOrg->user);
        $this->putJson("/api/organization/interviews/{$interview->id}", array_merge(
            $this->interviewPayload(),
            ['scheduled_at' => now()->addDays(10)->toDateTimeString()],
        ))->assertStatus(404);

        $this->assertDatabaseCount('notifications', 0);
    }

    // ===================================================================
    // C. Quiz events
    // ===================================================================

    public function test_successful_first_publish_notifies_the_student(): void
    {
        [$org, $student, , $quiz] = $this->draftQuizWithOneQuestion();

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($student->user->id, $notification->user_id);
        $this->assertSame('Quiz Available', $notification->title);
        $this->assertSame(
            'A quiz is now available for your application to Backend Developer.',
            $notification->message,
        );
        $this->assertSame('assessment', $notification->type);
        $this->assertSame('normal', $notification->priority);
    }

    public function test_publish_action_url_points_directly_to_the_student_quiz_route(): void
    {
        [$org, , $assessment, $quiz] = $this->draftQuizWithOneQuestion();

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);

        $this->assertSame(
            "/student/assessments/{$assessment->id}/quiz",
            Notification::first()->action_url,
        );
    }

    public function test_failed_publish_creates_no_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        [, $quiz] = $this->quizFor($application, 'draft');
        // Zero questions -- publish is rejected.

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_already_published_creates_no_duplicate_notification(): void
    {
        [$org, , , $quiz] = $this->draftQuizWithOneQuestion();

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);
        $this->assertDatabaseCount('notifications', 1);

        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(422);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_successful_submit_creates_one_student_and_one_organization_notification(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->startQuiz($scenario);

        $this->submitQuiz($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);

        $this->assertDatabaseCount('notifications', 2);

        $studentNotification = Notification::where('user_id', $scenario['student']->user->id)->firstOrFail();
        $this->assertSame('Quiz Result Available', $studentNotification->title);
        $this->assertSame(
            'Your quiz result for Backend Developer is now available.',
            $studentNotification->message,
        );
        $this->assertSame('assessment', $studentNotification->type);
        $this->assertSame('normal', $studentNotification->priority);
        $this->assertSame(
            "/student/applications/{$scenario['application']->id}",
            $studentNotification->action_url,
        );

        $organizationNotification = Notification::where('user_id', $scenario['org']->user->id)->firstOrFail();
        $this->assertSame('Quiz Completed', $organizationNotification->title);
        $this->assertSame(
            "{$scenario['student']->user->name} completed the quiz for Backend Developer.",
            $organizationNotification->message,
        );
        $this->assertSame('assessment', $organizationNotification->type);
        $this->assertSame('normal', $organizationNotification->priority);
        $this->assertSame(
            "/organization/applications/{$scenario['application']->id}",
            $organizationNotification->action_url,
        );
    }

    public function test_submit_without_start_creates_no_notification(): void
    {
        $scenario = $this->twoQuestionScenario();

        $this->submitQuiz($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_expired_submit_creates_no_notification(): void
    {
        $scenario = $this->twoQuestionScenario(timeLimitMinutes: 10);
        $this->startQuiz($scenario);
        \App\Models\QuizAttempt::where('quiz_id', $scenario['quiz']->id)
            ->update(['started_at' => now()->subMinutes(30)]);

        $this->submitQuiz($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_duplicate_submit_creates_no_additional_notifications(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->startQuiz($scenario);

        $this->submitQuiz($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);
        $this->assertDatabaseCount('notifications', 2);

        $this->submitQuiz($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(409);

        $this->assertDatabaseCount('notifications', 2);
    }

    // ===================================================================
    // D. Offer events
    // ===================================================================

    public function test_sending_an_offer_notifies_the_student_exactly_once(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/offer", [])
            ->assertStatus(201);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $this->assertSame($student->user->id, $notification->user_id);
        $this->assertSame('Offer Received', $notification->title);
        $this->assertSame('You received an offer for Backend Developer.', $notification->message);
        $this->assertSame('offer', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame("/student/applications/{$application->id}", $notification->action_url);
    }

    public function test_invalid_source_status_creates_no_offer_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/offer", [])
            ->assertStatus(422);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_duplicate_send_creates_no_additional_offer_notification(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/offer", [])->assertStatus(201);
        $this->assertDatabaseCount('notifications', 1);

        $this->postJson("/api/organization/applications/{$application->id}/offer", [])->assertStatus(409);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_accepting_an_offer_notifies_the_organization_exactly_once(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $organizationUserId = $application->opportunity->organizationProfile->user->id;
        $studentName = $application->studentProfile->user->name;
        $this->assertSame($organizationUserId, $notification->user_id);
        $this->assertSame('Offer Accepted', $notification->title);
        $this->assertSame("{$studentName} accepted the offer for Backend Developer.", $notification->message);
        $this->assertSame('offer', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame("/organization/applications/{$application->id}", $notification->action_url);

        // The student who just responded is never notified about their own action.
        $this->assertSame(0, Notification::where('user_id', $application->studentProfile->user->id)->count());
    }

    public function test_second_accept_creates_no_additional_notification(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);
        $this->assertDatabaseCount('notifications', 1);

        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(409);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_decline_after_accept_creates_no_additional_notification(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);
        $this->assertDatabaseCount('notifications', 1);

        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(409);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('Offer Accepted', Notification::first()->title);
    }

    public function test_declining_an_offer_notifies_the_organization_exactly_once(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);

        $this->assertDatabaseCount('notifications', 1);
        $notification = Notification::first();
        $organizationUserId = $application->opportunity->organizationProfile->user->id;
        $studentName = $application->studentProfile->user->name;
        $this->assertSame($organizationUserId, $notification->user_id);
        $this->assertSame('Offer Declined', $notification->title);
        $this->assertSame("{$studentName} declined the offer for Backend Developer.", $notification->message);
        $this->assertSame('offer', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame("/organization/applications/{$application->id}", $notification->action_url);

        $this->assertSame(0, Notification::where('user_id', $application->studentProfile->user->id)->count());
    }

    public function test_second_decline_creates_no_additional_notification(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);
        $this->assertDatabaseCount('notifications', 1);

        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(409);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_accept_after_decline_creates_no_additional_notification(): void
    {
        [$application, $offer] = $this->sentOffer();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);
        $this->assertDatabaseCount('notifications', 1);

        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(409);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('Offer Declined', Notification::first()->title);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

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

    private function applicationForStudent(Opportunity $opportunity, object $student, string $status = 'pending'): Application
    {
        $application = Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        $application->status = $status;
        $application->save();

        return $application;
    }

    private function interviewPayload(array $overrides = []): array
    {
        return array_merge([
            'interview_type' => 'online',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'meeting_link' => 'https://meet.example.com/room',
        ], $overrides);
    }

    /**
     * @return array{0: object, 1: object, 2: Application, 3: Interview}
     */
    private function scheduledInterview(): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $response = $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->interviewPayload(),
        );
        $response->assertStatus(201);

        $interview = Interview::findOrFail($response->json('data.id'));
        // The "interview scheduled" notification from setup must never
        // leak into a reschedule test's own assertions.
        Notification::query()->delete();

        return [$org, $student, $application, $interview];
    }

    /**
     * @return array{0: object, 1: object, 2: Assessment, 3: Quiz}
     */
    private function draftQuizWithOneQuestion(): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        [$assessment, $quiz] = $this->quizFor($application, 'draft');

        $quiz->questions()->create([
            'prompt' => 'What is the capital of France?',
            'type' => 'multiple_choice',
            'options' => ['Paris', 'London', 'Berlin'],
            'correct_answer' => 'Paris',
            'points' => 1,
            'position' => 0,
        ]);

        return [$org, $student, $assessment, $quiz];
    }

    /**
     * @return array{0: Assessment, 1: Quiz}
     */
    private function quizFor(Application $application, string $status, int $passingScore = 50, ?int $timeLimitMinutes = null): array
    {
        $assessment = $application->assessment()->create([
            'type' => 'quiz',
            'status' => $status === 'published' ? 'scheduled' : 'pending',
            'result' => null,
        ]);

        $quiz = $assessment->quiz()->create([
            'title' => 'Backend Fundamentals',
            'time_limit_minutes' => $timeLimitMinutes,
            'passing_score' => $passingScore,
            'status' => $status,
        ]);

        return [$assessment, $quiz];
    }

    /**
     * A published quiz with one 1-point multiple_choice question ("Paris")
     * and one 1-point true_false question ("True"). Mirrors
     * `SubmitQuizTest::twoQuestionScenario()`'s exact shape.
     *
     * @return array{org: object, student: object, application: Application, assessment: Assessment, quiz: Quiz, mcq: \App\Models\Question, tf: \App\Models\Question}
     */
    private function twoQuestionScenario(int $passingScore = 50, ?int $timeLimitMinutes = null): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        [$assessment, $quiz] = $this->quizFor($application, 'published', $passingScore, $timeLimitMinutes);

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

        return [
            'org' => $org,
            'student' => $student,
            'application' => $application,
            'assessment' => $assessment->fresh('application'),
            'quiz' => $quiz,
            'mcq' => $mcq,
            'tf' => $tf,
        ];
    }

    private function startQuiz(array $scenario): void
    {
        Sanctum::actingAs($scenario['student']->user);
        $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/start")->assertStatus(201);
    }

    private function submitQuiz(array $scenario, array $answers): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($scenario['student']->user);

        return $this->postJson("/api/student/quizzes/{$scenario['quiz']->id}/submit", [
            'answers' => $answers,
        ]);
    }

    /**
     * A `sent` Offer on an eligible, completed-Assessment application --
     * shared setup for every accept/decline test. The "offer sent"
     * notification from setup is cleared so accept/decline tests only ever
     * see their own notification.
     *
     * @return array{0: Application, 1: Offer}
     */
    private function sentOffer(): array
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", []);
        $response->assertStatus(201);

        $offer = Offer::findOrFail($response->json('data.id'));
        Notification::query()->delete();

        return [$application->fresh(), $offer];
    }
}
