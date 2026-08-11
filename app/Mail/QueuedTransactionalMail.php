<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Shared base class for every workflow-triggered transactional email
 * (Phase 7A-4.1). A workflow email is always queued from inside the same
 * `DB::transaction()` as the business mutation it accompanies (see
 * `EmailService`/`NotificationService`) -- so every concrete Mailable that
 * extends this class inherits the one property that actually matters here:
 * it is only ever pushed onto the queue *after* that transaction commits,
 * never before, and never at all if the transaction rolls back.
 *
 * **After-commit mechanism.** This implements
 * `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` (which itself extends
 * `ShouldQueue`, so no separate `ShouldQueue` declaration is needed) rather
 * than relying on the `Queueable` trait's public `$afterCommit` property.
 * Confirmed directly from the installed framework source
 * (`vendor/laravel/framework/.../Mail/SendQueuedMailable.php`,
 * `vendor/laravel/framework/.../Queue/Queue.php::shouldDispatchAfterCommit()`):
 * `SendQueuedMailable`'s constructor sets the underlying queued job's
 * `afterCommit` flag to `true` whenever the wrapped Mailable is an instance
 * of `ShouldQueueAfterCommit`, and `Queue::shouldDispatchAfterCommit()`
 * honors that per-job flag regardless of `config('queue.connections.database.after_commit')`
 * (which stays `false` -- a global project-wide default, deliberately left
 * untouched by this phase). Implementing the interface is more robust than
 * setting the property directly: it cannot be silently lost by a subclass
 * constructor that forgets to set it, and it is queryable via `instanceof`
 * in tests.
 *
 * Every subclass must call `parent::__construct()` for the queue
 * name/retry/backoff/timeout configuration below to take effect.
 */
abstract class QueuedTransactionalMail extends Mailable implements ShouldQueueAfterCommit
{
    use Queueable, SerializesModels;

    /**
     * The number of times the queued job may be attempted before landing in
     * `failed_jobs`.
     */
    public int $tries = 3;

    /**
     * The number of seconds a single send attempt may run before timing
     * out. A single outbound email send never legitimately needs more than
     * this.
     */
    public int $timeout = 60;

    /**
     * Seconds to wait before each retry: 30s, then 5 minutes, then 30
     * minutes -- enough spacing to ride out a transient SMTP provider
     * hiccup without hammering it.
     */
    public array $backoff = [30, 300, 1800];

    public function __construct()
    {
        $this->onQueue('emails');
    }
}
