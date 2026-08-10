<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Quiz-type detail record for an [Assessment] (Phase 6B-1), the quiz
 * counterpart to [Interview]. Owns only quiz configuration (title,
 * instructions, time limit, passing score) and its own `draft`/`published`
 * authoring lifecycle -- never score/attempt/student-answer data, which
 * belongs to a future student-attempt model, not here.
 */
class Quiz extends Model
{
    protected $fillable = [
        'assessment_id',
        'title',
        'instructions',
        'time_limit_minutes',
        'passing_score',
        'status',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
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
        ];
    }
}
