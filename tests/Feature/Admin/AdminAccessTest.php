<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_admin_dashboard(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_student_cannot_access_admin_routes(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_organization_cannot_access_admin_routes(): void
    {
        Sanctum::actingAs($this->organizationUser());

        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_unauthenticated_user_cannot_access_admin_routes(): void
    {
        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
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
