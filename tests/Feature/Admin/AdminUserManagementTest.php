<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = $this->adminUser();
        User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users');

        // The admin itself + the 1 created student = 2.
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_update_user_status(): void
    {
        $admin = $this->adminUser();
        $targetUser = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$targetUser->id}/status", [
            'status' => 'suspended',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'suspended');
    }

    public function test_admin_can_suspend_an_active_user(): void
    {
        $admin = $this->adminUser();
        $targetUser = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/users/{$targetUser->id}/status", [
            'status' => 'suspended',
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'status' => 'suspended',
        ]);
    }

    public function test_admin_can_reactivate_a_suspended_user(): void
    {
        $admin = $this->adminUser();
        $targetUser = User::factory()->create(['role' => 'student', 'status' => 'suspended']);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$targetUser->id}/status", [
            'status' => 'active',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('users', [
            'id' => $targetUser->id,
            'status' => 'active',
        ]);
    }

    public function test_invalid_user_status_is_rejected(): void
    {
        $admin = $this->adminUser();
        $targetUser = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$targetUser->id}/status", [
            'status' => 'pending',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_admin_cannot_update_a_nonexistent_user(): void
    {
        $admin = $this->adminUser();

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/admin/users/999999/status', [
            'status' => 'suspended',
        ]);

        $response->assertStatus(404);
    }

    public function test_student_cannot_update_user_status(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $targetUser = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($student);

        $response = $this->putJson("/api/admin/users/{$targetUser->id}/status", [
            'status' => 'suspended',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_organization_cannot_update_user_status(): void
    {
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        $targetUser = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($organizationUser);

        $response = $this->putJson("/api/admin/users/{$targetUser->id}/status", [
            'status' => 'suspended',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }
}
