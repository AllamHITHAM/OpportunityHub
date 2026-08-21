<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8B-2: rate limiting on the password-reset and verification-resend
 * endpoints, using Laravel's built-in `throttle` middleware (no custom
 * rate-limiter code). Each test targets a distinct route, so they don't
 * contaminate each other's bucket even though the `array` cache driver
 * used in tests persists across test methods in the same run.
 */
class AuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_is_throttled_after_five_attempts_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => "user{$i}@example.com"])
                ->assertStatus(200);
        }

        $this->postJson('/api/forgot-password', ['email' => 'one-too-many@example.com'])
            ->assertStatus(429);
    }

    public function test_reset_password_is_throttled_after_five_attempts_per_minute(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', [
                'email' => 'someone@example.com',
                'token' => 'invalid-token',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])->assertStatus(422);
        }

        $this->postJson('/api/reset-password', [
            'email' => 'someone@example.com',
            'token' => 'invalid-token',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(429);
    }

    public function test_verification_resend_is_throttled_after_six_attempts_per_minute(): void
    {
        // `status` set explicitly -- see EmailVerificationTest's own
        // comment on why `actingAs()` needs this spelled out rather than
        // relying on the DB column default.
        $user = User::factory()->unverified()->create(['status' => 'active']);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/email/verification-notification')
                ->assertStatus(200);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/verification-notification')
            ->assertStatus(429);
    }
}
