<?php

namespace Tests\Feature\Admin;

use App\Models\OrganizationProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_actions_do_not_modify_unrelated_users_or_organizations(): void
    {
        $admin = $this->adminUser();

        $targetUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $unrelatedUser = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $targetOrg = $this->organizationWithApproval('pending');
        $unrelatedOrg = $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/users/{$targetUser->id}/status", ['status' => 'suspended'])
            ->assertStatus(200);

        $this->putJson("/api/admin/organizations/{$targetOrg->profile->id}/approval", ['approval_status' => 'approved'])
            ->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $unrelatedUser->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('organization_profiles', [
            'id' => $unrelatedOrg->profile->id,
            'approval_status' => 'pending',
        ]);
    }

    public function test_updating_organization_approval_does_not_change_user_role(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/organizations/{$org->profile->id}/approval", [
            'approval_status' => 'approved',
        ])->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $org->user->id,
            'role' => 'organization',
        ]);
    }

    public function test_updating_user_status_does_not_change_organization_approval_status(): void
    {
        $admin = $this->adminUser();
        $org = $this->organizationWithApproval('pending');

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/users/{$org->user->id}/status", [
            'status' => 'suspended',
        ])->assertStatus(200);

        $this->assertDatabaseHas('organization_profiles', [
            'id' => $org->profile->id,
            'approval_status' => 'pending',
        ]);
    }

    public function test_skill_crud_does_not_affect_unrelated_skills(): void
    {
        $admin = $this->adminUser();
        $targetSkill = Skill::create(['name' => 'Target Skill']);
        $unrelatedSkill = Skill::create(['name' => 'Unrelated Skill']);

        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/skills/{$targetSkill->id}", [
            'name' => 'Renamed Skill',
        ])->assertStatus(200);

        $this->deleteJson("/api/admin/skills/{$targetSkill->id}")->assertStatus(200);

        $this->assertDatabaseHas('skills', [
            'id' => $unrelatedSkill->id,
            'name' => 'Unrelated Skill',
        ]);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function organizationWithApproval(string $status): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = $status;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
