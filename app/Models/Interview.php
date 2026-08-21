<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interview extends Model
{
    protected $fillable = [
        'assessment_id',
        'interview_type',
        'scheduled_at',
        'duration_minutes',
        'meeting_link',
        'location',
        'contact_phone',
        'interviewer_name',
        'interviewer_email',
        'notes',
    ];

    /**
     * `application` is not a real column any more (see the assessment
     * retarget migration) -- it's appended below purely so every Interview
     * API response keeps serializing the pre-Phase-4A-1 top-level
     * `data.application` shape, additively alongside the new
     * `data.assessment`. Never reintroduce `interviews.application_id`;
     * this is a read-only serialization concern, not a schema one.
     */
    protected $appends = ['application'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * Backward-compatible top-level `application` accessor, resolved
     * through `assessment` (loaded eagerly by every controller action that
     * returns an Interview, so this never triggers a surprise extra
     * query). Does not load `assessment.interview`, so serializing this
     * never recurses back into the Interview that owns it.
     */
    protected function application(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->assessment?->application,
        );
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
            'rating' => 'integer',
        ];
    }
}
