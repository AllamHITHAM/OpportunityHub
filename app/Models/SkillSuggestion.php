<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8A-6.1: a candidate Skill catalog entry awaiting Admin review --
 * created when the AI CV extraction flow finds a skill name that doesn't
 * match anything in the existing Admin-owned catalog. Never itself a
 * Skill; approving one creates (or reuses) a real Skill row via
 * `approved_skill_id`. The schema allows `student`/`organization` as
 * future suggestion sources, but only `ai_cv` is ever created in this
 * phase.
 */
class SkillSuggestion extends Model
{
    protected $fillable = [
        'name',
        'normalized_name',
        'source',
        'status',
        'suggested_by_user_id',
        'approved_skill_id',
    ];

    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by_user_id');
    }

    public function approvedSkill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'approved_skill_id');
    }
}
