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
 */
class Assessment extends Model
{
    protected $fillable = [
        'application_id',
        'type',
        'status',
        'result',
        'completed_at',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function interview(): HasOne
    {
        return $this->hasOne(Interview::class);
    }

    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
