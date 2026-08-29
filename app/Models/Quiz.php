<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Quiz-type detail record for an [Assessment] (Phase 6B-1), the quiz
 * counterpart to [Interview]. Owns quiz configuration (title, instructions,
 * time limit, passing score, question display mode, result release mode --
 * Phase 10A.2) and its own `draft`/`published` authoring lifecycle -- never
 * score/attempt/student-answer data, which belongs to [QuizAttempt].
 *
 * `display_mode`/`questions_per_page` (Phase 10A.2) control how the Student
 * Quiz-taking screen paginates questions -- purely a Flutter rendering
 * concern, never validated/enforced server-side beyond the FormRequest rule
 * that keeps the two fields consistent (see
 * `App\Http\Requests\Organization\Concerns\InteractsWithQuizDisplayRules`).
 *
 * `result_release_mode`/`result_release_at` (Phase 10A.2) control *when*
 * the already-graded result on the linked [Assessment] becomes visible to
 * the Student -- see `Assessment.result_released_at` for the actual
 * visibility gate this configures. For a shared template (below), these
 * are the one general announcement policy every candidate's Assessment
 * reads identically; each candidate's own decision-readiness still gates
 * independently -- see `QuizResultReleaseService`.
 *
 * **Phase 10A.4B -- two mutually exclusive shapes, never both, never
 * neither:**
 * - **Legacy / ad-hoc** (`assessment_id` set, `opportunity_id` null): the
 *   pre-10A.4B shape, a private Quiz authored for exactly one candidate's
 *   Assessment via the "Choose Assessment" flow. Fully preserved, still
 *   the exact code path new ad-hoc quizzes use going forward -- this
 *   phase does not change or retire it.
 * - **Shared template** (`opportunity_id` set, `assessment_id` null): one
 *   per Opportunity (`opportunity_id` is unique), authored once via the
 *   Opportunity-level quiz endpoints and referenced by every candidate's
 *   own Assessment through `Assessment.quiz_id` (`Assessment::sharedQuiz()`)
 *   -- never by giving each candidate their own Quiz row. `assessment()`
 *   below resolves `null` for a template, by construction; template
 *   ownership/ownership-checking code must branch on `opportunity_id`
 *   instead (see `Organization\QuizController::ownsQuiz()`).
 *
 * **Phase 10A.4B addendum -- candidate availability POLICY** (as opposed to
 * the candidate-SPECIFIC calculated dates, which live on [Assessment] --
 * see `available_at`/`due_at` there): `availability_delay_days` (candidate
 * assignment + this many days = the calendar day availability opens),
 * `availability_time` (the wall-clock time of day, on that calendar day,
 * availability opens at -- a plain "HH:MM:SS" UTC value, this project's
 * entire datetime stack being UTC end to end with no per-user timezone
 * concept), `submission_window_hours` (how many hours after opening the
 * candidate has to submit). Only ever populated for a shared template --
 * always `null` on a legacy Quiz, which is exactly what makes a legacy
 * candidate's Assessment immediately available with no deadline (see
 * `AssessmentService::advanceToSharedQuiz()`).
 */
class Quiz extends Model
{
    protected $fillable = [
        'assessment_id',
        'opportunity_id',
        'title',
        'instructions',
        'time_limit_minutes',
        'passing_score',
        'status',
        'display_mode',
        'questions_per_page',
        'result_release_mode',
        'result_release_at',
        'availability_delay_days',
        'availability_time',
        'submission_window_hours',
    ];

    /**
     * The legacy, one-candidate-only owning Assessment -- `null` for a
     * shared template (`opportunity_id` set instead). See this class's own
     * doc comment for the two mutually exclusive shapes.
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * The Opportunity a shared template belongs to -- `null` for a legacy
     * per-candidate Quiz.
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * Every candidate Assessment referencing this Quiz through the new
     * `Assessment.quiz_id` column -- many, for a shared template (one per
     * candidate advanced to it); realistically at most one for an ad-hoc
     * ID reused this way, though nothing enforces that at the database
     * level the way the legacy `assessment_id` unique constraint does.
     * Deliberately separate from `assessment()` (never merged into one
     * conditional relation) -- see `App\Models\Assessment::sharedQuiz()`'s
     * own doc comment for why.
     */
    public function candidateAssessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'quiz_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every attempt at this quiz. A plain `hasMany`, not a scoped/awkward
     * "the current application's attempt" relation -- v1's one-attempt-per-
     * application rule is enforced by the `quiz_attempts` unique constraint
     * (see the migration) and application-level lookups
     * (`Student\QuizController`), not by the shape of this relation.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    protected function casts(): array
    {
        return [
            'time_limit_minutes' => 'integer',
            'passing_score' => 'integer',
            'questions_per_page' => 'integer',
            'result_release_at' => 'datetime',
            'availability_delay_days' => 'integer',
            'submission_window_hours' => 'integer',
            // `availability_time` is deliberately NOT cast to `datetime` --
            // it's a wall-clock TIME value with no date of its own; casting
            // it would force Carbon to invent one. Left as the raw
            // "HH:MM:SS" string MySQL/SQLite return for a TIME column.
        ];
    }
}
