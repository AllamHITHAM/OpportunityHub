<?php

namespace Tests\Feature\Admin;

use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminSkillManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_skills(): void
    {
        $admin = $this->adminUser();
        Skill::create(['name' => 'Laravel']);
        Skill::create(['name' => 'PHP']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/skills');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_create_a_skill(): void
    {
        $admin = $this->adminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/skills', [
            'name' => 'Docker',
            'category' => 'DevOps',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Docker');

        $this->assertDatabaseHas('skills', ['name' => 'Docker']);
    }

    public function test_duplicate_skill_name_is_rejected(): void
    {
        $admin = $this->adminUser();
        Skill::create(['name' => 'Docker']);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/skills', [
            'name' => 'Docker',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('skills', 1);
    }

    public function test_admin_can_update_a_skill(): void
    {
        $admin = $this->adminUser();
        $skill = Skill::create(['name' => 'Old Name']);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/skills/{$skill->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('skills', [
            'id' => $skill->id,
            'name' => 'New Name',
        ]);
    }

    public function test_admin_can_delete_an_unused_skill(): void
    {
        $admin = $this->adminUser();
        $skill = Skill::create(['name' => 'Unused Skill']);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/skills/{$skill->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }

    public function test_admin_cannot_delete_a_skill_currently_used_by_a_student_or_opportunity(): void
    {
        $admin = $this->adminUser();
        $skill = Skill::create(['name' => 'In Use Skill']);

        $studentUser = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
        $studentProfile = StudentProfile::create(['user_id' => $studentUser->id]);
        $studentProfile->studentSkills()->create([
            'skill_id' => $skill->id,
            'level' => 'intermediate',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/skills/{$skill->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot delete a skill that is currently in use');

        $this->assertDatabaseHas('skills', ['id' => $skill->id]);
    }

    public function test_invalid_skill_data_is_rejected(): void
    {
        $admin = $this->adminUser();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/skills', [
            'name' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_student_cannot_manage_admin_skills(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        Sanctum::actingAs($student);

        $response = $this->postJson('/api/admin/skills', [
            'name' => 'Should Not Be Created',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');

        $this->assertDatabaseCount('skills', 0);
    }

    public function test_organization_cannot_manage_admin_skills(): void
    {
        $organizationUser = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        Sanctum::actingAs($organizationUser);

        $response = $this->postJson('/api/admin/skills', [
            'name' => 'Should Not Be Created',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');

        $this->assertDatabaseCount('skills', 0);
    }

    private function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }
}
