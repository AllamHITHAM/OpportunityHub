<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Phase 8B-2: registration-time verification notification, the signed
 * `verification.verify` link, and the authenticated resend endpoint --
 * built entirely on Laravel's own `MustVerifyEmail`/signed-URL
 * infrastructure, no custom verification tokens. Registration/login are
 * deliberately never gated by verification status -- see
 * docs/BUSINESS_RULES.md for the explicit decision; the regression tests
 * at the bottom of this file prove that directly. Throttling is covered
 * separately in `AuthThrottleTest` -- see `PasswordResetTest`'s own doc
 * comment for why this file disables it globally.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // -----------------------------------------------------------------
    // Registration sends a verification notification
    // -----------------------------------------------------------------

    public function test_student_registration_sends_a_verification_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/register/student', [
            'name' => 'Jane Student',
            'email' => 'jane.verify@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $user = User::where('email', 'jane.verify@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_organization_registration_sends_a_verification_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/register/organization', [
            'name' => 'Jane Recruiter',
            'email' => 'org.verify@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_name' => 'Acme Corp',
            'organization_type' => 'company',
        ])->assertStatus(201);

        $user = User::where('email', 'org.verify@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_a_newly_registered_user_is_unverified(): void
    {
        $response = $this->postJson('/api/register/student', [
            'name' => 'Jane Student',
            'email' => 'unverified@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertJsonPath('data.user.email_verified', false);
        $this->assertNull(User::where('email', 'unverified@example.com')->firstOrFail()->email_verified_at);
    }

    // -----------------------------------------------------------------
    // Verifying via the signed link
    // -----------------------------------------------------------------

    public function test_a_valid_signed_link_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->get($this->signedVerificationUrl($user));

        $response->assertRedirect();
        $this->assertStringContainsString('status=success', $response->headers->get('Location'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verified_at_is_set_to_a_real_timestamp(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->signedVerificationUrl($user));

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_invalid_signature_is_rejected_safely_not_a_crash(): void
    {
        $user = User::factory()->unverified()->create();
        $tamperedUrl = $this->signedVerificationUrl($user).'-tampered';

        $response = $this->get($tamperedUrl);

        $response->assertRedirect();
        $this->assertStringContainsString('status=invalid', $response->headers->get('Location'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_signature_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('status=invalid', $response->headers->get('Location'));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_wrong_users_id_swapped_into_a_valid_link_fails_safely(): void
    {
        $attacker = User::factory()->unverified()->create();
        $victim = User::factory()->unverified()->create();

        // The attacker's own genuinely-signed link -- the signature covers
        // the `id` route parameter, so swapping in the victim's id (while
        // keeping the attacker's own hash/signature) must invalidate it.
        $attackerUrl = $this->signedVerificationUrl($attacker);
        $forgedUrl = str_replace("/{$attacker->id}/", "/{$victim->id}/", $attackerUrl);

        $response = $this->get($forgedUrl);

        $response->assertRedirect();
        $this->assertStringContainsString('status=invalid', $response->headers->get('Location'));
        $this->assertFalse($victim->fresh()->hasVerifiedEmail());
        $this->assertFalse($attacker->fresh()->hasVerifiedEmail());
    }

    public function test_a_nonexistent_user_id_fails_safely(): void
    {
        $hash = sha1('ghost@example.com');
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => 999999,
            'hash' => $hash,
        ]);

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('status=invalid', $response->headers->get('Location'));
    }

    public function test_verifying_an_already_verified_email_is_safe_and_idempotent(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->signedVerificationUrl($user);

        $this->get($url);
        $firstVerifiedAt = $user->fresh()->email_verified_at;

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('status=success', $response->headers->get('Location'));
        $this->assertSame(
            $firstVerifiedAt->toDateTimeString(),
            $user->fresh()->email_verified_at->toDateTimeString(),
        );
    }

    // -----------------------------------------------------------------
    // Resend
    // -----------------------------------------------------------------

    public function test_resend_requires_authentication(): void
    {
        $response = $this->postJson('/api/email/verification-notification');

        $response->assertStatus(401);
    }

    public function test_an_unverified_user_can_request_a_resend(): void
    {
        Notification::fake();
        // `status` set explicitly -- a freshly `create()`d model's
        // in-memory attributes don't reflect the DB-applied column
        // default until a re-fetch, and `actingAs()` uses this exact
        // in-memory object as the authenticated user, so the `active`
        // middleware would otherwise see a null status.
        $user = User::factory()->unverified()->create(['status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/verification-notification');

        $response->assertStatus(200)->assertJsonPath('success', true);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_an_already_verified_user_resending_is_safe_and_sends_nothing(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email_verified_at' => now(), 'status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/email/verification-notification');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Your email is already verified.');
        Notification::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Regression — verification never gates existing flows
    // -----------------------------------------------------------------

    public function test_registration_still_succeeds_without_any_verification_step(): void
    {
        $this->postJson('/api/register/student', [
            'name' => 'Regression Student',
            'email' => 'regression.student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);
    }

    public function test_login_still_succeeds_for_an_unverified_user(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'password123']);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_an_unverified_user_can_still_access_protected_routes(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/me')
            ->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function signedVerificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }
}
