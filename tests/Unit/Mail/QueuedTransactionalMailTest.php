<?php

namespace Tests\Unit\Mail;

use App\Mail\QueuedTransactionalMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailables\Content;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7A-4.1: direct unit coverage for `QueuedTransactionalMail`, the
 * shared base class every workflow-triggered Mailable extends. Exercised
 * through a minimal concrete test-only subclass (not `OfferReceivedMail`)
 * so this file stays stable and independent of any one real Mailable's own
 * constructor/content shape as more workflow emails are added in Phase
 * 7A-4.2.
 *
 * Deliberately asserts the *public, stable* behavioral contract --
 * `instanceof ShouldQueueAfterCommit` (the actual mechanism Laravel's queue
 * layer checks, confirmed via `vendor/laravel/framework/.../Mail/SendQueuedMailable.php`
 * and `.../Queue/Queue.php::shouldDispatchAfterCommit()` -- see this class's
 * own doc comment) plus the public `tries`/`timeout`/`backoff`/`queue`
 * properties -- never an internal implementation detail that isn't part of
 * that contract.
 *
 * No database, no queue worker, no real send is needed for any of this --
 * these are structural facts about the class itself.
 */
class QueuedTransactionalMailTest extends TestCase
{
    public function test_implements_should_queue_after_commit(): void
    {
        $mail = new _TestQueuedTransactionalMail();

        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $mail);
    }

    public function test_should_queue_after_commit_extends_should_queue(): void
    {
        // ShouldQueueAfterCommit extends ShouldQueue, so implementing only
        // the former is sufficient -- this documents that relationship as
        // an explicit, tested fact rather than an assumption.
        $mail = new _TestQueuedTransactionalMail();

        $this->assertInstanceOf(ShouldQueue::class, $mail);
    }

    public function test_uses_the_emails_queue(): void
    {
        $mail = new _TestQueuedTransactionalMail();

        $this->assertSame('emails', $mail->queue);
    }

    public function test_tries_is_three(): void
    {
        $mail = new _TestQueuedTransactionalMail();

        $this->assertSame(3, $mail->tries);
    }

    public function test_timeout_is_sixty_seconds(): void
    {
        $mail = new _TestQueuedTransactionalMail();

        $this->assertSame(60, $mail->timeout);
    }

    public function test_backoff_is_30_300_1800_seconds(): void
    {
        $mail = new _TestQueuedTransactionalMail();

        $this->assertSame([30, 300, 1800], $mail->backoff);
    }
}

/**
 * A minimal concrete Mailable used only to exercise
 * `QueuedTransactionalMail`'s own base behavior in isolation.
 */
class _TestQueuedTransactionalMail extends QueuedTransactionalMail
{
    public function content(): Content
    {
        return new Content(htmlString: '<p>Test</p>');
    }
}
