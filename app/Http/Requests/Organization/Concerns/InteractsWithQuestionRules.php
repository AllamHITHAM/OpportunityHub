<?php

namespace App\Http\Requests\Organization\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * The single source of truth for Question create/update validation rules,
 * shared between `StoreQuestionRequest` and `UpdateQuestionRequest` (both
 * full-replacement PUT/POST bodies -- there is no partial-update variant).
 * Never copy these rules by hand into a second place -- both requests use
 * this trait.
 */
trait InteractsWithQuestionRules
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function questionRules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:2000'],
            'type' => ['required', 'in:multiple_choice,true_false'],
            // Not required for true_false -- its two choices are fixed and
            // never stored per-row (see Question model docblock). Whatever
            // is submitted for true_false is ignored by the controller,
            // which always persists `options = null` for that type.
            'options' => ['nullable', 'required_if:type,multiple_choice', 'array', 'min:2'],
            'options.*' => ['string', 'filled'],
            'correct_answer' => ['required', 'string'],
            'points' => ['nullable', 'integer', 'min:1'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Canonicalizes a true_false `correct_answer` to exactly "True"/"False"
     * before validation runs, so "true"/"TRUE"/"1" (and the false
     * equivalents) all validate identically instead of failing on casing --
     * see docs/BUSINESS_RULES.md for the documented True/False convention.
     * Left untouched for multiple_choice or any unrecognized `type`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('type') !== 'true_false' || ! $this->has('correct_answer')) {
            return;
        }

        $normalized = match (strtolower((string) $this->input('correct_answer'))) {
            'true', '1' => 'True',
            'false', '0' => 'False',
            default => $this->input('correct_answer'),
        };

        $this->merge(['correct_answer' => $normalized]);
    }

    /**
     * Cross-field checks the rule list above can't express: a true_false
     * `correct_answer` must be exactly "True"/"False" (after the
     * canonicalization above); a multiple_choice `correct_answer` must
     * exactly match one submitted option (case-sensitive -- options are
     * free-form organization-authored text, not a fixed enum).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('type');
            $correctAnswer = $this->input('correct_answer');

            if ($type === 'true_false') {
                if (! in_array($correctAnswer, ['True', 'False'], true)) {
                    $validator->errors()->add('correct_answer', 'The correct answer must be True or False.');
                }

                return;
            }

            if ($type === 'multiple_choice') {
                $options = $this->input('options');

                if (is_array($options) && ! in_array($correctAnswer, $options, true)) {
                    $validator->errors()->add('correct_answer', 'The correct answer must match one of the submitted options.');
                }
            }
        });
    }
}
