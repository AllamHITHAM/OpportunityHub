<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 8B-2: `POST /api/forgot-password` and `POST /api/reset-password`,
 * built entirely on Laravel's own password-broker infrastructure
 * (`Password::sendResetLink()` / `Password::reset()`) -- no custom token
 * code exists. Throttling itself is covered separately in
 * `AuthThrottleTest`; this file disables the `throttle` middleware so
 * these functional/security tests can run any number of requests without
 * tripping it (Laravel's rate limiter uses the `array` cache driver in
 * tests, which persists across test methods in the same run/file).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // -----------------------------------------------------------------
    // Forgot password — privacy / validation
    // -----------------------------------------------------------------

    public function test_an_existing_users_forgot_password_request_is_accepted(): void
    {
        $user = User::factory()->create(['email' => 'exists@example.com']);

        $response = $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_an_unknown_email_gets_the_exact_same_safe_response(): void
    {
        $known = User::factory()->create(['email' => 'known@example.com']);

        $knownResponse = $this->postJson('/api/forgot-password', ['email' => $known->email]);
        $unknownResponse = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

        $knownResponse->assertStatus(200);
        $unknownResponse->assertStatus(200);
        $this->assertSame($knownResponse->json(), $unknownResponse->json());
        $unknownResponse->assertJsonPath(
            'message',
            'If an account exists for this email, password reset instructions have been sent.',
        );
    }

    public function test_a_malformed_email_is_rejected(): void
    {
        $response = $this->postJson('/api/forgot-password', ['email' => 'not-an-email']);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_a_missing_email_is_rejected(): void
    {
        $response = $this->postJson('/api/forgot-password', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_the_reset_notification_is_only_sent_for_a_real_account(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'real@example.com']);

        $this->postJson('/api/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPasswordNotification::class);

        Notification::fake();
        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);
        Notification::assertNothingSent();
    }

    public function test_a_secure_hashed_token_is_created_not_the_plaintext_value(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'token@example.com']);

        $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $plainToken = null;
        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$plainToken) {
                $plainToken = $notification->token;

                return true;
            },
        );

        $this->assertNotNull($plainToken);
        $row = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotNull($row);
        $this->assertNotSame($plainToken, $row->token);
        $this->assertTrue(Hash::check($plainToken, $row->token));
    }

    // -----------------------------------------------------------------
    // Reset password
    // -----------------------------------------------------------------

    public function test_a_valid_token_resets_the_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $token = $this->requestTokenFor($user);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ]);

        $response->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_the_new_password_is_stored_hashed(): void
    {
        $user = User::factory()->create();
        $token = $this->requestTokenFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertNotEquals('new-password-456', $fresh->password);
        $this->assertTrue(Hash::check('new-password-456', $fresh->password));
    }

    public function test_confirmation_is_required_and_must_match(): void
    {
        $user = User::factory()->create();
        $token = $this->requestTokenFor($user);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_a_short_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->requestTokenFor($user);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_an_invalid_token_is_rejected_safely(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->requestTokenFor($user);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'completely-wrong-token',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $token = $this->requestTokenFor($user);

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_a_token_cannot_be_reused_after_a_successful_reset(): void
    {
        $user = User::factory()->create();
        $token = $this->requestTokenFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'first-new-password',
            'password_confirmation' => 'first-new-password',
        ])->assertStatus(200);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'second-new-password',
            'password_confirmation' => 'second-new-password',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertTrue(Hash::check('first-new-password', $user->fresh()->password));
    }

    public function test_the_old_password_no_longer_works_after_reset(): void
    {
        $user = User::factory()->create(['email' => 'old.pw@example.com', 'password' => 'old-password-123']);
        $token = $this->requestTokenFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(200);

        $this->postJson('/api/login', [
            'email' => 'old.pw@example.com',
            'password' => 'old-password-123',
        ])->assertStatus(401);
    }

    public function test_the_new_password_works_for_login(): void
    {
        $user = User::factory()->create(['email' => 'new.pw@example.com', 'password' => 'old-password-123']);
        $token = $this->requestTokenFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(200);

        $this->postJson('/api/login', [
            'email' => 'new.pw@example.com',
            'password' => 'new-password-456',
        ])->assertStatus(200);
    }

    public function test_reset_works_for_a_student_account(): void
    {
        $this->assertResetWorksForRole('student');
    }

    public function test_reset_works_for_an_organization_account(): void
    {
        $this->assertResetWorksForRole('organization');
    }

    public function test_reset_works_for_an_admin_account(): void
    {
        $this->assertResetWorksForRole('admin');
    }

    private function assertResetWorksForRole(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'old-password-123']);
        $token = $this->requestTokenFor($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(200);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'new-password-456',
        ])->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Sanctum token revocation
    // -----------------------------------------------------------------

    public function test_existing_sanctum_tokens_are_revoked_after_a_successful_reset(): void
    {
        $user = User::factory()->create();
        $user->createToken('device-one');
        $user->createToken('device-two');
        $this->assertSame(2, $user->tokens()->count());

        $token = $this->requestTokenFor($user);
        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_a_failed_reset_attempt_never_revokes_existing_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('device-one');

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => 'wrong-token',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertStatus(422);

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Requests a reset link for [$user] and returns the real plaintext
     * token that was emailed -- captured from the faked notification, the
     * same way a real inbox would carry it.
     */
    private function requestTokenFor(User $user): string
    {
        Notification::fake();
        $this->postJson('/api/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use (&$token) {
                $token = $notification->token;

                return true;
            },
        );

        return $token;
    }
}
