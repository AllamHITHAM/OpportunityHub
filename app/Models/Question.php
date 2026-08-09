<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single auto-graded question on a [Quiz] (Phase 6B-1). `multiple_choice`
 * stores its choices in `options`; `true_false` leaves `options` `null` --
 * its two choices are fixed and never stored per-row (see
 * docs/BUSINESS_RULES.md for the documented convention a client/UI can rely
 * on instead of reading them from this record).
 */
class Question extends Model
{
    /**
     * ORGANIZATION-INTERNAL fields. `correct_answer` is safe to return to
     * the organization that authored the quiz (it wrote the answer key
     * itself), but MUST NEVER be included in a future Student-facing Quiz
     * API response -- any student serialization path must hide every field
     * named here, the same way `HidesInternalInterviewFields` hides
     * Interview's organization-internal fields today. See
     * tests/Unit/Models/QuestionPrivacyTest.php and docs/BUSINESS_RULES.md.
     */
    public const ORGANIZATION_ONLY_FIELDS = ['correct_answer'];

    protected $fillable = [
        'quiz_id',
        'prompt',
        'type',
        'options',
        'correct_answer',
        'points',
        'position',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'points' => 'integer',
            'position' => 'integer',
        ];
    }
}
