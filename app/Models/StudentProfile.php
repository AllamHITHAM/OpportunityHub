<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'university',
        'major',
        'graduation_year',
        'bio',
        'profile_image',
    ];

    protected $casts = [
        'graduation_year' => 'integer',
    ];

    /**
     * Phase 8B-1: the raw `EducationVerification` relation is never
     * serialized directly -- it can carry `rejection_reason` and
     * `reviewed_by_admin_id`, which an Organization must never see (see
     * docs/BUSINESS_RULES.md). [education_verification_status] below is
     * the only derived, always-safe view of it exposed on this model.
     *
     * Eloquent's relation-hiding checks `$hidden` against the loaded
     * relation's own array key -- the camelCase method name
     * (`educationVerification`), not the snake_case name it's renamed to
     * in the final serialized output -- so this must list the camelCase
     * form, unlike a normal hidden column.
     */
    protected $hidden = ['educationVerification'];

    protected $appends = ['education_verification_status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cvs(): HasMany
    {
        return $this->hasMany(CV::class, 'student_id');
    }

    public function studentSkills(): HasMany
    {
        return $this->hasMany(StudentSkill::class, 'student_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'student_id');
    }

    /**
     * Invitations (Phase 8B-3, Flow B) this Student has received from
     * Organizations -- distinct from [applications], which only holds
     * rows created through the Student-driven Apply flow.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'student_id');
    }

    public function educationVerification(): HasOne
    {
        return $this->hasOne(EducationVerification::class, 'student_id');
    }

    /**
     * `not_submitted`, `pending`, `verified`, or `rejected` -- the one
     * education-verification field safe to expose everywhere this model
     * is serialized (e.g. to an Organization via an Application response).
     * Callers needing the full detail (institution, rejection reason,
     * dates) must go through the dedicated Student/Admin endpoints
     * instead, never through this model directly.
     */
    public function getEducationVerificationStatusAttribute(): string
    {
        return $this->educationVerification?->status ?? 'not_submitted';
    }
}
