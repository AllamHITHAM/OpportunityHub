<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // `in_assessment` is never accepted here -- it must only ever be
        // reached through a real Assessment-creation workflow (see
        // AssessmentService::transitionToInAssessment()). `interview_scheduled`
        // is also excluded: allowing it as manual input let an organization
        // fabricate an "assessment exists" status with no Assessment row
        // behind it. Existing rows may still legitimately hold either value
        // (legacy data / assessment-driven writes) -- this rule only governs
        // what a client may request here, not what the column may contain.
        return [
            'status' => ['required', 'in:reviewed,shortlisted,accepted,rejected'],
        ];
    }
}
