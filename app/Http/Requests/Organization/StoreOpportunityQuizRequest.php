<?php

namespace App\Http\Requests\Organization;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 10A.4B — validates the shared Quiz template's own settings
 * (`POST`/`PUT /organization/opportunities/{opportunity}/quiz`). The exact
 * same field set and cross-field rules (`display_mode`/`questions_per_page`,
 * `result_release_mode`/`result_release_at`) `StoreAssessmentRequest`
 * already validates for the legacy ad-hoc `quiz.*` payload -- duplicated
 * here rather than extracted into a shared concern, since the legacy
 * request's fields are conditionally `required_if:type,quiz` (nested under
 * a `type`-branching request) while every field here is unconditionally
 * required (this request is never about anything but a Quiz), a real enough
 * shape difference that a forced shared abstraction would need its own
 * parameterization to paper over. Reused unchanged for both create (POST)
 * and update (PUT) -- full-replace semantics, matching
 * `PUT /organization/interviews/{interview}`'s own established convention.
 * Never accepts `questions` -- those are still authored one at a time via
 * the existing `Organization\QuizController` question endpoints, unchanged.
 *
 * **Phase 10A.4B addendum**: `availability_delay_days`/`availability_time`/
 * `submission_window_hours` are the shared candidate-availability policy --
 * required here (this request is only ever about a *shared* template,
 * which this addendum's whole point is to always compute a real per-
 * candidate window for), unlike every other optional field above. Never
 * accepted by the legacy ad-hoc `quiz.*` payload (`StoreAssessmentRequest`)
 * at all -- that flow has no availability-window concept and stays exactly
 * as it was.
 */
class StoreOpportunityQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1'],
            'passing_score' => ['required', 'integer', 'min:0', 'max:100'],
            'display_mode' => ['nullable', 'in:single,paginated,all'],
            'questions_per_page' => ['nullable', 'integer', 'min:1'],
            'result_release_mode' => ['nullable', 'in:manual,immediate,scheduled'],
            'result_release_at' => ['nullable', 'date', 'after:now'],
            'availability_delay_days' => ['required', 'integer', 'min:0'],
            'availability_time' => ['required', 'date_format:H:i'],
            'submission_window_hours' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $displayMode = $this->input('display_mode', 'all');
            if ($displayMode === 'paginated' && ! $this->filled('questions_per_page')) {
                $validator->errors()->add(
                    'questions_per_page',
                    'Questions per page is required when display mode is paginated.',
                );
            }

            $releaseMode = $this->input('result_release_mode', 'immediate');
            if ($releaseMode === 'scheduled' && ! $this->filled('result_release_at')) {
                $validator->errors()->add(
                    'result_release_at',
                    'A release date/time is required when result release mode is scheduled.',
                );
            }
        });
    }
}
