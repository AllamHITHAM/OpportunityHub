<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The generic evaluation path an organization chooses for a shortlisted
 * application (interview or quiz). Owns the shared assessment lifecycle
 * (`type`, `status`, `result`, `completed_at`); type-specific detail
 * (scheduling/decision capture for interview, authoring/questions for quiz)
 * lives on the matching detail model -- [Interview] or, as of Phase 6B-1,
 * [Quiz]. Exactly one of `interview`/`quiz` is ever populated for a given
 * Assessment, matching its `type`.
 *
 * `result_released_at` (Phase 10A.2) is the Student-visibility gate for
 * `result` -- `result` itself is still written synchronously the instant
 * grading happens (`Student\QuizController::submit()`), completely
 * independent of when (or whether yet) it becomes visible to the Student.
 * `isResultReleased()` is the single place that check is made; every
 * Student-facing response must use it before exposing `result`/quiz score,
 * never read `result`/`completed_at` directly to decide visibility.
 * Interview-type assessments are unaffected by this column in practice --
 * `Organization\InterviewController::complete()` still sets `result`
 * directly and nothing in this phase changes when an Interview result
 * becomes visible (interviews were, and remain, immediately visible).
 *
 * **Phase 10A.4A** — for a `type=quiz` Assessment, `result_released_at`
 * being set now means more than "the technical score/pass-fail is
 * visible": it means the Organization's real next-step decision
 * (`next_action` -- `interview`/`offer`/`reject`) has *also* been released
 * as one coherent Student-facing update. A Quiz result release time alone
 * is never sufficient any more -- see `QuizResultReleaseService`'s own doc
 * comment for the full readiness rule (`isReadyToRelease()`), and
 * [nextActionAssessment]/[originAssessment]/[isPendingDecisionRelease] for
 * the decision-data model and the Student-visibility gate a staged
 * "Advance to Interview" follow-up Assessment needs before its own
 * release.
 */
class Assessment extends Model
{
    protected $fillable = [
        'application_id',
        'quiz_id',
        'available_at',
        'due_at',
        'type',
        'status',
        'result',
        'completed_at',
        'result_released_at',
        'next_action',
        'next_action_assessment_id',
        'next_action_data',
        'next_action_prepared_at',
        'origin_assessment_id',
        'decision_reminder_sent_at',
    ];

    /**
     * Phase 10A.4B addendum — the candidate-specific, frozen availability
     * window for a `type=quiz` Assessment referencing an Opportunity's
     * shared Quiz template. Computed exactly once, at
     * `AssessmentService::advanceToSharedQuiz()` time, from the shared
     * Quiz's policy (`availability_delay_days`/`availability_time`/
     * `submission_window_hours`) plus the assignment moment -- never
     * recalculated on read, so a later edit to the shared policy never
     * retroactively moves an already-assigned candidate's dates. Both
     * `null` for every legacy/ad-hoc Assessment (interview, or a private
     * Quiz) -- `null` means "no gating", i.e. immediately available with
     * no deadline, exactly the pre-addendum behavior, preserved
     * automatically with no special-casing anywhere this is read.
     */
    public function isAvailableNow(): bool
    {
        if ($this->available_at !== null && now()->lt($this->available_at)) {
            return false;
        }

        if ($this->due_at !== null && now()->gt($this->due_at)) {
            return false;
        }

        return true;
    }

    public function isUpcoming(): bool
    {
        return $this->available_at !== null && now()->lt($this->available_at);
    }

    public function isPastDue(): bool
    {
        return $this->due_at !== null && now()->gt($this->due_at);
    }

    /**
     * Phase 10A.4B addendum (section 12) -- a human-facing, derived-only
     * timing label for the Organization's views (Upcoming/Available/In
     * Progress/Submitted/Deadline Passed). Deliberately never a stored
     * enum -- there is nothing here a later phase needs to query or index
     * on, so it is computed fresh from `available_at`/`due_at` and the
     * candidate's own QuizAttempt every time. `null` for a non-quiz
     * Assessment (nothing to time).
     *
     * `$attempt` accepts anything exposing `->started_at`/`->submitted_at`
     * (a `QuizAttempt` model or a raw `DB::table()` row) so a caller that
     * already fetched attempts by another route (e.g.
     * `OpportunityQuizController::results()`'s single batched query,
     * keyed by `application_id`, to stay N+1-free across many candidates)
     * can pass it straight in. When omitted, falls back to reading
     * `quiz->attempts` -- which requires that relation already loaded
     * (every such caller already eager-loads it, and for a shared-quiz
     * Assessment, `withResolvedQuizRelation()` has already narrowed
     * `attempts` down to this candidate's own `application_id` -- never
     * another candidate's).
     */
    public function quizTimingStatus(?object $attempt = null): ?string
    {
        if ($this->type !== 'quiz') {
            return null;
        }

        if ($attempt === null && $this->quiz?->relationLoaded('attempts')) {
            $attempt = $this->quiz->attempts->first();
        }

        if ($this->isUpcoming()) {
            return 'upcoming';
        }

        if ($attempt?->submitted_at !== null) {
            return 'submitted';
        }

        if ($this->isPastDue()) {
            return 'deadline_passed';
        }

        if ($attempt?->started_at !== null) {
            return 'in_progress';
        }

        return 'available';
    }

    /**
     * Whether `result` is currently visible to the Student. `null` for
     * `completed_at` (nothing to release yet) always means `false` here
     * too, defensively -- there is nothing legitimate for
     * `result_released_at` to be set without `completed_at` also being
     * set, but this method never trusts that invariant blindly.
     */
    public function isResultReleased(): bool
    {
        return $this->completed_at !== null && $this->result_released_at !== null;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function interview(): HasOne
    {
        return $this->hasOne(Interview::class);
    }

    /**
     * The legacy, one-candidate-only Quiz this Assessment privately owns --
     * `null` when this Assessment instead *references* an Opportunity's
     * shared Quiz template (`quiz_id` set — see [sharedQuiz]). Deliberately
     * left as a plain, unconditional `hasOne` rather than merged with
     * [sharedQuiz] into one relation that branches on `quiz_id`: Eloquent
     * eager-loads a `hasOne`/`belongsTo` relation by building one query
     * shape from a representative model and applying it to the whole
     * collection, so a relation method that returns *different* relation
     * types depending on the row's own data would silently apply the wrong
     * query to some rows in a mixed legacy/shared-template collection. Two
     * separate, always-unambiguous relations avoid that risk entirely; use
     * [resolvedQuiz] where "whichever kind" is genuinely needed.
     */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    /**
     * Phase 10A.4B — the Opportunity's shared Quiz template this
     * Assessment references, when it was created via
     * `AssessmentService::advanceToSharedQuiz()` rather than the legacy
     * ad-hoc "Choose Assessment" flow. `null` for every legacy Assessment
     * (its Quiz is still `quiz()` above). See [quiz]'s own doc comment for
     * why this is a separate relation, not a merged/conditional one.
     */
    public function sharedQuiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    /**
     * The effective Quiz for this Assessment regardless of which of the two
     * mutually exclusive shapes it uses -- `quiz_id` set means [sharedQuiz],
     * otherwise the legacy [quiz]. A plain helper method, not a relation
     * (see [quiz]'s doc comment on why the two stay separate for
     * eager-loading safety) -- callers that need this resolved efficiently
     * across a collection should eager-load both `quiz` and `sharedQuiz`
     * (cheap: exactly one is ever non-null per row) rather than call this
     * inside a loop.
     */
    public function resolvedQuiz(): ?Quiz
    {
        return $this->quiz_id !== null ? $this->sharedQuiz : $this->quiz;
    }

    /**
     * Normalizes [resolvedQuiz] onto the `quiz` relation itself (via
     * `setRelation()`, not a real write), so every existing `data.quiz`
     * JSON shape -- and every existing `quiz.questions`/`quiz.attempts`
     * eager-load -- keeps serializing identically for API consumers
     * regardless of which of the two mutually exclusive Quiz shapes this
     * Assessment uses. Call immediately before returning an Assessment (or
     * a collection of them) as JSON wherever `quiz_id` might be set. Only
     * ever narrows a *shared* quiz's `attempts` down to this Assessment's
     * own `application_id` first -- a shared Quiz's `attempts()` relation
     * spans every candidate, and this method must never leak another
     * candidate's attempt into a single-Assessment response.
     */
    public function withResolvedQuizRelation(): static
    {
        if ($this->quiz_id === null) {
            return $this;
        }

        // Deliberately always re-fetches with `attempts` narrowed down to
        // this Assessment's own `application_id` -- never trusts an
        // already-loaded `sharedQuiz` relation, since a blanket eager-load
        // spec at the query level has no way to know each row needs a
        // *different* per-row `attempts` filter. The extra query per call
        // is bounded by how many quiz-type Assessments are in the response
        // (typically 0 or 1 per application), not a real N+1 concern here.
        $shared = $this->sharedQuiz()
            ->with(['questions', 'attempts' => fn ($q) => $q->where('application_id', $this->application_id)])
            ->first();

        $this->setRelation('quiz', $shared);

        return $this;
    }

    /**
     * Phase 10A.4A — the real, follow-up Interview Assessment this (Quiz)
     * Assessment's `next_action = 'interview'` decision points at, if any.
     * Only ever populated by `Organization\QuizController::setNextActionInterview()`
     * (never by the plain "Advance to Interview" / direct-Interview-
     * creation paths, which don't touch this column at all) -- its mere
     * presence *is* the Interview decision's readiness signal, see
     * `QuizResultReleaseService::isNextActionReady()`.
     */
    public function nextActionAssessment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_action_assessment_id');
    }

    /**
     * Phase 10A.4A — the reverse of [nextActionAssessment]: on a follow-up
     * Interview Assessment created via the "prepare next action" flow,
     * this points back at the Quiz Assessment whose decision it is. `null`
     * on every directly-created Interview Assessment (Path A, "Shortlisted
     * -> Interview directly") and on every Assessment that isn't a staged
     * decision's follow-up at all. This is the Student-visibility gate --
     * see `Assessment::isPendingDecisionRelease()` below and every
     * Student\*Controller this phase updated.
     */
    public function originAssessment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_assessment_id');
    }

    /**
     * Phase 10A.4A — true when this Assessment must be hidden from every
     * Student-facing endpoint: it's a follow-up to some other (origin)
     * Assessment's staged decision, and that origin hasn't been released
     * yet. `false` for a directly-created Interview (no `origin_assessment_id`
     * at all) and for any Assessment once its origin has actually been
     * released -- callers must `loadMissing('originAssessment')` first if
     * the relation isn't already loaded, since this reads it directly
     * rather than re-querying.
     */
    public function isPendingDecisionRelease(): bool
    {
        if ($this->origin_assessment_id === null) {
            return false;
        }

        return $this->originAssessment?->result_released_at === null;
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'result_released_at' => 'datetime',
            'next_action_data' => 'array',
            'next_action_prepared_at' => 'datetime',
            'decision_reminder_sent_at' => 'datetime',
            'available_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }
}
