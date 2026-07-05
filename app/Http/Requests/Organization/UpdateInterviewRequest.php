<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interview_type' => ['required', 'in:onsite,online,phone'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'meeting_link' => ['required_if:interview_type,online', 'nullable', 'string', 'max:2048'],
            'location' => ['required_if:interview_type,onsite', 'nullable', 'string', 'max:255'],
            'interviewer_name' => ['nullable', 'string', 'max:255'],
            'interviewer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
