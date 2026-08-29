<?php

namespace App\Services;

use App\Models\Opportunity;

/**
 * The single canonical implementation of "replace an Opportunity's entire
 * Required/Preferred Skill set" (Opportunity Requirements Integrity Patch)
 * -- delete-then-reinsert, mirroring
 * `Organization\OpportunityController::syncEligibleMajors()`'s own
 * "sync the set cleanly" convention exactly.
 *
 * Used by BOTH `Organization\OpportunityController::store()`/`update()`
 * (so Create/Edit Opportunity can save Required Skills atomically, in the
 * same transaction as the Opportunity itself and its Eligible Majors --
 * never a second, later HTTP call that can leave a half-created
 * Opportunity with zero Required Skills) and
 * `Organization\OpportunitySkillController::sync()` (the standalone
 * re-sync endpoint, kept for any caller that only wants to change Skills).
 * One implementation, two entry points -- never a second, drifting copy of
 * this logic.
 *
 * @param  list<array{skill_id: int, is_required: bool}>  $skills
 */
class OpportunitySkillSyncService
{
    public function sync(Opportunity $opportunity, array $skills): void
    {
        $opportunity->opportunitySkills()->delete();

        // A duplicate skill_id within the same payload keeps only its last
        // occurrence -- mirrors syncEligibleMajors()'s own duplicate-
        // collapsing behavior, matching the `opportunity_skills` table's
        // `unique(opportunity_id, skill_id)` constraint.
        $bySkillId = collect($skills)->keyBy('skill_id')->values();

        foreach ($bySkillId as $skill) {
            $opportunity->opportunitySkills()->create([
                'skill_id' => $skill['skill_id'],
                'is_required' => $skill['is_required'],
            ]);
        }
    }
}
