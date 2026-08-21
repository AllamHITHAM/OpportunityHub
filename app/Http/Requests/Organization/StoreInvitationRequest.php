<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `student_id`/`opportunity_id` existence is checked here; ownership
     * (the opportunity belongs to this organization) and eligibility (the
     * opportunity is open, the student is active, no duplicate/already-
     * applied conflict) are business rules the controller checks instead,
     * matching `ApplyToOpportunityRequest`'s own split -- a `Rule::exists`
     * scoped to `organization_id` here would collapse "doesn't exist" and
     * "belongs to someone else" into the same validation-error shape,
     * where the controller's own 404 is deliberately more specific.
     */
    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:student_profiles,id'],
            'opportunity_id' => ['required', 'integer', 'exists:opportunities,id'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
