<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 8B-2: a thin subclass of Laravel's own `VerifyEmail` notification,
 * adding only queuing -- everything else (the signed
 * `verification.verify` URL generation, the mail content) is inherited
 * unchanged. Kept queued for the same reason every other outbound email in
 * this project is (see `App\Mail\QueuedTransactionalMail`): an SMTP call
 * must never block the request it was triggered from.
 *
 * Always dispatched *after* the triggering action's own transaction (if
 * any) has already committed -- see `AuthController::registerOrganization()`,
 * which calls `sendEmailVerificationNotification()` only after its
 * `DB::transaction()` closure returns, not from inside it. That sidesteps
 * needing `ShouldQueueAfterCommit` semantics here.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('emails');
    }
}
