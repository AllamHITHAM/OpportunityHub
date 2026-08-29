<?php

namespace App\Services;

use App\Models\Opportunity;

/**
 * Final Company Profile Manual-E2E Bug Fix: the one place a `status='open'`
 * Opportunity whose `application_deadline` has passed is actually persisted
 * as `status='closed'`.
 *
 * This complements, and never replaces, {@see Opportunity::isOpenForApplications()}
 * / {@see Opportunity::scopeOpenForApplications()} -- those give every
 * public-facing read an immediate, real-time-correct answer even before this
 * service has ever run for a given row (closing the exact race window the
 * bug report warned about: "do not depend on cron alone for correctness").
 * This service exists so the *persisted* `status` column -- the one the
 * Organization's own Opportunities dashboard counts, and the one
 * `Organization\OpportunityController::destroyClosed()` requires literally
 * equal `closed` before allowing a permanent delete -- eventually catches up
 * to reality too, without requiring a UI-only relabeling hack.
 *
 * Called from two places:
 *   - Lazily, at the top of `Organization\OpportunityController::index()`/
 *     `show()`, scoped to the signed-in Organization's own rows -- so an
 *     Organization's own dashboard is always accurate the moment they load
 *     it, with zero dependency on a scheduler having run recently.
 *   - The scheduled `opportunities:close-expired` command (see
 *     `routes/console.php`), unscoped -- a background sweep so an
 *     Organization that never logs back in still eventually shows accurate
 *     status for any cross-organization/reporting reader.
 *
 * Idempotent: the query only ever matches rows still `status='open'` with a
 * real, already-past `application_deadline`, so calling this twice in a row
 * (or concurrently) never double-applies anything -- the second call always
 * matches zero rows for whatever the first call already closed (and so
 * `closed_at` is never overwritten on a subsequent call either). Never
 * touches `draft`. Never touches an Opportunity with no `application_deadline`
 * (it stays open indefinitely, exactly like today). Never deletes anything,
 * and never touches Applications/Invitations/Assessments/Quiz/QuizAttempts/
 * Interviews/Offers/Notifications -- this only ever assigns `status` and
 * `closed_at`.
 */
class OpportunityExpirationService
{
    /**
     * Persists `status: open -> closed` for every currently-expired
     * Opportunity, optionally scoped to one [$organizationId]. Returns the
     * number of rows actually transitioned just now (0 on a fully
     * up-to-date call -- e.g. the second call in a row).
     */
    public function closeExpired(?int $organizationId = null): int
    {
        return Opportunity::query()
            ->where('status', 'open')
            ->whereNotNull('application_deadline')
            ->whereDate('application_deadline', '<', today())
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            // Closed Opportunities Scalability Polish: `closed_at` is the
            // real moment this sweep detected and persisted the closure
            // (`now()`), never backdated to the deadline itself -- see
            // `Opportunity::closedAtAttributesForStatusChange()`'s own
            // doc comment for why only the one-time legacy migration
            // backfill is allowed to backdate.
            ->update(['status' => 'closed', 'closed_at' => now()]);
    }
}
