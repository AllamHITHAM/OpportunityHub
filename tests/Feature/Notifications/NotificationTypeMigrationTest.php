<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 7A-1: exercises the
 * `2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`
 * migration's up/rollback/re-run path directly, the same way
 * `OfferSentStatusMigrationTest` does for its own enum-widening migration.
 * Needs `DatabaseMigrations` (not `RefreshDatabase`) to roll a single
 * migration back and forward within one test.
 *
 * This migration is currently the most recently added one, so
 * `--step => 1` reaches exactly it and no further — recalculate if a later
 * phase adds another migration on top (the same fragility
 * `OfferSentStatusMigrationTest`'s own doc comment already flags for
 * itself).
 */
class NotificationTypeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_assessment_is_a_valid_type_after_migrating(): void
    {
        $notificationId = $this->seedNotification();

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'type' => 'assessment',
        ]);
    }

    public function test_offer_is_a_valid_type_after_migrating(): void
    {
        $notificationId = $this->seedNotification();

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'offer']);

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'type' => 'offer',
        ]);
    }

    public function test_every_pre_existing_type_remains_valid_after_migrating(): void
    {
        $types = ['system', 'application', 'interview', 'organization', 'opportunity'];

        foreach ($types as $type) {
            $notificationId = $this->seedNotification();
            DB::table('notifications')->where('id', $notificationId)->update(['type' => $type]);

            $this->assertDatabaseHas('notifications', [
                'id' => $notificationId,
                'type' => $type,
            ]);
        }
    }

    public function test_rollback_converts_an_assessment_row_to_system(): void
    {
        $notificationId = $this->seedNotification();
        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertSame(
            'system',
            DB::table('notifications')->where('id', $notificationId)->value('type'),
        );
    }

    public function test_rollback_converts_an_offer_row_to_system(): void
    {
        $notificationId = $this->seedNotification();
        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'offer']);

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertSame(
            'system',
            DB::table('notifications')->where('id', $notificationId)->value('type'),
        );
    }

    public function test_rollback_leaves_every_other_type_untouched(): void
    {
        $systemId = $this->seedNotification();
        $applicationId = $this->seedNotification();
        DB::table('notifications')->where('id', $applicationId)->update(['type' => 'application']);
        $interviewId = $this->seedNotification();
        DB::table('notifications')->where('id', $interviewId)->update(['type' => 'interview']);
        $organizationId = $this->seedNotification();
        DB::table('notifications')->where('id', $organizationId)->update(['type' => 'organization']);
        $opportunityId = $this->seedNotification();
        DB::table('notifications')->where('id', $opportunityId)->update(['type' => 'opportunity']);

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $this->assertSame('system', DB::table('notifications')->where('id', $systemId)->value('type'));
        $this->assertSame('application', DB::table('notifications')->where('id', $applicationId)->value('type'));
        $this->assertSame('interview', DB::table('notifications')->where('id', $interviewId)->value('type'));
        $this->assertSame('organization', DB::table('notifications')->where('id', $organizationId)->value('type'));
        $this->assertSame('opportunity', DB::table('notifications')->where('id', $opportunityId)->value('type'));
    }

    public function test_rollback_does_not_lose_unrelated_notification_data(): void
    {
        $notificationId = $this->seedNotification();
        DB::table('notifications')->where('id', $notificationId)->update([
            'type' => 'offer',
            'title' => 'A Very Specific Title',
            'message' => 'A very specific message body.',
        ]);

        Artisan::call('migrate:rollback', ['--step' => 1]);

        $row = DB::table('notifications')->where('id', $notificationId)->first();
        $this->assertSame('A Very Specific Title', $row->title);
        $this->assertSame('A very specific message body.', $row->message);
        $this->assertSame('system', $row->type);
    }

    public function test_rollback_then_remigrate_restores_assessment_and_offer_validity(): void
    {
        $notificationId = $this->seedNotification();

        Artisan::call('migrate:rollback', ['--step' => 1]);
        Artisan::call('migrate');

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);
        $this->assertDatabaseHas('notifications', ['id' => $notificationId, 'type' => 'assessment']);

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'offer']);
        $this->assertDatabaseHas('notifications', ['id' => $notificationId, 'type' => 'offer']);
    }

    public function test_rollback_removes_assessment_and_offer_from_the_enum(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 1]);

        $notificationId = $this->seedNotification();

        $this->expectException(QueryException::class);

        DB::table('notifications')->where('id', $notificationId)->update(['type' => 'assessment']);
    }

    private function seedNotification(): int
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => 'Test Notification',
            'message' => 'This is a test notification.',
            'type' => 'system',
            'priority' => 'normal',
            'action_url' => null,
        ]);

        return $notification->id;
    }
}
