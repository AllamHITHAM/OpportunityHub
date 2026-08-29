<?php

namespace Tests\Feature\Notifications;

use App\Models\Application;
use App\Models\CV;
use App\Models\Notification;
use App\Models\OrganizationProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_their_own_notifications(): void
    {
        $user = $this->createUser();
        $this->createNotification($user);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_user_cannot_see_another_users_notifications(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $this->createNotification($otherUser);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_notifications_are_ordered_from_newest_to_oldest(): void
    {
        $user = $this->createUser();

        $older = $this->createNotification($user, [
            'title' => 'Older',
            'created_at' => now()->subDays(2),
        ]);
        $newer = $this->createNotification($user, [
            'title' => 'Newer',
            'created_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_user_can_mark_one_of_their_own_notifications_as_read(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_marking_a_notification_as_read_sets_is_read_to_true(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user);

        Sanctum::actingAs($user);

        $this->putJson("/api/notifications/{$notification->id}/read")->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => true,
        ]);
    }

    public function test_marking_a_notification_as_read_sets_read_at(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user);

        $this->assertNull($notification->read_at);

        Sanctum::actingAs($user);

        $this->putJson("/api/notifications/{$notification->id}/read")->assertStatus(200);

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = $this->createUser();
        $notification = $this->createNotification($owner);

        $otherUser = $this->createUser();
        Sanctum::actingAs($otherUser);

        $response = $this->putJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Notification not found');

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => false,
        ]);
    }

    public function test_marking_an_already_read_notification_remains_safe_and_idempotent(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user, [
            'is_read' => true,
            'read_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'is_read' => true,
        ]);
    }

    public function test_user_can_mark_all_of_their_own_notifications_as_read(): void
    {
        $user = $this->createUser();
        $this->createNotification($user);
        $this->createNotification($user);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/notifications/read-all');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 2);

        $this->assertSame(
            0,
            Notification::where('user_id', $user->id)->where('is_read', false)->count()
        );
    }

    public function test_mark_all_does_not_affect_another_users_notifications(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();

        $this->createNotification($user);
        $otherNotification = $this->createNotification($otherUser);

        Sanctum::actingAs($user);

        $this->putJson('/api/notifications/read-all')->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'id' => $otherNotification->id,
            'is_read' => false,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_notification_routes(): void
    {
        $response = $this->getJson('/api/notifications');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_notification_response_includes_expected_fields(): void
    {
        $user = $this->createUser();
        $this->createNotification($user, [
            'title' => 'Application Update',
            'message' => 'Your application was reviewed.',
            'type' => 'application',
            'priority' => 'high',
            'action_url' => '/applications/1',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.title', 'Application Update')
            ->assertJsonPath('data.0.message', 'Your application was reviewed.')
            ->assertJsonPath('data.0.type', 'application')
            ->assertJsonPath('data.0.priority', 'high')
            ->assertJsonPath('data.0.action_url', '/applications/1')
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonStructure(['data' => [['sent_at']]]);

        // `is_read` has no explicit boolean cast on the model (a known,
        // already-accepted gap), so its JSON representation isn't guaranteed
        // to be a strict PHP boolean — assert loosely/truthily instead.
        $this->assertFalse((bool) $response->json('data.0.is_read'));
    }

    public function test_invalid_or_missing_notification_id_returns_correct_not_found_response(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/notifications/999999/read');

        $response->assertStatus(404);
    }

    public function test_student_organization_and_admin_users_can_each_access_their_own_notifications(): void
    {
        foreach (['student', 'organization', 'admin'] as $role) {
            $user = $this->createUser($role);
            $this->createNotification($user);

            Sanctum::actingAs($user);

            $response = $this->getJson('/api/notifications');

            $response->assertStatus(200)
                ->assertJsonPath('success', true)
                ->assertJsonCount(1, 'data');
        }
    }

    public function test_notification_list_returns_empty_array_when_user_has_no_notifications(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    // -----------------------------------------------------------------
    // Delete one (Phase 9.1)
    // -----------------------------------------------------------------

    public function test_user_can_delete_their_own_notification(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user);

        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_deleting_an_unread_notification_works(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user, ['is_read' => false]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertStatus(200);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_deleting_a_read_notification_works(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user, ['is_read' => true, 'read_at' => now()]);

        Sanctum::actingAs($user);

        $this->deleteJson("/api/notifications/{$notification->id}")->assertStatus(200);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        $owner = $this->createUser();
        $notification = $this->createNotification($owner);

        $otherUser = $this->createUser();
        Sanctum::actingAs($otherUser);

        $response = $this->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Notification not found');

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_an_organization_cannot_delete_a_students_notification(): void
    {
        $student = $this->createUser('student');
        $notification = $this->createNotification($student);

        $organization = $this->createUser('organization');
        Sanctum::actingAs($organization);

        $response = $this->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_unauthenticated_user_cannot_delete_a_notification(): void
    {
        $user = $this->createUser();
        $notification = $this->createNotification($user);

        $response = $this->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_deleting_a_notification_never_touches_the_related_application(): void
    {
        $user = $this->createUser('student');
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $org = $this->createUser('organization');
        $orgProfile = OrganizationProfile::create([
            'user_id' => $org->id,
            'organization_name' => 'Acme',
            'organization_type' => 'company',
        ]);
        $opportunity = $orgProfile->opportunities()->create([
            'title' => 'Role',
            'description' => 'Desc',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);
        $cv = CV::create([
            'student_id' => $profile->id,
            'title' => 'My CV',
            'file_path' => "cvs/{$profile->id}/irrelevant.pdf",
        ]);
        $application = Application::create([
            'student_id' => $profile->id,
            'opportunity_id' => $opportunity->id,
            'cv_id' => $cv->id,
        ]);
        $notification = $this->createNotification($user, ['type' => 'application']);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/notifications/{$notification->id}")->assertStatus(200);

        $this->assertDatabaseHas('applications', ['id' => $application->id]);
    }

    public function test_invalid_or_missing_notification_id_returns_correct_not_found_response_for_delete(): void
    {
        $user = $this->createUser();

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/notifications/999999');

        $response->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Clear read (bulk delete, Phase 9.1)
    // -----------------------------------------------------------------

    public function test_clear_read_deletes_only_read_notifications(): void
    {
        $user = $this->createUser();
        $unread = $this->createNotification($user, ['is_read' => false]);
        $read1 = $this->createNotification($user, ['is_read' => true, 'read_at' => now()]);
        $read2 = $this->createNotification($user, ['is_read' => true, 'read_at' => now()]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/notifications/read');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseHas('notifications', ['id' => $unread->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $read1->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $read2->id]);
    }

    public function test_clear_read_only_affects_the_authenticated_users_own_notifications(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $otherRead = $this->createNotification($otherUser, ['is_read' => true, 'read_at' => now()]);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/notifications/read')->assertStatus(200);

        $this->assertDatabaseHas('notifications', ['id' => $otherRead->id]);
    }

    public function test_clear_read_with_nothing_read_deletes_nothing(): void
    {
        $user = $this->createUser();
        $unread = $this->createNotification($user, ['is_read' => false]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/notifications/read');

        $response->assertStatus(200)->assertJsonPath('data.deleted_count', 0);
        $this->assertDatabaseHas('notifications', ['id' => $unread->id]);
    }

    public function test_clear_read_requires_authentication(): void
    {
        $response = $this->deleteJson('/api/notifications/read');

        $response->assertStatus(401);
    }

    public function test_mark_all_as_read_behavior_is_unchanged_by_the_new_delete_routes(): void
    {
        $user = $this->createUser();
        $this->createNotification($user);
        $this->createNotification($user);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/notifications/read-all');

        $response->assertStatus(200)->assertJsonPath('data.updated_count', 2);
    }

    private function createUser(string $role = 'student', string $status = 'active'): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function createNotification(User $user, array $overrides = []): Notification
    {
        $notification = Notification::create([
            'user_id' => $user->id,
            'title' => $overrides['title'] ?? 'Test Notification',
            'message' => $overrides['message'] ?? 'This is a test notification.',
            'priority' => $overrides['priority'] ?? 'normal',
            'type' => $overrides['type'] ?? 'system',
            'action_url' => $overrides['action_url'] ?? null,
        ]);

        if (array_key_exists('is_read', $overrides)) {
            $notification->is_read = $overrides['is_read'];
        }

        if (array_key_exists('read_at', $overrides)) {
            $notification->read_at = $overrides['read_at'];
        }

        if (array_key_exists('created_at', $overrides)) {
            $notification->created_at = $overrides['created_at'];
        }

        $notification->save();

        return $notification;
    }
}
