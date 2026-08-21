<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

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
            'opportunity_type' => ['required', 'in:job,internship,volunteer,scholarship,competition'],
            'employment_type' => ['required', 'in:full_time,part_time,contract'],
            'work_mode' => ['required', 'in:remote,hybrid,onsite'],
            'experience_level' => ['required', 'in:no_experience,junior,mid,senior,expert'],
            'education_level' => ['nullable', 'in:high_school,diploma,bachelor,master,phd'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'salary_min' => ['nullable', 'numeric', 'min:0'],
            'salary_max' => ['nullable', 'numeric', 'min:0', 'gte:salary_min'],
            'application_deadline' => ['nullable', 'date', 'after_or_equal:today'],
            'positions_available' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,open,closed'],
            // Phase 8B-3.2: optional (not required) -- `field_of_study`
            // was never required either, and requiring this would be a
            // stricter new business rule than existed before, not just a
            // backward-compatible addition. Left absent entirely (not
            // even an empty array) on create means the Opportunity has no
            // explicit majors and falls back to `field_of_study` (or is
            // unrestricted) -- see `OpportunityEligibilityService`.
            'eligible_majors' => ['sometimes', 'array', 'max:10'],
            'eligible_majors.*' => [
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    if (is_string($value) && trim($value) === '') {
                        $fail('Eligible majors must not be blank.');
                    }
                },
            ],
        ];
    }
}
