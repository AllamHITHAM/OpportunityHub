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

    public function organizationProfile(): BelongsTo
    {
        return $this->belongsTo(OrganizationProfile::class, 'organization_id');
    }

    public function opportunitySkills(): HasMany
    {
        return $this->hasMany(OpportunitySkill::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
