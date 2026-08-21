<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8A-6.1: proof that a specific CV's AI extraction actually
 * identified a specific catalog Skill for a specific student -- the only
 * thing that lets `StudentSkillController::store()` accept a
 * `source: cv_ai` claim rather than trusting the client. Written by
 * `AiSkillExtractionService::mapToCatalog()` whenever a suggestion
 * resolves to a real Skill; never contains parsed_text, AI prompt, or
 * provider response data.
 */
class CvSkillEvidence extends Model
{
    protected $table = 'cv_skill_evidence';

    protected $fillable = [
        'student_id',
        'cv_id',
        'skill_id',
    ];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    public function cv(): BelongsTo
    {
        return $this->belongsTo(CV::class, 'cv_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
