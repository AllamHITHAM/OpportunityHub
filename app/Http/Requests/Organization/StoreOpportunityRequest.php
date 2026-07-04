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
        ];
    }
}
