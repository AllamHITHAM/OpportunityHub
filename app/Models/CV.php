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
        'version',
        'is_default',
        'created_by_ai',
    ];

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
