<?php

namespace App\Support;

/**
 * The single canonical Opportunity Type vocabulary (`job`, `internship`,
 * `volunteer`, `scholarship`, `competition`) -- reused by both
 * `Opportunity.opportunity_type` and `StudentProfile.interested_in`
 * (Candidate Opportunity Preferences patch) so there is exactly one list
 * of valid values anywhere in the app, never a second, driftable
 * vocabulary. Both `StoreOpportunityRequest`/`UpdateOpportunityRequest`
 * and `StoreStudentProfileRequest`/`UpdateStudentProfileRequest` validate
 * against this same list.
 */
class OpportunityType
{
    public const ALL = [
        'job',
        'internship',
        'volunteer',
        'scholarship',
        'competition',
    ];
}
