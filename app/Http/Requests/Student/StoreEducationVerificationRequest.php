<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreEducationVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Phase 8B-1: PDF only, max 5 MB -- same limits as `StoreCVRequest`.
     * Deliberately no `status`/`reviewed_at`/`reviewed_by_admin_id` fields
     * here -- a student can never set those; the controller never reads
     * them from the request at all.
     */
    public function rules(): array
    {
        return [
            'institution_name' => ['required', 'string', 'max:255'],
            'degree_or_program' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}
