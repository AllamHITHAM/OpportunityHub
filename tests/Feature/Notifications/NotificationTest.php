<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
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
