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
        ];
    }
}
