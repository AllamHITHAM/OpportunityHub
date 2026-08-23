<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCVRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Phase 8A-6.2: rename only — `title` is the only field this request
     * recognizes, so `validated()` can never carry `student_id`,
     * `file_path`, `parsed_text`, `version`, `is_default`, or
     * `created_by_ai` regardless of what the client sends. The global
     * `TrimStrings` middleware already trims the value before validation
     * runs, so `required` alone is enough to reject a whitespace-only
     * title.
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
