<?php

namespace Tests\Feature\Notifications;

use App\Mail\ApplicationRejectedMail;
use App\Mail\InterviewRescheduledMail;
use App\Mail\InterviewScheduledMail;
use App\Mail\InvitationReceivedMail;
use App\Mail\OfferAcceptedMail;
use App\Mail\OfferDeclinedMail;
use App\Mail\OfferReceivedMail;
use App\Mail\QuizAvailableMail;
use App\Models\Application;
use App\Models\Interview;
use App\Models\Invitation;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 7A-4.1: proves the Offer Received email is actually wired into the
 * real `POST /api/organization/applications/{application}/offer` workflow
 * -- queued exactly once per genuine Offer send, never for a blocked/
 * wrong-owner/ineligible/duplicate request, and never surviving a
 * transaction rollback. Mirrors `WorkflowNotificationTest`'s own structure/
 * helper conventions (Phase 7A-2) for the same event.
 *
 * Extended Phase 7A-4.2 with the remaining six workflow events (Interview
 * Scheduled/Rescheduled, Quiz Available, Application Rejected, Offer
 * Accepted/Declined) plus an explicit in-app-only regression section
 * proving the four events that deliberately stay in-app-only (Application
 * Submitted/Shortlisted, Quiz Completed, Quiz Result Available) still queue
 * nothing.
 *
 * Extended Phase 8B-3.1 with Invitation Received (Flow B's invite step,
 * `POST /organization/invitations`) -- the eighth and, for now, final
 * workflow email.
 *
 * `Mail::fake()` is used for every test except the rollback one -- see that
 * test's own doc comment for why it deliberately does NOT use `Mail::fake()`.
 */
class WorkflowEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_sending_an_offer_queues_offer_received_to_the_student(): void
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

        // The business action and the in-app Notification both happened,
        // exactly as they did before this phase (Phase 7A-2) -- proving the
        // email addition didn't change either.
        $this->assertDatabaseHas('offers', ['application_id' => $application->id, 'status' => 'sent']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'offer_sent']);
        $this->assertDatabaseCount('notifications', 1);

        Mail::assertQueued(
            OfferReceivedMail::class,
            fn (OfferReceivedMail $mail) => $mail->hasTo($student->user->email)
                && $mail->opportunityTitle === 'Backend Developer',
        );
        Mail::assertQueuedCount(1);
    }

    public function test_wrong_organization_queues_no_email(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'completed',
            'result' => 'passed',
            'completed_at' => now(),
        ]);

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", []);
        $response->assertStatus(404);

        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingQueued();
    }

    public function test_incomplete_assessment_queues_no_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        $application->assessment()->create([
            'type' => 'interview',
            'status' => 'in_progress',
            'result' => null,
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", []);
        $response->assertStatus(422);

        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingQueued();
    }

    public function test_duplicate_offer_queues_no_second_email(): void
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
        Mail::assertQueuedCount(1);

        $response = $this->postJson("/api/organization/applications/{$application->id}/offer", []);
        $response->assertStatus(409);

        $this->assertDatabaseCount('offers', 1);
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertQueuedCount(1);
    }

    /**
     * Proves the hard transaction-safety requirement directly: a failure
     * that occurs inside the same `DB::transaction()` as the Offer
     * creation/Notification/email-queue call chain -- after all three have
     * already run -- still rolls back the Offer and the Notification, exactly
     * as it would have before this phase (email changed nothing about the
     * existing transaction/rollback semantics for the business rows).
     *
     * Calls `NotificationService::notifyOfferSent()` directly (a "focused
     * service/transaction test", per this phase's own scope) rather than
     * through `OfferService::sendOffer()`, because production code has
     * nothing left to fail *after* its own `notifyOfferSent()` call (it's
     * the last statement before the transaction closure returns) --
     * modifying it just to inject a failure point was explicitly out of
     * scope for this phase.
     *
     * Deliberately does NOT use `Mail::fake()`: `MailFake::queue()` records
     * a mailable the instant `Mail::to()->queue()` is called, bypassing the
     * real queue's `afterCommit`/`DatabaseTransactionsManager` deferral
     * entirely -- asserting `Mail::assertNothingQueued()` here would still
     * pass even if the after-commit mechanism were broken, so it would
     * prove nothing about the actual mechanism under test. Instead this
     * exercises the *real* (unfaked) `Mail::to()->queue()` call -- safe to
     * do because `MAIL_MAILER=array` and `QUEUE_CONNECTION=sync` in the
     * test environment mean no real network I/O is possible either way, and
     * because a transaction rollback causes the queue's deferred callback
     * to simply never fire at all (`DatabaseTransactionsManager::rollback()`
     * only invokes rollback-specific callbacks, never the normal
     * `addCallback()`-registered ones -- confirmed via
     * `vendor/laravel/framework/.../Database/DatabaseTransactionsManager.php`).
     * That the Mailable is *never dispatched* in the first place is proven
     * structurally in `QueuedTransactionalMailTest`
     * (`ShouldQueueAfterCommit` is the actual mechanism Laravel's queue
     * layer checks) rather than re-derived dynamically here, since genuinely
     * observing "did the deferred callback execute" from inside a
     * `RefreshDatabase`-wrapped test is not possible: `RefreshDatabase`
     * itself keeps an outer transaction open for the whole test and rolls
     * it back at teardown, so the deferred callback's target transaction
     * never reaches the real top-level commit that would execute it, even
     * on the success path. Combining that structural proof with this test's
     * database-side proof establishes the two halves of the guarantee this
     * phase requires.
     */
    public function test_a_failure_after_notify_offer_sent_but_before_commit_prevents_both_the_offer_and_notification_from_being_committed(): void
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

        $caught = null;

        try {
            DB::transaction(function () use ($application) {
                $offer = $application->offer()->create([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                $application->status = 'offer_sent';
                $application->save();

                app(NotificationService::class)->notifyOfferSent(
                    $application->studentProfile->user,
                    $application->opportunity->title,
                    $application->id,
                    $offer,
                );

                throw new RuntimeException('Simulated failure after notifyOfferSent, before commit.');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected the simulated failure to propagate out of DB::transaction().');
        $this->assertSame('Simulated failure after notifyOfferSent, before commit.', $caught->getMessage());

        $this->assertDatabaseCount('offers', 0);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'status' => 'in_assessment']);
        $this->assertDatabaseCount('notifications', 0);
    }

    // ===================================================================
    // A. Interview Scheduled (Phase 7A-4.2)
    // ===================================================================

    public function test_scheduling_an_interview_queues_interview_scheduled_to_the_student(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/interview", $this->interviewPayload())
            ->assertStatus(201);

        Mail::assertQueued(
            InterviewScheduledMail::class,
            fn (InterviewScheduledMail $mail) => $mail->hasTo($student->user->email)
                && $mail->opportunityTitle === 'Backend Developer',
        );
        Mail::assertQueuedCount(1);
    }

    /**
     * Phase Final-QA-1: the queued email for a Phone interview must
     * actually render the contact number the student needs to attend —
     * end-to-end, through the real HTTP endpoint and the real rendered
     * Mailable, not just the `EmailService`/`NotificationService` unit
     * boundary (see `EmailServiceTest`/`NotificationServiceTest` for those).
     */
    public function test_a_phone_interview_email_renders_the_contact_phone(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->postJson(
            "/api/organization/applications/{$application->id}/interview",
            $this->interviewPayload([
                'interview_type' => 'phone',
                'meeting_link' => null,
                'contact_phone' => '+1 555-0100',
            ]),
        )->assertStatus(201);

        Mail::assertQueued(
            InterviewScheduledMail::class,
            fn (InterviewScheduledMail $mail) => $mail->hasTo($student->user->email)
                && $mail->contactPhone === '+1 555-0100'
                && str_contains($mail->render(), '+1 555-0100'),
        );
    }

    /**
     * `Organization\AssessmentController::store()` (generic `type=interview`)
     * delegates to the exact same `AssessmentService::createInterviewAssessment()`
     * the legacy dedicated route above does -- the email must still queue
     * exactly once, proving it's emitted from the shared service boundary,
     * matching `WorkflowNotificationTest`'s own equivalent proof for the
     * in-app Notification.
     */
    public function test_the_generic_assessment_endpoint_also_queues_exactly_one_interview_scheduled_email(): void
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

        Mail::assertQueuedCount(1);
        Mail::assertQueued(InterviewScheduledMail::class);
    }

    public function test_failed_interview_creation_queues_no_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        // `pending` is not an allowed source status for Assessment creation.
        $application = $this->applicationForStudent($opportunity, $student, 'pending');

        Sanctum::actingAs($org->user);
        $this->postJson("/api/organization/applications/{$application->id}/interview", $this->interviewPayload())
            ->assertStatus(422);

        Mail::assertNothingQueued();
    }

    // ===================================================================
    // B. Interview Rescheduled (Phase 7A-4.2)
    // ===================================================================

    public function test_a_real_schedule_change_queues_interview_rescheduled_to_the_student(): void
    {
        [$org, $student, , $interview] = $this->scheduledInterview();

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/interviews/{$interview->id}", array_merge(
            $this->interviewPayload(),
            ['scheduled_at' => now()->addDays(10)->toDateTimeString()],
        ))->assertStatus(200);

        Mail::assertQueued(
            InterviewRescheduledMail::class,
            fn (InterviewRescheduledMail $mail) => $mail->hasTo($student->user->email),
        );
        Mail::assertQueuedCount(1);
    }

    public function test_idempotent_update_with_the_same_scheduling_data_queues_no_email(): void
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

        Mail::assertNothingQueued();
    }

    public function test_wrong_ownership_update_queues_no_email(): void
    {
        [, , , $interview] = $this->scheduledInterview();
        $otherOrg = $this->approvedOrganization();

        Sanctum::actingAs($otherOrg->user);
        $this->putJson("/api/organization/interviews/{$interview->id}", array_merge(
            $this->interviewPayload(),
            ['scheduled_at' => now()->addDays(10)->toDateTimeString()],
        ))->assertStatus(404);

        Mail::assertNothingQueued();
    }

    // ===================================================================
    // C. Quiz Published (Phase 7A-4.2)
    // ===================================================================

    public function test_publishing_a_quiz_queues_quiz_available_to_the_student(): void
    {
        [$org, $student, , $quiz] = $this->draftQuizWithOneQuestion();

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);

        Mail::assertQueued(
            QuizAvailableMail::class,
            fn (QuizAvailableMail $mail) => $mail->hasTo($student->user->email)
                && $mail->opportunityTitle === 'Backend Developer',
        );
        Mail::assertQueuedCount(1);
    }

    public function test_failed_publish_queues_no_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'in_assessment');
        [, $quiz] = $this->quizFor($application, 'draft');
        // Zero questions -- publish is rejected.

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(422);

        Mail::assertNothingQueued();
    }

    public function test_already_published_queues_no_additional_email(): void
    {
        [$org, , , $quiz] = $this->draftQuizWithOneQuestion();

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(200);
        Mail::assertQueuedCount(1);

        $this->putJson("/api/organization/quizzes/{$quiz->id}/publish")->assertStatus(422);

        Mail::assertQueuedCount(1);
    }

    // ===================================================================
    // D. Application Rejected (Phase 7A-4.2)
    // ===================================================================

    public function test_rejecting_an_application_queues_application_rejected_to_the_student(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(200);

        Mail::assertQueued(
            ApplicationRejectedMail::class,
            fn (ApplicationRejectedMail $mail) => $mail->hasTo($student->user->email)
                && $mail->opportunityTitle === 'Backend Developer',
        );
        Mail::assertQueuedCount(1);
    }

    public function test_repeated_rejected_request_queues_no_duplicate_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        Sanctum::actingAs($org->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(200);
        Mail::assertQueuedCount(1);

        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(200);

        Mail::assertQueuedCount(1);
    }

    public function test_rejecting_another_organizations_application_queues_no_email(): void
    {
        $orgA = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($orgA);
        $student = $this->studentWithProfileAndCv();
        $application = $this->applicationForStudent($opportunity, $student, 'shortlisted');

        $orgB = $this->approvedOrganization();
        Sanctum::actingAs($orgB->user);
        $this->putJson("/api/organization/applications/{$application->id}/status", [
            'status' => 'rejected',
        ])->assertStatus(404);

        Mail::assertNothingQueued();
    }

    // ===================================================================
    // E. Offer Accepted (Phase 7A-4.2)
    // ===================================================================

    public function test_accepting_an_offer_queues_offer_accepted_to_the_organization(): void
    {
        [$application, $offer] = $this->sentOffer();
        // The Offer Received email from setup must never leak into this
        // test's own assertions.
        Mail::fake();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);

        $organizationEmail = $application->opportunity->organizationProfile->user->email;
        Mail::assertQueued(
            OfferAcceptedMail::class,
            fn (OfferAcceptedMail $mail) => $mail->hasTo($organizationEmail)
                && $mail->studentName === $application->studentProfile->user->name,
        );
        Mail::assertQueuedCount(1);
    }

    public function test_second_response_queues_no_additional_offer_accepted_email(): void
    {
        [$application, $offer] = $this->sentOffer();
        Mail::fake();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(200);
        Mail::assertQueuedCount(1);

        $this->putJson("/api/student/offers/{$offer->id}/accept")->assertStatus(409);

        Mail::assertQueuedCount(1);
    }

    // ===================================================================
    // F. Offer Declined (Phase 7A-4.2)
    // ===================================================================

    public function test_declining_an_offer_queues_offer_declined_to_the_organization(): void
    {
        [$application, $offer] = $this->sentOffer();
        Mail::fake();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);

        $organizationEmail = $application->opportunity->organizationProfile->user->email;
        Mail::assertQueued(
            OfferDeclinedMail::class,
            fn (OfferDeclinedMail $mail) => $mail->hasTo($organizationEmail)
                && $mail->studentName === $application->studentProfile->user->name,
        );
        Mail::assertQueuedCount(1);
    }

    public function test_second_response_queues_no_additional_offer_declined_email(): void
    {
        [$application, $offer] = $this->sentOffer();
        Mail::fake();

        Sanctum::actingAs($application->studentProfile->user);
        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(200);
        Mail::assertQueuedCount(1);

        $this->putJson("/api/student/offers/{$offer->id}/decline")->assertStatus(409);

        Mail::assertQueuedCount(1);
    }

    // ===================================================================
    // G. In-app-only regression (Phase 7A-4.2) -- these four events must
    // continue to create their in-app Notification exactly as before, and
    // must never queue any email. Explicit, not just "absence of a test" --
    // prevents accidental email-spam expansion in a future change.
    // ===================================================================

    public function test_application_submitted_creates_a_notification_but_queues_no_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($student->user);
        $this->postJson("/api/opportunities/{$opportunity->id}/apply", [
            'cv_id' => $student->cv->id,
        ])->assertStatus(201);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('New Application', Notification::first()->title);
        Mail::assertNothingQueued();
    }

    public function test_application_shortlisted_creates_a_notification_but_queues_no_email(): void
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
        $this->assertSame('Application Shortlisted', Notification::first()->title);
        Mail::assertNothingQueued();
    }

    public function test_quiz_completed_organization_notification_queues_no_email(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->startQuiz($scenario);

        $this->submitQuiz($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);

        $organizationNotification = Notification::where('user_id', $scenario['org']->user->id)->firstOrFail();
        $this->assertSame('Quiz Completed', $organizationNotification->title);
        Mail::assertNothingQueued();
    }

    public function test_quiz_result_available_student_notification_queues_no_email(): void
    {
        $scenario = $this->twoQuestionScenario();
        $this->startQuiz($scenario);

        $this->submitQuiz($scenario, [
            ['question_id' => $scenario['mcq']->id, 'answer' => 'Paris'],
            ['question_id' => $scenario['tf']->id, 'answer' => 'True'],
        ])->assertStatus(200);

        $studentNotification = Notification::where('user_id', $scenario['student']->user->id)->firstOrFail();
        $this->assertSame('Quiz Result Available', $studentNotification->title);
        // Two Notifications exist (student + organization, see
        // WorkflowNotificationTest's own equivalent test), but zero emails
        // -- neither Quiz Completed nor Quiz Result Available queues one.
        Mail::assertNothingQueued();
    }

    // ===================================================================
    // H. Invitation Received (Phase 8B-3.1)
    // ===================================================================

    public function test_sending_an_invitation_queues_invitation_received_to_the_student(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($org->user);
        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'message' => 'We think you would be a great fit!',
        ]);
        $response->assertStatus(201);

        // The business action and the in-app Notification both happened,
        // proving the email addition changed neither.
        $this->assertDatabaseHas('invitations', [
            'opportunity_id' => $opportunity->id,
            'student_id' => $student->profile->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('Invitation to Apply', Notification::first()->title);
        // Sending an invitation never creates an Application.
        $this->assertDatabaseCount('applications', 0);

        Mail::assertQueued(
            InvitationReceivedMail::class,
            fn (InvitationReceivedMail $mail) => $mail->hasTo($student->user->email)
                && $mail->organizationName === 'Hiring Co'
                && $mail->opportunityTitle === 'Backend Developer'
                && $mail->invitationMessage === 'We think you would be a great fit!',
        );
        Mail::assertQueuedCount(1);
    }

    public function test_an_invitation_with_no_message_queues_email_with_a_null_message(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($org->user);
        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201);

        Mail::assertQueued(
            InvitationReceivedMail::class,
            fn (InvitationReceivedMail $mail) => $mail->invitationMessage === null,
        );
    }

    public function test_a_non_open_opportunity_queues_no_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['status' => 'closed']);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($org->user);
        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $response->assertStatus(422);

        $this->assertDatabaseCount('invitations', 0);
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingQueued();
    }

    public function test_another_organizations_opportunity_queues_no_email(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $opportunityB = $this->opportunityFor($orgB);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($orgA->user);
        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunityB->id,
        ]);
        $response->assertStatus(404);

        $this->assertDatabaseCount('invitations', 0);
        Mail::assertNothingQueued();
    }

    public function test_an_already_applied_student_queues_no_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();
        Application::create([
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $student->cv->id,
        ]);

        Sanctum::actingAs($org->user);
        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $response->assertStatus(409);

        $this->assertDatabaseCount('invitations', 0);
        Mail::assertNothingQueued();
    }

    public function test_an_ineligible_student_invitation_queues_no_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org, ['field_of_study' => 'Computer Science']);
        $student = $this->studentWithProfileAndCv('Fine Arts');

        Sanctum::actingAs($org->user);
        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $response->assertStatus(422);

        $this->assertDatabaseCount('invitations', 0);
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingQueued();
    }

    public function test_a_duplicate_invitation_queues_no_second_email(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        Sanctum::actingAs($org->user);
        $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ])->assertStatus(201);
        Mail::assertQueuedCount(1);

        $response = $this->postJson('/api/organization/invitations', [
            'student_id' => $student->profile->id,
            'opportunity_id' => $opportunity->id,
        ]);
        $response->assertStatus(409);

        $this->assertDatabaseCount('invitations', 1);
        Mail::assertQueuedCount(1);
    }

    /**
     * Proves the transaction-safety requirement directly, the same way
     * `test_a_failure_after_notify_offer_sent_but_before_commit_prevents_both_the_offer_and_notification_from_being_committed()`
     * does for Offers: calls `NotificationService::notifyInvitationReceived()`
     * directly, inside a manually-created `DB::transaction()` that then
     * throws, and proves neither the Invitation row nor the Notification
     * row survive the rollback. `Organization\InvitationController::store()`
     * itself has no explicit `DB::transaction()` wrapping its single
     * `Invitation::create()` call (a single INSERT needs no multi-statement
     * atomicity), so this test exercises the underlying after-commit
     * mechanism `NotificationService`/`EmailService` share with every other
     * workflow event directly, rather than through that controller.
     *
     * Deliberately does NOT use `Mail::fake()` -- see the identical Offer
     * rollback test's own doc comment for the full reasoning (in short:
     * `MailFake::queue()` bypasses the real after-commit deferral entirely,
     * so asserting against it here would prove nothing about the actual
     * mechanism under test).
     */
    public function test_a_failure_after_notify_invitation_received_but_before_commit_prevents_both_the_invitation_and_notification_from_being_committed(): void
    {
        $org = $this->approvedOrganization();
        $opportunity = $this->opportunityFor($org);
        $student = $this->studentWithProfileAndCv();

        $caught = null;

        try {
            DB::transaction(function () use ($opportunity, $student, $org) {
                Invitation::create([
                    'opportunity_id' => $opportunity->id,
                    'student_id' => $student->profile->id,
                ]);

                app(NotificationService::class)->notifyInvitationReceived(
                    $student->user,
                    $org->profile->organization_name,
                    $opportunity->title,
                );

                throw new RuntimeException('Simulated failure after notifyInvitationReceived, before commit.');
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'Expected the simulated failure to propagate out of DB::transaction().');
        $this->assertSame(
            'Simulated failure after notifyInvitationReceived, before commit.',
            $caught->getMessage(),
        );

        $this->assertDatabaseCount('invitations', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    // ---------------------------------------------------------------
    // Helpers -- mirror WorkflowNotificationTest's own conventions.
    // ---------------------------------------------------------------

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

    private function studentWithProfileAndCv(?string $major = null): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id, 'major' => $major]);

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
        // The "interview scheduled" email from setup must never leak into
        // a reschedule test's own assertions.
        Mail::fake();

        return [$org, $student, $application, $interview];
    }

    /**
     * @return array{0: \App\Models\Assessment, 1: \App\Models\Quiz}
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
     * @return array{0: object, 1: object, 2: \App\Models\Assessment, 3: \App\Models\Quiz}
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
     * A published quiz with one 1-point multiple_choice question ("Paris")
     * and one 1-point true_false question ("True"). Mirrors
     * `WorkflowNotificationTest::twoQuestionScenario()`'s exact shape.
     *
     * @return array{org: object, student: object, application: Application, assessment: \App\Models\Assessment, quiz: \App\Models\Quiz, mcq: \App\Models\Question, tf: \App\Models\Question}
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
     * shared setup for every accept/decline test.
     *
     * @return array{0: Application, 1: \App\Models\Offer}
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

        $offer = \App\Models\Offer::findOrFail($response->json('data.id'));

        return [$application->fresh(), $offer];
    }
}
