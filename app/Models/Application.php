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

    public function assessment(): HasOne
    {
        return $this->hasOne(Assessment::class);
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
     * Read-only convenience accessor to reach this application's interview
     * without going through `assessment` explicitly. `interviews` no longer
     * has an `application_id` column (see the assessment retarget
     * migration), so this is a genuine "through" relation via `assessments`
     * rather than a direct FK -- it does not support `create()`/`save()`;
     * use `assessment()->create([...])` followed by
     * `$assessment->interview()->create([...])` to create one.
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
        );
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
