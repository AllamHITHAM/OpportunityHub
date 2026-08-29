<?php

namespace App\Http\Requests\Student;

use App\Support\OpportunityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentProfileRequest extends FormRequest
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
            // Candidate Opportunity Preferences patch: `sometimes`, matching
            // `available_location_ids`'/`eligible_majors`' own convention --
            // absent entirely leaves the Student's existing selection
            // untouched (an existing profile from before this patch, or one
            // simply not being edited right now, is never force-migrated);
            // present, it must be non-empty -- a Student cannot clear this
            // down to zero once they're actively setting it, matching the
            // product rule that at least one selection is required whenever
            // this preference is being completed/updated.
            'interested_in' => ['sometimes', 'array', 'min:1'],
            'interested_in.*' => [Rule::in(OpportunityType::ALL)],
            // Student Location Profile Patch: the Student's own current/
            // home location -- a single canonical Location Catalog ID,
            // optional, never free text. Distinct from
            // `available_location_ids` below (work-location willingness).
            'current_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            // Phase O8.2: the Student's own available/preferred work
            // locations -- canonical Location IDs only, never free text.
            // `sometimes`, matching `eligible_majors`' own convention:
            // absent entirely leaves the Student's existing selection
            // untouched; present (even as an empty array, to clear it)
            // replaces the whole set.
            'available_location_ids' => ['sometimes', 'array', 'max:20'],
            'available_location_ids.*' => ['integer', 'exists:locations,id'],
        ];
    }
}
