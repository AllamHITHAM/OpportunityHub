<?php

namespace Tests\Feature\Authorization;

use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_protected_student_routes(): void
    {
        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_unauthenticated_user_cannot_access_protected_organization_routes(): void
    {
        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_student_can_access_student_routes(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', []);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    public function test_student_cannot_access_organization_routes(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_organization_can_access_organization_routes(): void
    {
        Sanctum::actingAs($this->organizationUserWithApproval('approved'));

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_organization_cannot_access_student_routes(): void
    {
        Sanctum::actingAs($this->organizationUserWithApproval('approved'));

        $response = $this->postJson('/api/student/profile', []);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_admin_can_access_admin_routes(): void
    {
        Sanctum::actingAs($this->adminUser());

        $response = $this->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_student_cannot_access_admin_routes(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->getJson('/api/admin/users');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_organization_cannot_access_admin_routes(): void
    {
        Sanctum::actingAs($this->organizationUserWithApproval('approved'));

        $response = $this->getJson('/api/admin/users');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_suspended_user_cannot_access_protected_routes(): void
    {
        Sanctum::actingAs($this->studentUser('suspended'));

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Account is not active');
    }

    /**
     * The schema has no distinct "inactive" status value — `users.status` is
     * one of active/pending/suspended. This test exercises "pending" to prove
     * EnsureUserIsActive blocks *any* non-active status, not just "suspended".
     */
    public function test_pending_status_user_cannot_access_protected_routes(): void
    {
        Sanctum::actingAs($this->studentUser('pending'));

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Account is not active');
    }

    public function test_pending_organization_cannot_create_opportunities(): void
    {
        Sanctum::actingAs($this->organizationUserWithApproval('pending'));

        $response = $this->postJson('/api/organization/opportunities', $this->validOpportunityPayload());

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Organization is not approved to publish opportunities');
    }

    public function test_rejected_organization_cannot_create_opportunities(): void
    {
        Sanctum::actingAs($this->organizationUserWithApproval('rejected'));

        $response = $this->postJson('/api/organization/opportunities', $this->validOpportunityPayload());

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Organization is not approved to publish opportunities');
    }

    public function test_approved_organization_can_create_opportunities(): void
    {
        Sanctum::actingAs($this->organizationUserWithApproval('approved'));

        $response = $this->postJson('/api/organization/opportunities', $this->validOpportunityPayload());

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('opportunities', [
            'title' => 'Software Engineer',
        ]);
    }

    public function test_public_opportunity_routes_remain_accessible_without_authentication(): void
    {
        $response = $this->getJson('/api/opportunities');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    private function studentUser(string $status = 'active'): User
    {
        return User::factory()->create([
            'role' => 'student',
            'status' => $status,
        ]);
    }

    private function adminUser(string $status = 'active'): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => $status,
        ]);
    }

    private function organizationUserWithApproval(string $approvalStatus, string $userStatus = 'active'): User
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => $userStatus,
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);

        $profile->approval_status = $approvalStatus;
        $profile->save();

        return $user;
    }

    private function validOpportunityPayload(): array
    {
        return [
            'title' => 'Software Engineer',
            'description' => 'A great opportunity.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
        ];
    }
}
