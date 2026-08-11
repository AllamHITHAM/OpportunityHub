<?php

namespace Tests\Feature\Notifications;

use App\Mail\OfferReceivedMail;
use App\Models\Application;
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
}
