<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Final Company Profile Manual-E2E Bug Fix: background sweep for expired
// Opportunities -- see `CloseExpiredOpportunitiesCommand`'s own doc comment.
// This is a background-consistency measure, not a correctness dependency:
// `Organization\OpportunityController` already closes expired rows lazily
// on every read, and `Student\ApplicationController::store()` independently
// rejects an expired deadline regardless of persisted `status`.
Schedule::command('opportunities:close-expired')->daily();
