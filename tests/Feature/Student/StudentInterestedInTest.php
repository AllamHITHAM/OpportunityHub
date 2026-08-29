<?php

namespace Tests\Feature\Student;

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Candidate Opportunity Preferences patch: `StudentProfile.interested_in`
 * -- a JSON array of the exact canonical Opportunity Type values
 * (`App\Support\OpportunityType::ALL`), required (min:1) whenever a
 * Student completes or updates their profile, but never backfilled onto
 * an existing profile that predates this column.
 */
class StudentInterestedInTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_select_one_interested_in_type(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'Test University',
            'interested_in' => ['job'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.interested_in', ['job']);

        $this->assertDatabaseHas('student_profiles', [
            'interested_in' => json_encode(['job']),
        ]);
    }

    public function test_student_can_select_multiple_interested_in_types(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'Test University',
            'interested_in' => ['job', 'internship'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.interested_in', ['job', 'internship']);
    }

    public function test_a_student_may_genuinely_want_volunteer_and_scholarship_together(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'Test University',
            'interested_in' => ['volunteer', 'scholarship'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.interested_in', ['volunteer', 'scholarship']);
    }

    public function test_an_invalid_opportunity_type_preference_is_rejected(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'Test University',
            'interested_in' => ['not-a-real-type'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('interested_in.0');
    }

    public function test_creating_a_profile_without_interested_in_is_rejected(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'Test University',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('interested_in');
    }

    public function test_creating_a_profile_with_an_empty_interested_in_array_is_rejected(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'Test University',
            'interested_in' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('interested_in');
    }

    public function test_an_existing_profile_with_no_interested_in_preference_remains_readable(): void
    {
        $user = $this->studentUser();
        // Simulates a Student profile created before this patch -- no
        // `interested_in` column value at all, never backfilled.
        StudentProfile::create(['user_id' => $user->id, 'university' => 'Legacy University']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.university', 'Legacy University')
            ->assertJsonPath('data.interested_in', null);
    }

    public function test_an_existing_profile_can_update_unrelated_fields_without_setting_interested_in(): void
    {
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id, 'university' => 'Legacy University']);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'university' => 'Updated University',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.university', 'Updated University');
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'university' => 'Updated University',
            'interested_in' => null,
        ]);
    }

    public function test_a_student_can_later_add_interested_in_via_edit_profile(): void
    {
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id, 'university' => 'Legacy University']);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'interested_in' => ['internship'],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.interested_in', ['internship']);
    }

    public function test_updating_with_an_empty_interested_in_array_is_rejected_not_a_silent_clear(): void
    {
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'Test University',
            'interested_in' => ['job'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'interested_in' => [],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('interested_in');
    }

    public function test_updating_with_an_invalid_type_is_rejected(): void
    {
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'Test University',
            'interested_in' => ['job'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'interested_in' => ['job', 'bogus'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('interested_in.1');
    }

    private function studentUser(): User
    {
        return User::factory()->create([
            'role' => 'student',
            'status' => 'active',
        ]);
    }
}
