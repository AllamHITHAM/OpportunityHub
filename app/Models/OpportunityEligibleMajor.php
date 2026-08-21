<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One accepted major for an Opportunity (Phase 8B-3.2, multi-major
 * eligibility). See `OpportunityEligibilityService` for how this is used
 * to gate Candidate Search/Invitation/Apply, and the
 * `create_opportunity_eligible_majors_table` migration's own doc comment
 * for the schema/normalization rationale.
 */
class OpportunityEligibleMajor extends Model
{
    protected $fillable = [
        'opportunity_id',
        'major_name',
        'normalized_major_name',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }
}
