<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Organization-initiated invitation for a Student to apply to one of
 * its own Opportunities (Phase 8B-3, Flow B). Never creates an
 * `Application` by itself -- accepting only flips [status] to `accepted`
 * and the Student still goes through the same existing Apply flow
 * (`Student\ApplicationController::store()`) to actually submit one, since
 * `applications.cv_id` is required and no CV is chosen at invitation time.
 * See docs/ARCHITECTURE.md for the full Flow B convergence into Flow A.
 */
class Invitation extends Model
{
    protected $fillable = [
        'opportunity_id',
        'student_id',
        'message',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }
}
