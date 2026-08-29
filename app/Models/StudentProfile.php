<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'current_location_id',
        'interested_in',
    ];

    protected $casts = [
        'graduation_year' => 'integer',
        // Candidate Opportunity Preferences patch: a JSON array of
        // canonical Opportunity Type values (`App\Support\OpportunityType`)
        // -- `null` for a Student who hasn't set one (existing profiles
        // from before this patch, never backfilled), an empty array is not
        // a valid persisted state (validation requires min:1 whenever this
        // is actually set).
        'interested_in' => 'array',
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

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'student_id');
    }

    /**
     * The Student's own selected set of available/preferred work locations
     * (Phase O8.2), each a real Location Catalog row -- never free text.
     * Empty is a completely valid, truthful state (a Student who hasn't
     * configured this yet), not an error; see
     * `OpportunityEligibilityService::isLocationEligible()` for how an
     * empty set is handled for an On-site/Hybrid Opportunity (excluded
     * from recommendations, never guessed).
     *
     * Deliberately distinct from [currentLocation] below -- a Student may
     * live in one city but be willing to work in several others.
     */
    public function availableLocations(): BelongsToMany
    {
        return $this->belongsToMany(
            Location::class,
            'student_available_locations',
        );
    }

    /**
     * The Student's own current/home location (Student Location Profile
     * Patch) -- a single canonical Location Catalog reference, optional,
     * and never consulted by On-site/Hybrid location eligibility (see
     * [availableLocations] and `OpportunityEligibilityService::isLocationEligible()`
     * for the field that actually gates recommendations). Purely
     * informational profile data, distinct from work-location
     * availability.
     */
    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
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
