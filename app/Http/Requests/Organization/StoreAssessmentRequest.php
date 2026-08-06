<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssessmentRequest extends FormRequest
{
    use InteractsWithInterviewRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `type=quiz` is deliberately accepted here (`in:interview,quiz`) --
     * it is a syntactically valid request that must reach the controller's
     * explicit "Quiz assessments are not available yet." business
     * rejection, not be turned away by generic validation. An unrecognized
     * `type` value (anything outside this list) still fails normal
     * Laravel validation.
     */
    public function rules(): array
    {
        return array_merge([
            'type' => ['required', 'in:interview,quiz'],
            'interview' => ['required_if:type,interview', 'array'],
        ], $this->interviewCreationRules('interview.'));
    }
}
