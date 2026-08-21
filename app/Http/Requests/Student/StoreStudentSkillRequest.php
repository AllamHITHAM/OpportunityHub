<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skill_id' => ['required', 'integer', 'exists:skills,id'],
            'level' => ['required', 'in:beginner,intermediate,advanced,expert'],
            'years_of_experience' => ['nullable', 'numeric', 'between:0,60'],

            // Phase 8A-6.1: evidence source. Defaults to `manual` in the
            // controller when omitted, matching every pre-existing Add
            // Skill call. `cv_id` is required only when claiming
            // `cv_ai` -- the controller then verifies real
            // CvSkillEvidence for (cv_id, skill_id, this student) before
            // ever trusting the claim; a client cannot spoof `cv_ai` by
            // sending it alone.
            'source' => ['nullable', 'in:manual,cv_ai'],
            'cv_id' => ['nullable', 'integer', 'exists:cvs,id', 'required_if:source,cv_ai'],
        ];
    }
}
