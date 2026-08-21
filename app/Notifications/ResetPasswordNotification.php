<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 8B-2: a thin subclass of Laravel's own `ResetPassword`
 * notification, adding only queuing. The reset URL, mail copy, and the
 * token itself are all inherited unchanged from the framework class --
 * see `AppServiceProvider::boot()` for the one customization applied
 * globally (`ResetPassword::createUrlUsing()`, re-pointing the URL at the
 * Flutter reset screen). Kept queued for the same reason every other
 * outbound email in this project is (see `App\Mail\QueuedTransactionalMail`).
 *
 * Always dispatched from `AuthController::forgotPassword()`, which has no
 * surrounding `DB::transaction()` of its own to worry about racing --
 * `Password::sendResetLink()` writes the reset token row synchronously
 * before this notification is ever queued.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->onQueue('emails');
    }
}
