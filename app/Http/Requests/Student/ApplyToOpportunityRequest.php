<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyToOpportunityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cv_id' => [
                'required',
                'integer',
                Rule::exists('cvs', 'id')->where('student_id', $this->user()->studentProfile->id),
            ],
            'cover_letter' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
