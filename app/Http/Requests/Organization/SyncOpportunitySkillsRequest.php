<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase O8.2 -- replaces an Opportunity's entire Required/Preferred Skill
 * set. Every skill is referenced by its real, canonical `skills.id`
 * (`exists:skills,id`) -- there is no free-text Skill name accepted here,
 * matching the addendum's explicit "Use Skill IDs" requirement.
 */
class SyncOpportunitySkillsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skills' => ['present', 'array', 'max:30'],
            'skills.*.skill_id' => ['required', 'integer', 'exists:skills,id'],
            'skills.*.is_required' => ['required', 'boolean'],
        ];
    }
}
