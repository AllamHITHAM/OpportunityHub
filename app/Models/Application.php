<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function interview(): HasOne
    {
        return $this->hasOne(Interview::class);
    }
}
