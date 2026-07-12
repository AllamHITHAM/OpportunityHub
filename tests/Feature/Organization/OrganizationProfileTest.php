<?php

namespace Tests\Feature\Organization;

use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_view_its_own_profile(): void
    {
        $org = $this->organizationWithProfile('approved', ['organization_name' => 'Acme Corp']);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.organization_name', 'Acme Corp');
    }

    public function test_organization_can_update_its_own_profile(): void
    {
        $org = $this->organizationWithProfile('approved', ['organization_name' => 'Old Name']);

        Sanctum::actingAs($org->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => 'New Name',
            'organization_type' => 'company',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.organization_name', 'New Name');

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $org->user->id,
            'organization_name' => 'New Name',
        ]);
    }

    public function test_student_cannot_access_organization_profile_routes(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    /**
     * The update endpoint has no route parameter — it always targets
     * $request->user()->organizationProfile — so there is structurally no way
     * for one organization to target another's profile through this route.
     * This test proves the resulting data isolation: organization B's update
     * only ever touches its own row, never organization A's.
     */
    public function test_organization_cannot_modify_another_organizations_profile(): void
    {
        $orgA = $this->organizationWithProfile('approved', ['organization_name' => 'Org A']);
        $orgB = $this->organizationWithProfile('approved', ['organization_name' => 'Org B']);

        Sanctum::actingAs($orgB->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => 'Hijacked Name',
            'organization_type' => 'company',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $orgA->user->id,
            'organization_name' => 'Org A',
        ]);

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $orgB->user->id,
            'organization_name' => 'Hijacked Name',
        ]);
    }

    public function test_approval_status_cannot_be_changed_by_the_organization(): void
    {
        $org = $this->organizationWithProfile('pending');

        Sanctum::actingAs($org->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => $org->profile->organization_name,
            'organization_type' => $org->profile->organization_type,
            'approval_status' => 'approved',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $org->user->id,
            'approval_status' => 'pending',
        ]);
    }

    private function organizationWithProfile(string $approvalStatus = 'approved', array $attributes = []): object
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

        $profile->approval_status = $approvalStatus;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
