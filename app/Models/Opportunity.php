<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'description',
        'opportunity_type',
        'employment_type',
        'work_mode',
        'experience_level',
        'education_level',
        'field_of_study',
        'location',
        'salary_min',
        'salary_max',
        'application_deadline',
        'positions_available',
        'status',
    ];

    /**
     * The raw `eligibleMajorRecords` relation is never serialized
     * directly -- each row carries `normalized_major_name`, an internal
     * comparison value that must never reach a client (see
     * `OpportunityEligibilityService`). `eligible_majors` below is the
     * only derived, always-safe view of it exposed on this model --
     * mirrors `StudentProfile`'s identical `educationVerification`/
     * `education_verification_status` split, including the same
     * "Eloquent's relation-hiding checks the camelCase relation name, not
     * the snake_case attribute it's derived from" gotcha that comment
     * documents.
     */
    protected $hidden = ['eligibleMajorRecords'];

    /**
     * `eligible_majors` (Phase 8B-3.2) is always appended -- the same
     * "derived, always-safe" convention `StudentProfile.education_verification_status`
     * already established -- so callers never need to remember to
     * eager-load `eligibleMajorRecords` just to see whether an
     * Opportunity is major-restricted. Never exposes
     * `normalized_major_name` (see `getEligibleMajorsAttribute()`).
     */
    protected $appends = ['eligible_majors'];

    public function organizationProfile(): BelongsTo
    {
        return $this->belongsTo(OrganizationProfile::class, 'organization_id');
    }

    public function opportunitySkills(): HasMany
    {
        return $this->hasMany(OpportunitySkill::class);
    }

    /**
     * Explicit accepted majors (Phase 8B-3.2) -- see
     * `OpportunityEligibilityService` for how this (or, when empty, the
     * legacy `field_of_study`) gates Candidate Search/Invitation/Apply.
     *
     * Deliberately named `eligibleMajorRecords`, not `eligibleMajors` --
     * Eloquent studly-cases both a relation accessed via `$this->eligibleMajors`
     * and the `eligible_majors` appended attribute's accessor
     * (`getEligibleMajorsAttribute`) to the exact same "EligibleMajors",
     * so a relation with that literal name would collide with its own
     * accessor and never resolve as a relation at all (confirmed by
     * hitting `ErrorException: Undefined property` while building this).
     */
    public function eligibleMajorRecords(): HasMany
    {
        return $this->hasMany(OpportunityEligibleMajor::class);
    }

    /**
     * The organization-facing `major_name` values only, in insertion
     * order -- never `normalized_major_name`, which exists purely for
     * internal comparison (see `OpportunityEligibilityService`).
     *
     * @return list<string>
     */
    public function getEligibleMajorsAttribute(): array
    {
        return $this->eligibleMajorRecords->pluck('major_name')->values()->all();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Invitations (Phase 8B-3, Flow B) sent to Students for this
     * Opportunity -- distinct from [applications], which only ever holds
     * Student-submitted rows.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    protected function casts(): array
    {
        return [
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'application_deadline' => 'date',
            'positions_available' => 'integer',
        ];
    }
}
