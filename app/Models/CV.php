<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CV extends Model
{
    /**
     * Explicit table name because Eloquent would otherwise infer "c_v_s" from the class name "CV".
     */
    protected $table = 'cvs';

    protected $fillable = [
        'student_id',
        'title',
        'file_path',
        'parsed_text',
        'version',
        'is_default',
        'created_by_ai',
    ];

    /**
     * Phase 8A-5: `parsed_text` (the CV's raw extracted PDF text) is
     * backend-only processing data for a future AI-matching phase, never
     * something a Student or Organization needs from the API -- hidden at
     * the model level (rather than per-response, like
     * `HidesInternalApplicationFields` does for `match_score`) because
     * there is no consumer of this API that should ever see it, so there's
     * no case to carve an exception for.
     */
    protected $hidden = ['parsed_text'];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'cv_id');
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'created_by_ai' => 'boolean',
            'version' => 'integer',
        ];
    }
}
