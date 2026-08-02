<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The generic evaluation path an organization chooses for a shortlisted
 * application (interview, and later quiz). Owns the shared assessment
 * lifecycle (`type`, `status`, `result`, `completed_at`); type-specific
 * detail (scheduling, decision capture, etc.) lives on the matching detail
 * model -- currently only [Interview]. A future `Quiz` detail model would
 * hang off this same table the same way.
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

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }
}
