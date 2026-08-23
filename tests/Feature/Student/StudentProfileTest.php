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

    public function test_organization_cannot_update_student_profile(): void
    {
        Sanctum::actingAs($this->organizationUser());

        $response = $this->putJson('/api/student/profile', [
            'university' => 'Hijacked University',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_cannot_update_profile(): void
    {
        $response = $this->putJson('/api/student/profile', [
            'university' => 'New University',
        ]);

        $response->assertStatus(401);
    }

    public function test_update_rejects_invalid_graduation_year(): void
    {
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id, 'university' => 'Old University']);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'graduation_year' => 1800,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('graduation_year');
    }

    public function test_update_rejects_invalid_field_types(): void
    {
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id, 'university' => 'Old University']);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'university' => ['not', 'a', 'string'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('university');
    }

    public function test_partial_update_preserves_omitted_fields(): void
    {
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'Kept University',
            'major' => 'Kept Major',
            'graduation_year' => 2027,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'phone' => '0791234567',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.university', 'Kept University')
            ->assertJsonPath('data.major', 'Kept Major')
            ->assertJsonPath('data.graduation_year', 2027)
            ->assertJsonPath('data.phone', '0791234567');

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'university' => 'Kept University',
            'phone' => '0791234567',
        ]);
    }

    public function test_student_can_update_phone_and_bio(): void
    {
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id, 'university' => 'Old University']);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'phone' => '0791234567',
            'bio' => 'A motivated computer science student.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.phone', '0791234567')
            ->assertJsonPath('data.bio', 'A motivated computer science student.');
    }

    public function test_profile_remains_complete_and_accessible_after_a_valid_edit(): void
    {
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'Old University',
            'major' => 'Old Major',
            'graduation_year' => 2026,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/student/profile', [
            'university' => 'New University',
        ])->assertStatus(200);

        $this->assertNotNull($user->fresh()->studentProfile);

        // The `profile.exists` middleware gates every other Student feature
        // area on the profile row still existing -- proves editing never
        // puts the profile into a state that locks the student out of CVs/
        // Skills/Education Verification.
        $this->getJson('/api/student/cvs')->assertStatus(200);
    }

    public function test_update_does_not_modify_another_students_profile(): void
    {
        $studentA = $this->studentUser();
        $studentB = $this->studentUser();
        StudentProfile::create(['user_id' => $studentA->id, 'university' => 'Student A University']);
        StudentProfile::create(['user_id' => $studentB->id, 'university' => 'Student B University']);

        Sanctum::actingAs($studentA);

        $this->putJson('/api/student/profile', [
            'university' => 'Changed University',
        ])->assertStatus(200);

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $studentA->id,
            'university' => 'Changed University',
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $studentB->id,
            'university' => 'Student B University',
        ]);
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
