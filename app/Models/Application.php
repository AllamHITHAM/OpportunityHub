<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Application extends Model
{
    protected $fillable = [
        'student_id',
        'opportunity_id',
        'cv_id',
        'cover_letter',
    ];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function cv(): BelongsTo
    {
        return $this->belongsTo(CV::class);
    }

    /**
     * The application's *current/latest* Assessment (Phase 10A.3) --
     * `hasOne(...)->latestOfMany(['created_at', 'id'])`, not a plain
     * `hasOne`. Before Phase 10A.3, an application could only ever have one
     * Assessment at all (`assessments.application_id` was unique), so this
     * accessor's behavior is unchanged for every application that still has
     * exactly one. Once an application can accumulate Assessment *history*
     * (a completed Quiz followed by a new Interview, for example — see
     * [assessments]), this deterministically resolves to the most recently
     * created one, never an arbitrary row — the explicit "current/latest
     * assessment" concept called for by this phase, not the ambiguous
     * "whichever happens to load first" a plain `hasOne` would silently
     * become once more than one row can exist.
     *
     * Safe to call `.create([...])` on exactly as before -- `HasOne::create()`
     * only ever sets the foreign key and saves; it never consults the
     * `ofMany` ordering scope, which only affects `SELECT`s (`->first()`,
     * `->exists()`, eager-loading). `.exists()` also still means exactly
     * what it always did -- "does this application have any Assessment at
     * all" -- since the underlying query resolves to zero rows when none
     * exist and exactly one (the latest) when any do.
     *
     * For the full ordered history (including this one), use [assessments].
     */
    public function assessment(): HasOne
    {
        return $this->hasOne(Assessment::class)->latestOfMany(['created_at', 'id']);
    }

    /**
     * Every Assessment ever created for this application, oldest first
     * (Phase 10A.3) -- a completed Quiz followed by a new Interview both
     * remain here, in full, forever; nothing is ever deleted or overwritten
     * to make room for the next stage of evaluation. Ordered
     * `created_at` then `id` (a deterministic tiebreak for same-instant
     * rows, matching every other chronological listing in this codebase --
     * see docs/BUSINESS_RULES.md). At most one of these is ever active
     * (non-final) at a time -- see `AssessmentService`'s active-assessment
     * invariant -- but this relation itself does not enforce or filter
     * that; it always returns the complete, real history.
     */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The organization's final Offer for this application (Phase 6C-1) --
     * at most one, enforced by the `offers.application_id` unique
     * constraint. Unlike [assessments] (Phase 10A.3), an application never
     * accumulates Offer history -- there is exactly one Offer, ever, per
     * application, so this stays a plain `hasOne` rather than needing an
     * `assessments()`/`assessment()` split.
     */
    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class);
    }

    /**
     * Every quiz attempt made against this application. A direct `hasMany`
     * on the real `quiz_attempts.application_id` column -- unlike
     * [interview], this needs no "through" relation. v1's one-attempt rule
     * means this holds at most one row per application in practice (the
     * `quiz_attempts` unique constraint enforces it), but the relation
     * itself stays a plain `hasMany` rather than an artificial `hasOne`,
     * matching [Quiz::attempts()]'s own reasoning.
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Read-only convenience accessor to reach this application's *most
     * recent* interview without going through `assessment` explicitly.
     * `interviews` no longer has an `application_id` column (see the
     * assessment retarget migration), so this is a genuine "through"
     * relation via `assessments` rather than a direct FK -- it does not
     * support `create()`/`save()`; use `assessment()->create([...])`
     * followed by `$assessment->interview()->create([...])` to create one.
     *
     * **Phase 10A.3**: explicitly ordered `assessments.id` descending, so
     * an application with more than one Interview-type Assessment in its
     * history (an uncommon but now-possible shape -- e.g. a declined first
     * interview followed by a second one) still resolves deterministically
     * to the latest, never an arbitrary row picked by unordered join
     * output. Before this phase an application could have at most one
     * Assessment ever, so this was always unambiguous regardless of
     * ordering; this makes that invariant explicit rather than relying on
     * a constraint that no longer holds.
     */
    public function interview(): HasOneThrough
    {
        return $this->hasOneThrough(
            Interview::class,
            Assessment::class,
            'application_id', // Foreign key on the assessments table.
            'assessment_id', // Foreign key on the interviews table.
            'id', // Local key on the applications table.
            'id', // Local key on the assessments table.
        )->orderByDesc('assessments.id');
    }

    protected function casts(): array
    {
        return [
            'match_score' => 'decimal:2',
            'applied_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
