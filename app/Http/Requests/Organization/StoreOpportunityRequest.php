<?php

namespace App\Http\Requests\Organization;

use App\Support\OpportunityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'opportunity_type' => ['required', Rule::in(OpportunityType::ALL)],
            'employment_type' => ['required', 'in:full_time,part_time,contract'],
            'work_mode' => ['required', 'in:remote,hybrid,onsite'],
            'experience_level' => ['required', 'in:no_experience,junior,mid,senior,expert'],
            'education_level' => ['nullable', 'in:high_school,diploma,bachelor,master,phd'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            // Phase O8.2: the canonical Location Catalog replaces free-text
            // `location` input for every new Opportunity -- see
            // `Organization\OpportunityController::store()`, which derives
            // the legacy `location` string column from this ID for
            // backward-compatible display. There is no raw `location`
            // string accepted from the client any more.
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'gte:salary_min'],
            'application_deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'positions_available' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,open,closed'],
            // Phase 10A.4B: the Organization declares, up front, what kind
            // of recruitment evaluation this Opportunity uses -- see the
            // `recruitment_process` migration's own doc comment for why
            // only these 3 values exist. `sometimes`, not `required` --
            // the real Flutter Create Opportunity form always makes this
            // an explicit, required choice at the UX layer (see
            // docs/BUSINESS_RULES.md), but the backend itself stays
            // backward compatible with every existing caller that doesn't
            // send it, falling back to the column's own safe `none`
            // default (fully unrestricted, exactly the pre-10A.4B ad-hoc
            // behavior) -- the same precedent `eligible_majors` (Phase
            // 8B-3.2) already established for this exact situation.
            'recruitment_process' => ['sometimes', 'in:none,interview,quiz'],
            // Opportunity Requirements Integrity Patch: every NEW
            // Opportunity must declare at least one Eligible Major --
            // `field_of_study` remains separate, descriptive-only metadata
            // and is never an eligibility fallback (see
            // `OpportunityEligibilityService`). This supersedes the
            // previous Phase 8B-3.2 decision to leave this optional; a
            // *historical* Opportunity created before this rule is
            // unaffected (see `UpdateOpportunityRequest`'s own note on why
            // saving one doesn't retroactively force this unless the
            // Organization actually edits its majors).
            'eligible_majors' => ['required', 'array', 'min:1', 'max:10'],
            'eligible_majors.*' => [
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (is_string($value) && trim($value) === '') {
                        $fail('Eligible majors must not be blank.');
                    }
                },
            ],
            // Opportunity Requirements Integrity Patch: every NEW
            // Opportunity must declare at least one Required/Preferred
            // Skill, saved atomically with the Opportunity itself (see
            // `OpportunityController::store()`) rather than through a
            // later, separate `PUT .../skills` call -- so creation can
            // never leave a half-created Opportunity with zero Skills.
            // Every skill references a real, canonical `skills.id` -- no
            // free-text skill name is ever accepted, matching
            // `SyncOpportunitySkillsRequest`'s identical shape/rules.
            'skills' => ['required', 'array', 'min:1', 'max:30'],
            'skills.*.skill_id' => ['required', 'integer', 'exists:skills,id'],
            'skills.*.is_required' => ['required', 'boolean'],
        ];
    }
}
