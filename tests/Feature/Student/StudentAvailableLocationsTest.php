<?php

namespace Tests\Feature\Student;

use App\Models\Location;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase O8.2 — a Student's own "Available Work Locations": a multi-select
 * of real, canonical Location Catalog IDs, never comma-separated free
 * text, updated via the existing `PUT /api/student/profile` endpoint
 * (`available_location_ids`).
 */
class StudentAvailableLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_can_select_multiple_available_locations(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $ramallah = Location::create(['canonical_name' => 'Ramallah']);
        $jenin = Location::create(['canonical_name' => 'Jenin']);

        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'available_location_ids' => [$nablus->id, $ramallah->id, $jenin->id],
        ]);

        $response->assertStatus(200);
        $names = collect($response->json('data.available_locations'))->pluck('canonical_name')->sort()->values()->all();
        $this->assertSame(['Jenin', 'Nablus', 'Ramallah'], $names);
    }

    public function test_an_explicit_empty_list_clears_available_locations(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $user = $this->studentUser();
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $profile->availableLocations()->sync([$nablus->id]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', ['available_location_ids' => []]);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.available_locations'));
    }

    public function test_omitting_the_key_leaves_existing_locations_untouched(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $user = $this->studentUser();
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $profile->availableLocations()->sync([$nablus->id]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', ['bio' => 'Updated bio only']);

        $response->assertStatus(200);
        $this->assertCount(1, $profile->fresh()->availableLocations);
    }

    public function test_a_nonexistent_location_id_is_rejected(): void
    {
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->putJson('/api/student/profile', ['available_location_ids' => [99999]])
            ->assertStatus(422);
    }

    public function test_free_text_is_never_accepted_for_locations(): void
    {
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->putJson('/api/student/profile', ['available_location_ids' => ['Nablus']])
            ->assertStatus(422);
    }

    public function test_the_profile_show_endpoint_includes_available_locations(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $user = $this->studentUser();
        $profile = StudentProfile::create(['user_id' => $user->id]);
        $profile->availableLocations()->sync([$nablus->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(200);
        $this->assertSame('Nablus', $response->json('data.available_locations.0.canonical_name'));
    }

    public function test_a_duplicate_location_id_in_the_payload_is_never_stored_twice(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $user = $this->studentUser();
        $profile = StudentProfile::create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'available_location_ids' => [$nablus->id, $nablus->id],
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $profile->fresh()->availableLocations);
        $this->assertDatabaseCount('student_available_locations', 1);
    }

    public function test_existing_profile_fields_are_unaffected(): void
    {
        $nablus = Location::create(['canonical_name' => 'Nablus']);
        $user = $this->studentUser();
        StudentProfile::create(['user_id' => $user->id, 'university' => 'Old University']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/student/profile', [
            'university' => 'New University',
            'available_location_ids' => [$nablus->id],
        ]);

        $response->assertStatus(200)->assertJsonPath('data.university', 'New University');
    }

    private function studentUser(): User
    {
        return User::factory()->create(['role' => 'student', 'status' => 'active']);
    }
}
