<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closed Opportunities Scalability Polish -- the real, authoritative
     * "when did this Opportunity become closed" timestamp. Never
     * `updated_at`: that column changes for any unrelated edit (a typo
     * fix in the description, a salary tweak), so it was never a safe
     * stand-in for closure time.
     *
     * Set going forward by `Organization\OpportunityController::update()`
     * (manual close, and clearing it back to `null` on a genuine reopen —
     * see that controller's own doc comment) and by
     * `OpportunityExpirationService::closeExpired()` (automatic
     * expiration) -- both always write the real current backend
     * timestamp (`now()`) at the moment of transition, never a backdated
     * value.
     *
     * The one-time backfill below is the ONLY place a backdated value is
     * ever written, and only under a narrow, provably-correct condition:
     * an already-`closed` row whose `application_deadline` has already
     * passed is, by the exact same rule `OpportunityExpirationService`
     * itself uses, unambiguously known to have become closed at
     * (deadline + 1 day, start of day) -- the earliest moment that row
     * was genuinely expired, regardless of whether a scheduled sweep or
     * a manual edit is what actually flipped the stored `status`. Every
     * other closed row (no deadline, or a deadline that hasn't passed --
     * meaning it was closed manually, before its own natural expiry) has
     * no reliable signal for its true closure time and is deliberately
     * left `closed_at = null` rather than guessing from `updated_at`.
     * The UI handles this explicitly ("Closure date unavailable"), never
     * pretending a fake date.
     */
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('status');
        });

        DB::table('opportunities')
            ->where('status', 'closed')
            ->whereNotNull('application_deadline')
            ->orderBy('id')
            ->get(['id', 'application_deadline'])
            ->each(function ($row) {
                $expiredAt = Carbon::parse($row->application_deadline)->startOfDay()->addDay();

                // Only backfill when the deadline has genuinely already
                // passed as of right now -- a closed row whose deadline
                // is today or still in the future was closed manually,
                // before its own natural expiry, and has no reliable
                // closure-time signal at all.
                if ($expiredAt->lte(now())) {
                    DB::table('opportunities')
                        ->where('id', $row->id)
                        ->update(['closed_at' => $expiredAt]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
