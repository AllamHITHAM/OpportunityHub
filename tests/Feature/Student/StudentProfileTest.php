<?php

namespace Tests\Feature\Student;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_a_profile(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'phone' => '0791234567',
            'university' => 'Test University',
            'major' => 'Computer Science',
            'graduation_year' => 2026,
            'bio' => 'A motivated student.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.university', 'Test University');

        $this->assertDatabaseHas('student_profiles', [
            'university' => 'Test University',
        ]);
    }

    public function test_student_can_view_their_own_profile(): void
    {
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'View University',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.university', 'View University');
    }

    public function test_student_can_update_their_profile(): void
    {
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'Old University',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'university' => 'New University',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.university', 'New University');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'university' => 'New University',
        ]);
    }

    public function test_student_cannot_create_a_second_profile(): void
    {
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'First University',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/student/profile', [
            'university' => 'Second University',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Profile already exists');

        $this->assertDatabaseCount('student_profiles', 1);
    }

    public function test_organization_cannot_access_student_profile_routes(): void
    {
        Sanctum::actingAs($this->organizationUser());

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    private function studentUser(): User
    {
        return User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
    }

    private function organizationUser(): User
    {
        return User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);
    }
}
