<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class IndexPublicOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'opportunity_type' => ['nullable', 'in:job,internship,volunteer,scholarship,competition'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract'],
            'work_mode' => ['nullable', 'in:remote,hybrid,onsite'],
            'experience_level' => ['nullable', 'in:no_experience,junior,mid,senior,expert'],
            'location' => ['nullable', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
