<?php

namespace App\Console\Commands;

use App\Services\OpportunityExpirationService;
use Illuminate\Console\Command;

/**
 * Final Company Profile Manual-E2E Bug Fix: the background half of
 * expiration handling -- see {@see OpportunityExpirationService}'s own doc
 * comment for the full picture (this command is the "eventually consistent
 * even for an Organization that never logs back in" sweep; the lazy call in
 * `Organization\OpportunityController` is what makes correctness NOT depend
 * on this command actually running, per the bug report's own "do not depend
 * on cron alone" instruction).
 *
 * Registered in `routes/console.php` via `Schedule::command(...)->daily()`.
 * Deployment requires a real cron entry running Laravel's scheduler --
 * standard for any Laravel app with scheduled tasks:
 *
 *   * * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
 *
 * Safe to run manually at any time (`php artisan opportunities:close-expired`)
 * -- idempotent, read-then-conditionally-update only, never destructive.
 */
class CloseExpiredOpportunitiesCommand extends Command
{
    protected $signature = 'opportunities:close-expired';

    protected $description = 'Persist status=open -> closed for every Opportunity whose application_deadline has passed.';

    public function handle(OpportunityExpirationService $expiration): int
    {
        $count = $expiration->closeExpired();

        $this->info("Closed {$count} expired opportunity(ies).");

        return self::SUCCESS;
    }
}
