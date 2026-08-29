<?php

namespace App\Http\Requests\Organization;

use App\Support\OpportunityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOpportunityRequest extends FormRequest
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
            // Phase O8.2: see the identical note on StoreOpportunityRequest
            // -- `location_id` (canonical) replaces free-text `location`
            // for every future write; the legacy string column is derived
            // by the controller, never sent directly by the client.
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'gte:salary_min'],
            'application_deadline' => ['nullable', 'date'],
            'positions_available' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,open,closed'],
            // Phase 10A.4B: `sometimes`, matching `eligible_majors` above --
            // when present, replaces the value; when absent, the
            // Opportunity's existing `recruitment_process` is left
            // untouched (never silently reset to `none`). See
            // `StoreOpportunityRequest`'s own doc comment.
            'recruitment_process' => ['sometimes', 'in:none,interview,quiz'],
            // Phase 8B-3.2, tightened by the Opportunity Requirements
            // Integrity Patch: when the key is present, the controller
            // replaces the Opportunity's eligible-majors set with exactly
            // this list -- "sync the set cleanly" -- but it may no longer
            // be sent as an empty array (`min:1`): an Organization can
            // leave an existing set of majors untouched by omitting this
            // key entirely, but can never clear it down to zero. A
            // historical Opportunity that already has zero eligible majors
            // remains fully readable and editable for every OTHER field
            // without being forced to add one -- this rule only engages
            // when the Organization actually sends `eligible_majors`.
            // See `OpportunityController::update()`.
            'eligible_majors' => ['sometimes', 'array', 'min:1', 'max:10'],
            'eligible_majors.*' => [
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (is_string($value) && trim($value) === '') {
                        $fail('Eligible majors must not be blank.');
                    }
                },
            ],
            // Opportunity Requirements Integrity Patch: the identical
            // "sync the set cleanly, but never down to empty" rule as
            // `eligible_majors` above, for Required/Preferred Skills.
            // Omitting `skills` entirely leaves the existing set
            // untouched; sending it requires at least one entry.
            'skills' => ['sometimes', 'array', 'min:1', 'max:30'],
            'skills.*.skill_id' => ['required', 'integer', 'exists:skills,id'],
            'skills.*.is_required' => ['required', 'boolean'],
        ];
    }
}
