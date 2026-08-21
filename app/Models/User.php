<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Phase 8B-2: implements `MustVerifyEmail`. The trait providing
 * `hasVerifiedEmail()`/`markEmailAsVerified()`/`getEmailForVerification()`
 * is already inherited from the base `Illuminate\Foundation\Auth\User`
 * class this extends (confirmed directly from the installed framework
 * source: `Illuminate\Auth\MustVerifyEmail` is used by that base class
 * unconditionally) -- only the *interface* needed declaring here. Also
 * uses Laravel's built-in password-reset infrastructure
 * (`Illuminate\Auth\Passwords\CanResetPassword`), likewise already
 * inherited -- no custom reset-token code exists or is needed.
 *
 * No route uses `verified` middleware -- registration/login succeed
 * before verification, exactly as before this phase. See
 * docs/BUSINESS_RULES.md for the explicit gating decision.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * `email_verified` (Phase 8B-2) -- a plain boolean derived from
     * `email_verified_at`, appended so Flutter never has to parse a
     * nullable timestamp itself just to answer "is this verified?".
     * `email_verified_at` itself was already unhidden/visible before this
     * phase, so appending this alongside it introduces no new exposure.
     */
    protected $appends = ['email_verified'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getEmailVerifiedAttribute(): bool
    {
        return $this->hasVerifiedEmail();
    }

    /**
     * Sends the project's own queued notification (a thin
     * `ShouldQueue` subclass of Laravel's default `VerifyEmail`, see
     * `VerifyEmailNotification`) instead of the unqueued framework
     * default -- keeps email dispatch off the request/response path,
     * consistent with every other outbound email in this project (see
     * `QueuedTransactionalMail`).
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Sends the project's own queued notification (see
     * `ResetPasswordNotification`) instead of the unqueued framework
     * default -- same reasoning as `sendEmailVerificationNotification()`
     * above. Token generation itself is entirely Laravel's own
     * `Password` broker behavior, untouched by this override.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * The student profile that belongs to this user (only applies to student accounts).
     */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /**
     * The organization profile that belongs to this user (only applies to organization accounts).
     */
    public function organizationProfile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    /**
     * The in-app notifications sent to this user.
     *
     * Overrides Notifiable's default notifications() relation because this project
     * uses its own notifications table/schema instead of Laravel's database notification channel.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
