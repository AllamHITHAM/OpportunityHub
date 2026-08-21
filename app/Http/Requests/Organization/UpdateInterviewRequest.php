<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInterviewRequest extends FormRequest
{
    use InteractsWithInterviewRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Full-replace PUT semantics — every field uses the exact same shared
     * rule source as creation (`StoreInterviewRequest`/
     * `StoreAssessmentRequest`), so the conditional detail-per-type
     * requirement (Phase Final-QA-1) never drifts between create and
     * update.
     */
    public function rules(): array
    {
        return $this->interviewCreationRules();
    }
}
