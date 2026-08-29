<?php

namespace Tests\Feature\Student;

use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 8A-6.3: `GET /student/skills/catalog` — the real Skill catalog a
 * student picks from when manually adding a skill. Every `skills` row is
 * Admin-owned/approved by construction (there's no separate approval flag),
 * so this is simply the full catalog.
 */
class StudentSkillCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_can_fetch_the_real_skill_catalog(): void
    {
        $student = $this->studentWithProfile();
        Skill::create(['name' => 'Laravel', 'category' => 'Computer Science']);
        Skill::create(['name' => 'AutoCAD', 'category' => 'Civil Engineering']);
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/skills/catalog');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_the_catalog_is_ordered_deterministically_by_name(): void
    {
        $student = $this->studentWithProfile();
        Skill::create(['name' => 'Zebra Skill']);
        Skill::create(['name' => 'Alpha Skill']);
        Skill::create(['name' => 'Middle Skill']);
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/skills/catalog');

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Alpha Skill', 'Middle Skill', 'Zebra Skill'], $names);
    }

    public function test_the_catalog_exposes_only_id_name_and_category(): void
    {
        $student = $this->studentWithProfile();
        Skill::create(['name' => 'Laravel', 'category' => 'Computer Science']);
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/skills/catalog');

        $response->assertJsonStructure(['data' => [['id', 'name', 'category']]]);
        $entry = $response->json('data.0');
        $this->assertArrayNotHasKey('created_at', $entry);
        $this->assertArrayNotHasKey('updated_at', $entry);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/student/skills/catalog');

        $response->assertStatus(401);
    }

    public function test_an_organization_cannot_fetch_the_student_skill_catalog(): void
    {
        $org = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        Sanctum::actingAs($org);

        $response = $this->getJson('/api/student/skills/catalog');

        $response->assertStatus(403);
    }

    private function studentWithProfile(): object
    {
        $user = User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);

        $profile = StudentProfile::create(['user_id' => $user->id]);

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
