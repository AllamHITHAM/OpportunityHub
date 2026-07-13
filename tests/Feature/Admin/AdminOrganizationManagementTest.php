<?php

namespace Tests\Feature\Admin;

use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_organizations(): void
    {
        $admin = $this->adminUser();
        $this->organizationWithApproval('pending');
        $this->organizationWithApproval('approved');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/organizations');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_view_one_organization(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('approved', ['organization_name' => 'Acme Corp']);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/organizations/{$org->profile->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.organization_name', 'Acme Corp');
    }

    public function test_admin_can_approve_a_pending_organization(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/organizations/{$org->profile->id}/approval", [
            'approval_status' => 'approved',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.approval_status', 'approved');
    }

    public function test_admin_can_reject_a_pending_organization(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/organizations/{$org->profile->id}/approval", [
            'approval_status' => 'rejected',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.approval_status', 'rejected');
    }

    public function test_approval_status_is_persisted(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/organizations/{$org->profile->id}/approval", [
            'approval_status' => 'approved',
        ])->assertStatus(200);

        $this->assertDatabaseHas('organization_profiles', [
            'id' => $org->profile->id,
            'approval_status' => 'approved',
        ]);
    }

    public function test_invalid_approval_status_is_rejected(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/organizations/{$org->profile->id}/approval", [
            'approval_status' => 'not-a-real-status',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['approval_status']);
    }

    public function test_admin_cannot_update_a_nonexistent_organization(): void
    {
        $admin = $this->adminUser();

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/admin/organizations/999999/approval', [
            'approval_status' => 'approved',
        ]);

        $response->assertStatus(404);
    }

    public function test_student_cannot_approve_organizations(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($student);

        $response = $this->putJson("/api/admin/organizations/{$org->profile->id}/approval", [
            'approval_status' => 'approved',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    public function test_organization_cannot_approve_itself_through_admin_routes(): void
    {
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/admin/organizations/{$org->profile->id}/approval", [
            'approval_status' => 'approved',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');

        $this->assertDatabaseHas('organization_profiles', [
            'id' => $org->profile->id,
            'approval_status' => 'pending',
        ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function organizationWithApproval(string $status, array $attributes = []): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create(array_merge([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ], $attributes));
        $profile->approval_status = $status;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
