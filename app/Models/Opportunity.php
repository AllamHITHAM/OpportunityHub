<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Opportunity extends Model
{
    /**
     * @deprecated `field_of_study` (Opportunity Academic Matching
     * Cleanup) -- legacy free-text column, still present and still
     * `$fillable` for backward compatibility with any historical row/
     * caller, but no longer read by `MatchingService`, never displayed
     * in Create/Edit Opportunity or Opportunity Details, and never
     * consulted for eligibility (that remains `eligibleMajorRecords`
     * only, unchanged -- see that relation's own doc comment below).
     * Intentionally NOT dropped from the database in this phase pending
     * a full audit of every remaining reader (e.g. the public opportunity
     * search filter, which is a legitimate, unrelated "browse by keyword"
     * feature and is unaffected by this deprecation).
     */
    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'opportunity_type',
        'employment_type',
        'work_mode',
        'experience_level',
        'education_level',
        'field_of_study',
        'location',
        'location_id',
        'salary_min',
        'salary_max',
        'application_deadline',
        'positions_available',
        'status',
        'closed_at',
        'recruitment_process',
    ];

    /**
     * The raw `eligibleMajorRecords` relation is never serialized
     * directly -- each row carries `normalized_major_name`, an internal
     * comparison value that must never reach a client (see
     * `OpportunityEligibilityService`). `eligible_majors` below is the
     * only derived, always-safe view of it exposed on this model --
     * mirrors `StudentProfile`'s identical `educationVerification`/
     * `education_verification_status` split, including the same
     * "Eloquent's relation-hiding checks the camelCase relation name, not
     * the snake_case attribute it's derived from" gotcha that comment
     * documents.
     */
    protected $hidden = ['eligibleMajorRecords'];

    /**
     * `eligible_majors` (Phase 8B-3.2) is always appended -- the same
     * "derived, always-safe" convention `StudentProfile.education_verification_status`
     * already established -- so callers never need to remember to
     * eager-load `eligibleMajorRecords` just to see whether an
     * Opportunity is major-restricted. Never exposes
     * `normalized_major_name` (see `getEligibleMajorsAttribute()`).
     */
    protected $appends = ['eligible_majors', 'can_delete'];

    public function organizationProfile(): BelongsTo
    {
        return $this->belongsTo(OrganizationProfile::class, 'organization_id');
    }

    public function opportunitySkills(): HasMany
    {
        return $this->hasMany(OpportunitySkill::class);
    }

    /**
     * The canonical Location Catalog entry this Opportunity is at (Phase
     * O8.2) -- named `locationRecord`, not `location`, since the legacy
     * free-text `location` string column already occupies that attribute
     * name (same studly-case-collision risk documented on
     * [eligibleMajorRecords] above). `location_id` is nullable: a Remote
     * Opportunity never needs one, and a historical On-site/Hybrid
     * Opportunity created before this phase has none either -- see
     * `OpportunityEligibilityService::isLocationEligible()` for how a
     * missing `location_id` is handled truthfully (never guessed).
     */
    public function locationRecord(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * Explicit accepted majors (Phase 8B-3.2) -- see
     * `OpportunityEligibilityService` for how this gates Candidate
     * Search/Invitation/Apply. When empty, the Opportunity is
     * unrestricted -- `field_of_study` (deprecated, see its own note on
     * `$fillable` above) is never consulted as a fallback eligibility
     * restriction, and as of the Opportunity Academic Matching Cleanup is
     * also no longer a `MatchingService` scoring factor. This relation --
     * the canonical, ID-backed major data -- is the sole authoritative
     * academic signal anywhere in this app.
     *
     * Deliberately named `eligibleMajorRecords`, not `eligibleMajors` --
     * Eloquent studly-cases both a relation accessed via `$this->eligibleMajors`
     * and the `eligible_majors` appended attribute's accessor
     * (`getEligibleMajorsAttribute`) to the exact same "EligibleMajors",
     * so a relation with that literal name would collide with its own
     * accessor and never resolve as a relation at all (confirmed by
     * hitting `ErrorException: Undefined property` while building this).
     */
    public function eligibleMajorRecords(): HasMany
    {
        return $this->hasMany(OpportunityEligibleMajor::class);
    }

    /**
     * The organization-facing `major_name` values only, in insertion
     * order -- never `normalized_major_name`, which exists purely for
     * internal comparison (see `OpportunityEligibilityService`).
     *
     * @return list<string>
     */
    public function getEligibleMajorsAttribute(): array
    {
        return $this->eligibleMajorRecords->pluck('major_name')->values()->all();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Invitations (Phase 8B-3, Flow B) sent to Students for this
     * Opportunity -- distinct from [applications], which only ever holds
     * Student-submitted rows.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Phase 10A.4B -- the ONE shared Quiz definition (title/instructions/
     * settings/questions) authored once for this Opportunity and reused by
     * every candidate's own Assessment, when `recruitment_process = quiz`.
     * Distinct from a *legacy* per-candidate Quiz (`Quiz::assessment()`),
     * which never sets `opportunity_id` -- see that column's own migration
     * doc comment. `null` until the Organization creates it.
     */
    public function quizTemplate(): HasOne
    {
        return $this->hasOne(Quiz::class, 'opportunity_id');
    }

    /**
     * Company Profile Polish phase: true when this Opportunity has any
     * real recruitment history that must be preserved -- a real
     * Application, a real Invitation (even one that never became an
     * Application), or an authored shared Quiz Template (Phase 10A.4B).
     * The single source of truth `Organization\OpportunityController::destroy()`
     * enforces server-side (never trusts Flutter to hide the button) and
     * [getCanDeleteAttribute] exposes to the client so the UI can show an
     * explanatory state up front rather than only reacting to a failed
     * delete attempt.
     *
     * Deliberately status-independent -- this says nothing about whether
     * an Opportunity is `closed` (a separate, pre-existing concept); the
     * Company Profile screen additionally only ever offers permanent
     * delete for `closed` opportunities, but this flag itself must stay
     * correct for a `draft`/`open` Opportunity too, since `destroy()` has
     * always allowed deleting those (this phase only tightens what counts
     * as "has history", never narrows which statuses may be deleted).
     *
     * Conversations (Messaging MVP) are deliberately NOT part of this
     * check -- `conversations.opportunity_id` is `nullOnDelete()` by its
     * own design (see that migration's doc comment): message history is
     * preserved either way, so a Conversation existing is not a reason to
     * block deletion.
     */
    public function hasRecruitmentHistory(): bool
    {
        return $this->applications()->exists()
            || $this->invitations()->exists()
            || $this->quizTemplate()->exists();
    }

    public function getCanDeleteAttribute(): bool
    {
        return ! $this->hasRecruitmentHistory();
    }

    /**
     * Final Company Profile Manual-E2E Bug Fix: the ONE authoritative
     * "has this Opportunity's application_deadline passed" check --
     * previously duplicated inline inside `Student\ApplicationController::store()`
     * and nowhere else, which is exactly how a Student could already be
     * correctly rejected at apply-time while every read path (public
     * discovery, the Organization's own dashboard, Company Profile) kept
     * treating the same Opportunity as `open` forever.
     *
     * `application_deadline` is date-only (`casts(): ['application_deadline' => 'date']`),
     * so this deliberately compares whole days, not a raw timestamp --
     * the deadline's own calendar day still counts as open through its
     * entire 24 hours (end-of-day semantics), and only the day *after* it
     * is genuinely expired. Backend server time is authoritative; no
     * per-request/client timezone is ever consulted.
     */
    public function hasDeadlinePassed(): bool
    {
        return $this->application_deadline !== null
            && now()->startOfDay()->gt($this->application_deadline->copy()->startOfDay());
    }

    /**
     * True only when this Opportunity is both stored as `open` AND its
     * deadline (if any) has not passed -- the single "is this genuinely
     * open for applications right now" check reused by
     * `Public\OpportunityController::show()` and
     * `Student\ApplicationController::store()`'s first (existence/status)
     * guard, so a `closed`, `draft`, and a merely-expired-but-still-
     * `open`-in-the-database row are all treated identically by every
     * public-facing read path -- never just by the persisted `status`
     * column alone. See [scopeOpenForApplications] for the query-level
     * equivalent used by list endpoints.
     */
    public function isOpenForApplications(): bool
    {
        return $this->status === 'open' && ! $this->hasDeadlinePassed();
    }

    /**
     * Query-level equivalent of [isOpenForApplications] -- `status='open'`
     * AND (no deadline, or the deadline day has not yet passed). Used by
     * `Public\OpportunityController::index()` (and, transitively, Company
     * Profile's "Open Opportunities" section, which reuses that same
     * endpoint) so an expired Opportunity stops appearing as open in
     * real time, independent of whether the [closeExpired] auto-close
     * sweep (see `OpportunityExpirationService`) has already persisted
     * `status='closed'` for it yet.
     */
    public function scopeOpenForApplications(Builder $query): void
    {
        $query->where('status', 'open')
            ->where(function (Builder $q): void {
                $q->whereNull('application_deadline')
                    ->orWhereDate('application_deadline', '>=', today());
            });
    }

    protected function casts(): array
    {
        return [
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'application_deadline' => 'date',
            'positions_available' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Closed Opportunities Scalability Polish -- the `closed_at`
     * bookkeeping half of every `status` transition, computed once here
     * so `Organization\OpportunityController::store()`/`update()` (manual
     * create-as-closed / close / reopen) can never drift from each other
     * or from `OpportunityExpirationService` (automatic expiration,
     * which sets `closed_at` inline for the same reason but doesn't call
     * this -- it's always transitioning FROM `open`, so the "reopen"
     * branch below never applies there).
     *
     * Returns the attributes to merge into whatever mass-assignment call
     * is already happening -- never writes to the database itself, so
     * the caller's own transaction/single-`update()`-call semantics are
     * untouched:
     *   - `$oldStatus` wasn't `closed`, `$newStatus` is `closed`: really
     *     closing just now -- `['closed_at' => now()]`.
     *   - `$oldStatus` was `closed`, `$newStatus` isn't: a real reopen
     *     (the existing generic Edit Opportunity form already allows
     *     freely changing `status` back to `open`/`draft` -- this phase
     *     doesn't invent that, only makes it clear the closure timestamp
     *     the moment it happens) -- `['closed_at' => null]`.
     *   - Any other case (no real transition, or transitioning between
     *     two non-closed statuses): `[]` -- `closed_at` is left
     *     completely untouched.
     */
    public static function closedAtAttributesForStatusChange(?string $oldStatus, string $newStatus): array
    {
        if ($oldStatus !== 'closed' && $newStatus === 'closed') {
            return ['closed_at' => now()];
        }

        if ($oldStatus === 'closed' && $newStatus !== 'closed') {
            return ['closed_at' => null];
        }

        return [];
    }
}
