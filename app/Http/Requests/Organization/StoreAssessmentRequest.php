<?php

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules;
use Illuminate\Contracts\Validation\Validator;
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
     *
     * `quiz.display_mode`/`quiz.questions_per_page`/
     * `quiz.result_release_mode`/`quiz.result_release_at` (Phase 10A.2)
     * are all optional -- omitting any of them leaves the DB column's own
     * default (`display_mode: all`, `result_release_mode: immediate`),
     * exactly matching pre-Phase-10A.2 behavior. Cross-field requirements
     * (`questions_per_page` required when `paginated`;
     * `result_release_at` required, and must be a future time, when
     * `scheduled`) can't be expressed as plain rules here -- see
     * `withValidator()`.
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
            'quiz.display_mode' => ['nullable', 'in:single,paginated,all'],
            'quiz.questions_per_page' => ['nullable', 'integer', 'min:1'],
            'quiz.result_release_mode' => ['nullable', 'in:manual,immediate,scheduled'],
            'quiz.result_release_at' => ['nullable', 'date', 'after:now'],
        ], $this->interviewCreationRules('interview.'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('type') !== 'quiz') {
                return;
            }

            $displayMode = $this->input('quiz.display_mode', 'all');
            if ($displayMode === 'paginated' && ! $this->filled('quiz.questions_per_page')) {
                $validator->errors()->add(
                    'quiz.questions_per_page',
                    'Questions per page is required when display mode is paginated.',
                );
            }

            $releaseMode = $this->input('quiz.result_release_mode', 'immediate');
            if ($releaseMode === 'scheduled' && ! $this->filled('quiz.result_release_at')) {
                $validator->errors()->add(
                    'quiz.result_release_at',
                    'A release date/time is required when result release mode is scheduled.',
                );
            }
        });
    }
}
