<?php

namespace App\Http\Requests\Student;

use App\Support\OpportunityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:20'],
            'university' => ['nullable', 'string', 'max:255'],
            'major' => ['nullable', 'string', 'max:255'],
            'graduation_year' => ['nullable', 'integer', 'between:1950,2100'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'profile_image' => ['nullable', 'string', 'max:255'],
            // Candidate Opportunity Preferences patch: the canonical
            // Opportunity Type(s) this Student wants to be recommended
            // for -- the exact same vocabulary `Opportunity.opportunity_type`
            // uses (`App\Support\OpportunityType`), never a second,
            // free-text vocabulary. Required at profile-setup time
            // (`required`, `min:1`) -- unlike every other field on this
            // request, this one is a mandatory step in onboarding, per
            // product decision. Multiple selections allowed (a Student may
            // genuinely want both Job and Internship opportunities).
            'interested_in' => ['required', 'array', 'min:1'],
            'interested_in.*' => [Rule::in(OpportunityType::ALL)],
            // Student Location Profile Patch: the Student's own current/
            // home location -- a single canonical Location Catalog ID,
            // optional, never free text.
            'current_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            // The Student's own available/preferred work locations --
            // canonical Location IDs only, never free text. Optional at
            // profile-setup time too (a Student may finish setup and add
            // these later via Edit Profile), `nullable`+`array` rather than
            // `sometimes`'s "leave untouched" semantics, since there is no
            // prior state to leave untouched on create.
            'available_location_ids' => ['nullable', 'array', 'max:20'],
            'available_location_ids.*' => ['integer', 'exists:locations,id'],
        ];
    }
}
