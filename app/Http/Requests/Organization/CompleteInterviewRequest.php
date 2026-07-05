<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class CompleteInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => ['nullable', 'in:passed,failed,waiting'],
            'rating' => ['nullable', 'integer', 'min:1'],
            'company_feedback' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
