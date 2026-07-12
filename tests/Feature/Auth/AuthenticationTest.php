<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_registration_succeeds_with_valid_data(): void
    {
        $response = $this->postJson('/api/register/student', [
            'name' => 'Jane Student',
            'email' => 'jane.student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'jane.student@example.com')
            ->assertJsonPath('data.user.role', 'student')
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane.student@example.com',
            'role' => 'student',
        ]);
    }

    public function test_organization_registration_succeeds_with_valid_data(): void
    {
        $response = $this->postJson('/api/register/organization', [
            'name' => 'John Recruiter',
            'email' => 'org@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_name' => 'Acme Corp',
            'organization_type' => 'company',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'org@example.com')
            ->assertJsonPath('data.user.role', 'organization')
            ->assertJsonPath('data.user.organization_profile.organization_name', 'Acme Corp')
            ->assertJsonPath('data.user.organization_profile.approval_status', 'pending');

        $this->assertDatabaseHas('users', [
            'email' => 'org@example.com',
            'role' => 'organization',
        ]);

        $this->assertDatabaseHas('organization_profiles', [
            'organization_name' => 'Acme Corp',
            'approval_status' => 'pending',
        ]);
    }

    public function test_registration_fails_when_email_is_duplicated(): void
    {
        User::factory()->create(['email' => 'duplicate@example.com']);

        $response = $this->postJson('/api/register/student', [
            'name' => 'Another Student',
            'email' => 'duplicate@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_succeeds_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'wrongpass@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'wrongpass@example.com',
            'password' => 'incorrect-password',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_unauthenticated_user_cannot_access_me_endpoint(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_passwords_are_stored_hashed_not_plain_text(): void
    {
        $this->postJson('/api/register/student', [
            'name' => 'Hash Test',
            'email' => 'hash.test@example.com',
            'password' => 'plainTextPassword123',
            'password_confirmation' => 'plainTextPassword123',
        ])->assertStatus(201);

        $user = User::where('email', 'hash.test@example.com')->firstOrFail();

        $this->assertNotEquals('plainTextPassword123', $user->password);
        $this->assertTrue(Hash::check('plainTextPassword123', $user->password));
    }

    public function test_registered_student_receives_student_role(): void
    {
        $this->postJson('/api/register/student', [
            'name' => 'Role Check Student',
            'email' => 'role.student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'role.student@example.com',
            'role' => 'student',
        ]);
    }

    public function test_registered_organization_receives_organization_role(): void
    {
        $this->postJson('/api/register/organization', [
            'name' => 'Role Check Org',
            'email' => 'role.org@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_name' => 'Role Check Co',
            'organization_type' => 'company',
        ])->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'email' => 'role.org@example.com',
            'role' => 'organization',
        ]);
    }
}
