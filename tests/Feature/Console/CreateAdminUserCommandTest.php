<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Backend Configuration & Safety Pass: `admin:create` replaces
 * `AdminUserSeeder`, which contained a fixed, source-visible plaintext
 * password. This command must never accept an existing account being
 * silently overwritten, must validate its inputs, and must never end up
 * storing/echoing a plaintext password anywhere.
 */
class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_active_admin_with_an_explicitly_supplied_password(): void
    {
        $this->artisan('admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'a-strong-password',
        ])
            ->assertExitCode(0);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertTrue(Hash::check('a-strong-password', $admin->password));
    }

    public function test_prompts_interactively_and_confirms_the_password_when_not_supplied(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.com'])
            ->expectsQuestion('Password for the new Admin account', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->assertExitCode(0);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-strong-password', $admin->password));
    }

    public function test_a_password_confirmation_mismatch_fails_without_creating_a_user(): void
    {
        $this->artisan('admin:create', ['email' => 'admin@example.com'])
            ->expectsQuestion('Password for the new Admin account', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-different-password')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_an_invalid_email_is_rejected_without_creating_a_user(): void
    {
        $this->artisan('admin:create', [
            'email' => 'not-an-email',
            '--password' => 'a-strong-password',
        ])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_a_too_short_password_is_rejected_without_creating_a_user(): void
    {
        $this->artisan('admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'short',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_refuses_to_overwrite_an_existing_account(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->artisan('admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'a-strong-password',
        ])->assertExitCode(1);

        // Still exactly the one, original account -- never mutated.
        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
    }
}
