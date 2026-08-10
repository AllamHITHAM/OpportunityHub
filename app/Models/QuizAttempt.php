<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's one-and-only attempt at a [Quiz] (Phase 6B-3) --
 * `quiz_attempts.(quiz_id, application_id)` is unique, enforcing "one
 * attempt per application" at the database level. Deliberately has no
 * status enum: `submitted_at === null` means in progress,
 * `submitted_at !== null` means completed (and graded) -- see
 * `Student\QuizController` and docs/ARCHITECTURE.md.
 */
class QuizAttempt extends Model
{
    protected $fillable = [
        'quiz_id',
        'application_id',
        'answers',
        'score',
        'started_at',
        'submitted_at',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'score' => 'integer',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }
}
