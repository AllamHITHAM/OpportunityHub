<?php

namespace Tests\Unit\Services;

use App\Models\Offer;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 7A-1: direct unit coverage for `NotificationService` — the one
 * creation API for in-app Notification rows. Exercised directly (not
 * through an HTTP endpoint) because nothing calls this service yet; no
 * controller wiring exists until Phase 7A-2. Uses `RefreshDatabase` (the
 * same trait every Feature test in this project already uses) since every
 * assertion here needs a real persisted `User`/`Notification` row — the
 * `tests/Unit` location reflects that this exercises the service class in
 * isolation, not an HTTP contract, not that it avoids the database.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolved via the container (Phase 7A-4.1: NotificationService now
        // depends on EmailService) rather than `new NotificationService()`
        // -- see docs/ARCHITECTURE.md on why every constructor-injection
        // change like this prefers container resolution over touching
        // every direct-construction call site.
        $this->notifications = app(NotificationService::class);
    }

    // -----------------------------------------------------------------
    // Generic create()
    // -----------------------------------------------------------------

    public function test_create_persists_a_notification_for_the_given_user(): void
    {
        $user = $this->studentUser();

        $notification = $this->notifications->create(
            $user,
            'Test Title',
            'Test message body.',
        );

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_create_sets_the_title(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Specific Title',
            'A message.',
        );

        $this->assertSame('A Specific Title', $notification->title);
    }

    public function test_create_sets_the_message(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A very specific message body.',
        );

        $this->assertSame('A very specific message body.', $notification->message);
    }

    public function test_create_sets_the_given_type(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
            type: 'offer',
        );

        $this->assertSame('offer', $notification->type);
    }

    public function test_create_sets_the_given_priority(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
            priority: 'high',
        );

        $this->assertSame('high', $notification->priority);
    }

    public function test_create_sets_the_given_action_url(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
            actionUrl: '/student/applications/42',
        );

        $this->assertSame('/student/applications/42', $notification->action_url);
    }

    public function test_create_defaults_type_to_system(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
        );

        $this->assertSame('system', $notification->type);
    }

    public function test_create_defaults_priority_to_normal(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
        );

        $this->assertSame('normal', $notification->priority);
    }

    public function test_create_defaults_action_url_to_null(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
        );

        $this->assertNull($notification->action_url);
    }

    public function test_create_is_unread_by_default(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
        );

        $this->assertFalse((bool) $notification->is_read);
    }

    public function test_create_leaves_read_at_null(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
        );

        $this->assertNull($notification->read_at);
    }

    public function test_create_populates_sent_at_via_database_default(): void
    {
        $notification = $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
        );

        $this->assertNotNull($notification->fresh()->sent_at);
    }

    public function test_create_rejects_an_invalid_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
            type: 'not-a-real-type',
        );
    }

    public function test_create_rejects_an_invalid_priority(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->notifications->create(
            $this->studentUser(),
            'A Title',
            'A message.',
            priority: 'urgent',
        );
    }

    public function test_create_does_not_persist_anything_when_type_is_invalid(): void
    {
        try {
            $this->notifications->create(
                $this->studentUser(),
                'A Title',
                'A message.',
                type: 'not-a-real-type',
            );
        } catch (InvalidArgumentException) {
            // Expected -- assert no row was written below.
        }

        $this->assertDatabaseCount('notifications', 0);
    }

    // -----------------------------------------------------------------
    // Convenience methods
    // -----------------------------------------------------------------

    public function test_notify_application_submitted(): void
    {
        $organizationUser = $this->organizationUser();

        $notification = $this->notifications->notifyApplicationSubmitted(
            $organizationUser,
            'Backend Developer',
            applicationId: 7,
        );

        $this->assertSame($organizationUser->id, $notification->user_id);
        $this->assertSame('New Application', $notification->title);
        $this->assertSame('A new application was submitted for Backend Developer.', $notification->message);
        $this->assertSame('application', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame('/organization/applications/7', $notification->action_url);
    }

    public function test_notify_application_shortlisted(): void
    {
        $studentUser = $this->studentUser();

        $notification = $this->notifications->notifyApplicationShortlisted(
            $studentUser,
            'Backend Developer',
            applicationId: 12,
        );

        $this->assertSame($studentUser->id, $notification->user_id);
        $this->assertSame('Application Shortlisted', $notification->title);
        $this->assertSame('You have been shortlisted for Backend Developer.', $notification->message);
        $this->assertSame('application', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame('/student/applications/12', $notification->action_url);
    }

    public function test_notify_application_rejected(): void
    {
        $studentUser = $this->studentUser();

        $notification = $this->notifications->notifyApplicationRejected(
            $studentUser,
            'Backend Developer',
            applicationId: 12,
        );

        $this->assertSame($studentUser->id, $notification->user_id);
        $this->assertSame('Application Update', $notification->title);
        $this->assertSame('Your application for Backend Developer was not selected.', $notification->message);
        $this->assertSame('application', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame('/student/applications/12', $notification->action_url);
    }

    public function test_notify_interview_scheduled(): void
    {
        $studentUser = $this->studentUser();

        $notification = $this->notifications->notifyInterviewScheduled(
            $studentUser,
            'Backend Developer',
            applicationId: 5,
        );

        $this->assertSame($studentUser->id, $notification->user_id);
        $this->assertSame('Interview Scheduled', $notification->title);
        $this->assertSame(
            'An interview has been scheduled for your application to Backend Developer.',
            $notification->message,
        );
        $this->assertSame('interview', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame('/student/applications/5', $notification->action_url);
    }

    public function test_notify_interview_rescheduled(): void
    {
        $studentUser = $this->studentUser();

        $notification = $this->notifications->notifyInterviewRescheduled(
            $studentUser,
            'Backend Developer',
            applicationId: 5,
        );

        $this->assertSame($studentUser->id, $notification->user_id);
        $this->assertSame('Interview Rescheduled', $notification->title);
        $this->assertSame('Your interview for Backend Developer has been rescheduled.', $notification->message);
        $this->assertSame('interview', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame('/student/applications/5', $notification->action_url);
    }

    public function test_notify_quiz_published(): void
    {
        $studentUser = $this->studentUser();

        $notification = $this->notifications->notifyQuizPublished(
            $studentUser,
            'Backend Developer',
            assessmentId: 9,
        );

        $this->assertSame($studentUser->id, $notification->user_id);
        $this->assertSame('Quiz Available', $notification->title);
        $this->assertSame(
            'A quiz is now available for your application to Backend Developer.',
            $notification->message,
        );
        $this->assertSame('assessment', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame('/student/assessments/9/quiz', $notification->action_url);
    }

    public function test_notify_quiz_result_available(): void
    {
        $studentUser = $this->studentUser();

        $notification = $this->notifications->notifyQuizResultAvailable(
            $studentUser,
            'Backend Developer',
            applicationId: 5,
        );

        $this->assertSame($studentUser->id, $notification->user_id);
        $this->assertSame('Quiz Result Available', $notification->title);
        $this->assertSame('Your quiz result for Backend Developer is now available.', $notification->message);
        $this->assertSame('assessment', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame('/student/applications/5', $notification->action_url);
    }

    public function test_notify_quiz_completed(): void
    {
        $organizationUser = $this->organizationUser();

        $notification = $this->notifications->notifyQuizCompleted(
            $organizationUser,
            'Jane Student',
            'Backend Developer',
            applicationId: 5,
        );

        $this->assertSame($organizationUser->id, $notification->user_id);
        $this->assertSame('Quiz Completed', $notification->title);
        $this->assertSame('Jane Student completed the quiz for Backend Developer.', $notification->message);
        $this->assertSame('assessment', $notification->type);
        $this->assertSame('normal', $notification->priority);
        $this->assertSame('/organization/applications/5', $notification->action_url);
    }

    public function test_notify_offer_sent(): void
    {
        $studentUser = $this->studentUser();
        // Never persisted -- notifyOfferSent() only reads display fields
        // off it (Phase 7A-4.1, forwarded to EmailService), it does not
        // save/mutate the Offer itself.
        $offer = new Offer();

        $notification = $this->notifications->notifyOfferSent(
            $studentUser,
            'Backend Developer',
            applicationId: 5,
            offer: $offer,
        );

        $this->assertSame($studentUser->id, $notification->user_id);
        $this->assertSame('Offer Received', $notification->title);
        $this->assertSame('You received an offer for Backend Developer.', $notification->message);
        $this->assertSame('offer', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame('/student/applications/5', $notification->action_url);
    }

    public function test_notify_offer_accepted(): void
    {
        $organizationUser = $this->organizationUser();

        $notification = $this->notifications->notifyOfferAccepted(
            $organizationUser,
            'Jane Student',
            'Backend Developer',
            applicationId: 5,
        );

        $this->assertSame($organizationUser->id, $notification->user_id);
        $this->assertSame('Offer Accepted', $notification->title);
        $this->assertSame('Jane Student accepted the offer for Backend Developer.', $notification->message);
        $this->assertSame('offer', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame('/organization/applications/5', $notification->action_url);
    }

    public function test_notify_offer_declined(): void
    {
        $organizationUser = $this->organizationUser();

        $notification = $this->notifications->notifyOfferDeclined(
            $organizationUser,
            'Jane Student',
            'Backend Developer',
            applicationId: 5,
        );

        $this->assertSame($organizationUser->id, $notification->user_id);
        $this->assertSame('Offer Declined', $notification->title);
        $this->assertSame('Jane Student declined the offer for Backend Developer.', $notification->message);
        $this->assertSame('offer', $notification->type);
        $this->assertSame('high', $notification->priority);
        $this->assertSame('/organization/applications/5', $notification->action_url);
    }

    private function studentUser(): User
    {
        return User::factory()->create(['role' => 'student', 'status' => 'active']);
    }

    private function organizationUser(): User
    {
        return User::factory()->create(['role' => 'organization', 'status' => 'active']);
    }
}
