<?php

namespace Tests\Feature\Student;

use App\Models\Location;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student Location Profile Patch — a Student's own "Current Location": a
 * single, optional canonical Location Catalog reference, deliberately
 * distinct from `available_location_ids` (the multiple locations they'd
 * be willing to work in). Settable at profile creation (Student Profile
 * Setup) and via `PUT /api/student/profile` (Edit Profile).
 */
class StudentCurrentLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_location_can_be_set_on_profile_creation(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $user = $this->studentUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/student/profile', [
            'university' => 'State University',
            'major' => 'Computer Science',
            'graduation_year' => 2027,
            'interested_in' => ['job'],
            'current_location_id' => $nablus->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame('Nablus', $response->json('data.current_location.canonical_name'));
        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'current_location_id' => $nablus->id,
        ]);
    }

    public function test_available_work_locations_can_also_be_set_on_profile_creation(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'State University',
            'major' => 'Computer Science',
            'graduation_year' => 2027,
            'interested_in' => ['job'],
            'available_location_ids' => [$nablus->id, $ramallah->id],
        ]);

        $response->assertStatus(201);
        $names = collect($response->json('data.available_locations'))
            ->pluck('canonical_name')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['Nablus', 'Ramallah'], $names);
    }

    public function test_current_location_is_optional_on_creation(): void
    {
        Sanctum::actingAs($this->studentUser());

        $response = $this->postJson('/api/student/profile', [
            'university' => 'State University',
            'major' => 'Computer Science',
            'graduation_year' => 2027,
            'interested_in' => ['job'],
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.current_location'));
    }

    public function test_current_location_persists_through_update(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->putJson('/api/student/profile', ['current_location_id' => $nablus->id])
            ->assertStatus(200)
            ->assertJsonPath('data.current_location.canonical_name', 'Nablus');

        $response = $this->putJson('/api/student/profile', ['current_location_id' => $ramallah->id]);

        $response->assertStatus(200)
            ->assertJsonPath('data.current_location.canonical_name', 'Ramallah');
    }

    public function test_current_location_can_be_cleared_with_an_explicit_null(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id, 'current_location_id' => $nablus->id]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', ['current_location_id' => null]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.current_location'));
    }

    public function test_a_nonexistent_current_location_id_is_rejected(): void
    {
        Sanctum::actingAs($this->studentUser());

        $this->postJson('/api/student/profile', [
            'university' => 'State University',
            'major' => 'Computer Science',
            'graduation_year' => 2027,
            'interested_in' => ['job'],
            'current_location_id' => 99999,
        ])->assertStatus(422);
    }

    public function test_current_location_and_available_locations_are_independent(): void
    {
        $jenin = Location::create(['canonical_name' => 'Jenin']);
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $user = $this->studentUser();
        Sanctum::actingAs($user);

        // Lives in Jenin, but willing to work in Nablus and Ramallah --
        // the two concepts must never be conflated.
        $response = $this->postJson('/api/student/profile', [
            'university' => 'State University',
            'major' => 'Computer Science',
            'graduation_year' => 2027,
            'interested_in' => ['job'],
            'current_location_id' => $jenin->id,
            'available_location_ids' => [$nablus->id, $ramallah->id],
        ]);

        $response->assertStatus(201);
        $this->assertSame('Jenin', $response->json('data.current_location.canonical_name'));
        $availableNames = collect($response->json('data.available_locations'))
            ->pluck('canonical_name')
            ->toArray();
        $this->assertNotContains('Jenin', $availableNames);
        $this->assertContains('Nablus', $availableNames);
        $this->assertContains('Ramallah', $availableNames);
    }

    public function test_one_student_cannot_read_another_students_current_location(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $studentA = $this->studentUser();
        StudentProfile::create(['user_id' => $studentA->id, 'current_location_id' => $nablus->id]);

        $studentB = $this->studentUser();
        StudentProfile::create(['user_id' => $studentB->id]);
        Sanctum::actingAs($studentB);

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.current_location'));
    }

    public function test_one_student_cannot_mutate_another_students_locations(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);

        $studentA = $this->studentUser();
        $profileA = StudentProfile::create(['user_id' => $studentA->id, 'current_location_id' => $nablus->id]);
        $profileA->availableLocations()->sync([$nablus->id]);

        $studentB = $this->studentUser();
        StudentProfile::create(['user_id' => $studentB->id]);
        Sanctum::actingAs($studentB);

        // Student B can only ever mutate their own profile -- the update
        // endpoint is never addressed by a student ID, only the
        // authenticated session's own profile.
        $this->putJson('/api/student/profile', [
            'current_location_id' => $ramallah->id,
            'available_location_ids' => [$ramallah->id],
        ])->assertStatus(200);

        $this->assertSame('Nablus', $profileA->fresh()->currentLocation?->canonical_name);
        $this->assertSame(
            ['Nablus'],
            $profileA->fresh()->availableLocations->pluck('canonical_name')->all(),
        );
    }

    public function test_existing_users_with_no_location_data_remain_readable(): void
    {
        // Simulates a Student who registered before this patch shipped --
        // no current_location_id, no available_locations rows at all.
        $user = $this->studentUser();
        StudentProfile::create([
            'user_id' => $user->id,
            'university' => 'State University',
            'major' => 'Computer Science',
            'graduation_year' => 2026,
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.current_location'));
        $this->assertSame([], $response->json('data.available_locations'));
        // Never crashes, never guesses a location from unrelated data.
        $this->assertSame('State University', $response->json('data.university'));
    }

    private function studentUser(): User
    {
        return User::factory()->create(['role' => 'student', 'status' => 'active']);
    }
}
