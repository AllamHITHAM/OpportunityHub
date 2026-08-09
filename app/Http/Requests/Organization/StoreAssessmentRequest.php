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
     * `interview.*` and `quiz.*` are each only required when `type` selects
     * that assessment kind (see `interviewCreationRules()` and the `quiz.*`
     * rules below), so a `type=quiz` request is never rejected for missing
     * Interview fields, and vice versa. An unrecognized `type` value
     * (anything outside `interview`/`quiz`) still fails normal Laravel
     * validation. Quiz creation intentionally does not accept `questions`
     * here -- see `App\Http\Controllers\Organization\QuizController` for
     * adding them afterward, one at a time.
     */
    public function rules(): array
    {
        return array_merge([
            'type' => ['required', 'in:interview,quiz'],
            'interview' => ['required_if:type,interview', 'array'],
            'quiz' => ['required_if:type,quiz', 'array'],
            'quiz.title' => ['required_if:type,quiz', 'string', 'max:255'],
            'quiz.instructions' => ['nullable', 'string'],
            'quiz.time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'quiz.passing_score' => ['required_if:type,quiz', 'integer', 'min:0', 'max:100'],
        ], $this->interviewCreationRules('interview.'));
    }
}
