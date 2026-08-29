<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\IndexClosedOpportunitiesRequest;
use App\Http\Requests\Organization\StoreOpportunityRequest;
use App\Http\Requests\Organization\UpdateOpportunityRequest;
use App\Models\Location;
use App\Models\Opportunity;
use App\Services\OpportunityExpirationService;
use App\Services\OpportunitySkillSyncService;
use App\Support\MajorNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OpportunityController extends Controller
{
    public function __construct(
        private readonly OpportunitySkillSyncService $skillSync,
        private readonly OpportunityExpirationService $expiration,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        // Final Company Profile Manual-E2E Bug Fix: lazily persists
        // status=open -> closed for any of this Organization's own
        // Opportunities whose deadline has passed, before listing them --
        // so this dashboard's Open/Closed counts are always accurate on
        // load, with no dependency on the scheduled sweep having run
        // recently. Scoped to this Organization only; never touches
        // another Organization's rows.
        $this->expiration->closeExpired($request->user()->organizationProfile->id);

        // Opportunity Requirements Integrity Patch: `opportunitySkills.skill`
        // must be eager-loaded here too, not just on show() -- this was the
        // actual root cause of Organization Opportunity Details showing
        // "No required skills configured" for an Opportunity that genuinely
        // has Required Skills. `loadOpportunityDetails()` on the Flutter
        // side reuses an already-loaded row from this list endpoint's
        // response instead of always re-fetching show() -- so whatever this
        // endpoint omits, Details silently omits too, even though
        // Recommended Candidates (which always re-queries fresh via
        // `OpportunityRecommendationController`) saw the real data all
        // along. Now both endpoints eager-load the identical relations, so
        // there is exactly one canonical shape for an Opportunity
        // regardless of which response ends up cached.
        $opportunities = $request->user()->organizationProfile->opportunities()
            ->with(['eligibleMajorRecords', 'opportunitySkills.skill'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Opportunities retrieved successfully',
            'data' => $opportunities,
        ]);
    }

    /**
     * Closed Opportunities Scalability Polish -- the Company Profile's
     * "Closed Opportunities" section's own real, backend-paginated/
     * filtered/sorted endpoint. Deliberately separate from [index] (which
     * the pre-existing Organization Opportunities management screen still
     * uses, unpaginated, every status at once, exactly as before) -- this
     * one is `closed`-only by construction (never a `status` query
     * param a caller could widen), scoped to the signed-in Organization's
     * own rows only, and never returns more than one page at a time so a
     * large closed history can never make a single response unbounded.
     *
     * `total_closed` (a sibling of the standard Laravel-paginator `data`
     * envelope, not nested inside it) is the Organization's real TOTAL
     * closed count, unaffected by whatever date filter is currently
     * applied -- the Company Profile "Closed Opportunities (N)" header
     * always reflects this, never the filtered page's own `total`, which
     * Flutter uses instead for its own "Showing X of Y" caption.
     */
    public function closed(IndexClosedOpportunitiesRequest $request): JsonResponse
    {
        $organizationId = $request->user()->organizationProfile->id;

        // Same lazy expiration sweep as index()/show() -- an Opportunity
        // that only just expired must be able to show up here (and in
        // the accurate total) the instant this section is opened, not
        // only after the next scheduled sweep.
        $this->expiration->closeExpired($organizationId);

        $query = Opportunity::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'closed');

        $this->applyClosedDateFilter($query, $request);

        // Newest first (most recently closed) is the documented default
        // (spec section 8) -- a legacy row with no known `closed_at`
        // (see the backfill migration's own doc comment) is always
        // placed AFTER every row with a known date, regardless of sort
        // direction, since its true position relative to them is
        // genuinely unknown -- never asserted either way.
        $query->orderByRaw('closed_at IS NULL')
            ->orderBy('closed_at', $request->string('sort', 'newest')->value() === 'oldest' ? 'asc' : 'desc');

        $opportunities = $query->paginate($request->integer('per_page', 15));

        $totalClosed = Opportunity::where('organization_id', $organizationId)
            ->where('status', 'closed')
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'Closed opportunities retrieved successfully',
            'data' => $opportunities,
            'total_closed' => $totalClosed,
        ]);
    }

    public function store(StoreOpportunityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $eligibleMajors = $data['eligible_majors'];
        $skills = $data['skills'];
        unset($data['eligible_majors'], $data['skills']);
        $this->mirrorLocationName($data);

        // Closed Opportunities Scalability Polish: the rare direct-create-
        // as-closed edge case (there's no `$oldStatus` for a brand new
        // row, so `closedAtAttributesForStatusChange()` doesn't apply
        // here) -- a newly created Opportunity that's already `closed`
        // really did just become closed, right now.
        if (($data['status'] ?? null) === 'closed') {
            $data['closed_at'] = now();
        }

        $opportunity = DB::transaction(function () use ($request, $data, $eligibleMajors, $skills) {
            $created = $request->user()->organizationProfile->opportunities()->create($data);

            $this->syncEligibleMajors($created, $eligibleMajors);
            $this->skillSync->sync($created, $skills);

            return $created;
        });

        return response()->json([
            'success' => true,
            'message' => 'Opportunity created successfully',
            'data' => $opportunity->fresh(['eligibleMajorRecords', 'opportunitySkills.skill']),
        ], 201);
    }

    public function show(int $opportunity, Request $request): JsonResponse
    {
        // Same lazy expiration sweep as index() -- opening a single
        // expired Opportunity's own Details view must never show a stale
        // `open` status either.
        $this->expiration->closeExpired($request->user()->organizationProfile->id);

        // Phase O8.2: `opportunitySkills.skill` is also eager-loaded here
        // (not just on the public endpoints) so Edit Opportunity's
        // Required Skills multi-select can prefill the Organization's own
        // existing selection.
        $opportunity = Opportunity::with(['eligibleMajorRecords', 'opportunitySkills.skill'])
            ->find($opportunity);

        if (! $opportunity || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Opportunity retrieved successfully',
            'data' => $opportunity,
        ]);
    }

    public function update(UpdateOpportunityRequest $request, int $opportunity): JsonResponse
    {
        $opportunity = Opportunity::find($opportunity);

        if (! $opportunity || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        $data = $request->validated();
        // `array_key_exists`, not `isset`/`??` -- an explicitly-sent empty
        // array is a real "clear the list" instruction and must be
        // distinguished from the key being absent entirely (which leaves
        // the existing set untouched). As of the Opportunity Requirements
        // Integrity Patch, an explicitly-sent EMPTY array is rejected by
        // `UpdateOpportunityRequest` (`min:1` when the key is present) --
        // an Organization can leave majors/skills untouched by omitting
        // the key, but can never clear either down to zero once the
        // Opportunity has at least one, keeping every Opportunity that
        // *does* have requirements from silently losing them, while a
        // historical Opportunity that already has zero stays fully
        // readable/editable until the Organization actually chooses to
        // set some.
        $hasEligibleMajors = array_key_exists('eligible_majors', $data);
        $eligibleMajors = $data['eligible_majors'] ?? null;
        $hasSkills = array_key_exists('skills', $data);
        $skills = $data['skills'] ?? null;
        unset($data['eligible_majors'], $data['skills']);
        $this->mirrorLocationName($data);

        // Closed Opportunities Scalability Polish: `status` is `nullable`
        // in `UpdateOpportunityRequest`, so it's simply absent from
        // `$data` (not merely `null`) whenever the client didn't send it
        // -- `array_key_exists`, matching the same convention already
        // used above for `eligible_majors`/`skills`. Only a request that
        // actually changes `status` can possibly transition `closed_at`;
        // omitting it (e.g. an edit that only touches the description)
        // must never touch `closed_at` either.
        if (array_key_exists('status', $data)) {
            $data = array_merge(
                $data,
                Opportunity::closedAtAttributesForStatusChange($opportunity->status, $data['status']),
            );
        }

        DB::transaction(function () use (
            $opportunity,
            $data,
            $hasEligibleMajors,
            $eligibleMajors,
            $hasSkills,
            $skills,
        ) {
            $opportunity->update($data);

            if ($hasEligibleMajors) {
                $this->syncEligibleMajors($opportunity, $eligibleMajors);
            }

            if ($hasSkills) {
                $this->skillSync->sync($opportunity, $skills);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Opportunity updated successfully',
            'data' => $opportunity->fresh(['eligibleMajorRecords', 'opportunitySkills.skill']),
        ]);
    }

    public function destroy(int $opportunity, Request $request): JsonResponse
    {
        $opportunity = Opportunity::find($opportunity);

        if (! $opportunity || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        // Company Profile Polish phase: tightened to also cover
        // Invitations and an authored shared Quiz Template -- both of
        // which used to cascade-delete silently (their FKs are
        // `cascadeOnDelete()`) whenever an Opportunity had zero
        // Applications. `hasRecruitmentHistory()` is the single source
        // of truth also exposed to the client as `can_delete`, so this
        // enforcement can never drift from what the UI decided to show.
        if ($opportunity->hasRecruitmentHistory()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete an opportunity that has recruitment history',
                'data' => null,
            ], 409);
        }

        $opportunity->delete();

        return response()->json([
            'success' => true,
            'message' => 'Opportunity deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Company Profile Polish phase: permanently deletes a *closed*
     * Opportunity from the Company Profile's own "Closed Opportunities"
     * cleanup UI -- a deliberately separate, narrower endpoint from
     * [destroy] above (which the pre-existing Organization Opportunities
     * management screen still uses, unrestricted by status, exactly as
     * before). This one additionally requires `status === 'closed'`
     * server-side -- never trusts Flutter to only show the button for a
     * closed row -- so a `draft`/`open` Opportunity can never be removed
     * through this specific path, even by someone calling the API
     * directly. Shares the exact same [Opportunity::hasRecruitmentHistory()]
     * dependency check as [destroy], so the two enforcement points can
     * never drift apart on what counts as "has history".
     */
    public function destroyClosed(int $opportunity, Request $request): JsonResponse
    {
        $opportunity = Opportunity::find($opportunity);

        if (! $opportunity || $opportunity->organization_id !== $request->user()->organizationProfile->id) {
            return response()->json([
                'success' => false,
                'message' => 'Opportunity not found',
                'data' => null,
            ], 404);
        }

        if ($opportunity->status !== 'closed') {
            return response()->json([
                'success' => false,
                'message' => 'Only a closed opportunity can be permanently deleted this way',
                'data' => null,
            ], 409);
        }

        if ($opportunity->hasRecruitmentHistory()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete an opportunity that has recruitment history',
                'data' => null,
            ], 409);
        }

        $opportunity->delete();

        return response()->json([
            'success' => true,
            'message' => 'Opportunity deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Closed Opportunities Scalability Polish -- the date half of the
     * `closed()` listing. A custom `closed_from`/`closed_to` range (when
     * either is present) always takes priority over `date_preset`, even
     * if both happen to be sent -- never silently ignores an explicit
     * range the client chose. Filters strictly against `closed_at`,
     * never `created_at` or `application_deadline`.
     *
     * A `null` `closed_at` (a legacy row with no reliable closure-time
     * signal -- see the backfill migration's own doc comment) is
     * EXCLUDED from every date-bounded preset/range below, since it can
     * never be proven to actually fall inside any of them -- except
     * `all` (no filter at all: everything, including unknown-date rows)
     * and `older`, which explicitly also includes them: an unknown-date
     * legacy row is, at minimum, definitely not known to have closed
     * recently, so grouping it with "not recently closed" is the one
     * preset where including it is honest rather than a guess.
     */
    private function applyClosedDateFilter(Builder $query, Request $request): void
    {
        $closedFrom = $request->filled('closed_from')
            ? Carbon::parse($request->input('closed_from'))->startOfDay()
            : null;
        $closedTo = $request->filled('closed_to')
            ? Carbon::parse($request->input('closed_to'))->endOfDay()
            : null;

        if ($closedFrom !== null || $closedTo !== null) {
            $query->whereNotNull('closed_at');
            if ($closedFrom !== null) {
                $query->where('closed_at', '>=', $closedFrom);
            }
            if ($closedTo !== null) {
                $query->where('closed_at', '<=', $closedTo);
            }

            return;
        }

        match ($request->string('date_preset', 'all')->value()) {
            'last_30_days' => $query->whereNotNull('closed_at')->where('closed_at', '>=', now()->subDays(30)),
            'last_3_months' => $query->whereNotNull('closed_at')->where('closed_at', '>=', now()->subMonths(3)),
            'last_6_months' => $query->whereNotNull('closed_at')->where('closed_at', '>=', now()->subMonths(6)),
            'this_year' => $query->whereNotNull('closed_at')->where('closed_at', '>=', now()->startOfYear()),
            'older' => $query->where(function (Builder $q): void {
                $q->whereNull('closed_at')->orWhere('closed_at', '<', now()->startOfYear());
            }),
            default => null, // 'all' -- no date filter; includes null closed_at rows too.
        };
    }

    /**
     * Keeps the legacy free-text `location` column in sync with whichever
     * canonical `location_id` was just chosen (Phase O8.2) -- purely
     * additive, and only ever touches `location` when the client actually
     * sent `location_id` in this request:
     *
     * - `location_id` absent entirely (key not in [$data]): leaves
     *   `location` completely untouched. A caller/test that predates this
     *   phase and never sends `location_id` behaves exactly as before.
     * - `location_id` present and a real ID: mirrors that Location's
     *   `canonical_name` into `location`, so every existing consumer of
     *   the plain-text field (Flutter display, etc.) keeps working
     *   unchanged without needing `location_id` itself.
     * - `location_id` present and `null` (e.g. switching to Remote):
     *   clears `location` to `null` too, rather than leaving a stale
     *   string behind for a location that's no longer set.
     *
     * `location_id` itself is left in [$data] either way -- it is a real
     * `opportunities` column and part of the same mass-assignment.
     */
    private function mirrorLocationName(array &$data): void
    {
        if (! array_key_exists('location_id', $data)) {
            return;
        }

        $data['location'] = $data['location_id'] === null
            ? null
            : Location::find($data['location_id'])?->canonical_name;
    }

    /**
     * Replaces [$opportunity]'s entire `eligibleMajors` set with
     * [$majorNames] (Phase 8B-3.2) -- delete-then-reinsert is the
     * simplest correct "sync", and this table is never large enough
     * (max:10, enforced by the request) for that to be a real cost.
     * Deduplicates by normalized value (first occurrence's original
     * casing wins), so two entries differing only in
     * case/whitespace collapse to one row, matching the table's own
     * `unique(opportunity_id, normalized_major_name)` constraint.
     *
     * @param  list<string>  $majorNames
     */
    private function syncEligibleMajors(Opportunity $opportunity, array $majorNames): void
    {
        $opportunity->eligibleMajorRecords()->delete();

        $seen = [];
        foreach ($majorNames as $majorName) {
            $trimmed = trim($majorName);
            $normalized = MajorNormalizer::normalize($trimmed);
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;

            $opportunity->eligibleMajorRecords()->create([
                'major_name' => $trimmed,
                'normalized_major_name' => $normalized,
            ]);
        }
    }
}
