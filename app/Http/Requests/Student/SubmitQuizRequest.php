<?php

namespace App\Http\Requests\Student;

use App\Models\Quiz;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /student/quizzes/{quiz}/submit`. The route-bound
 * `{quiz}` (with its `questions` eager-loaded) is the source of truth this
 * request checks the submitted answers against -- never anything the
 * client supplies about scoring: `points`, `score`, and `correct_answer`
 * are never accepted as request input at all, only `question_id`/`answer`.
 *
 * Every quiz question must be answered exactly once (Phase 6B-3 has no
 * partial submission), each `question_id` must genuinely belong to this
 * quiz, and each `answer` must be structurally valid for its question's
 * `type` -- exactly matching one of the question's own `options` for
 * `multiple_choice`, or "True"/"False" (after canonicalization) for
 * `true_false`. None of this determines correctness -- grading itself
 * happens in `Student\QuizController::submit()`, after this request has
 * already guaranteed the answer shape is trustworthy.
 */
class SubmitQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.answer' => ['required', 'string'],
        ];
    }

    /**
     * Canonicalizes every true_false answer to exactly "True"/"False"
     * before validation runs, the same convention (and reasoning)
     * `Organization\Concerns\InteractsWithQuestionRules` already applies to
     * `correct_answer` -- "true"/"TRUE"/"1" (and the false equivalents) all
     * validate identically instead of failing on casing.
     */
    protected function prepareForValidation(): void
    {
        $quiz = $this->route('quiz');
        $answers = $this->input('answers');

        if (! $quiz instanceof Quiz || ! is_array($answers)) {
            return;
        }

        $quiz->loadMissing('questions');
        $questionTypes = $quiz->questions->pluck('type', 'id');

        foreach ($answers as $index => $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $questionId = $answer['question_id'] ?? null;
            if ($questionTypes->get($questionId) !== 'true_false') {
                continue;
            }

            $normalized = match (strtolower((string) ($answer['answer'] ?? ''))) {
                'true', '1' => 'True',
                'false', '0' => 'False',
                default => $answer['answer'] ?? null,
            };
            $answers[$index]['answer'] = $normalized;
        }

        $this->merge(['answers' => $answers]);
    }

    /**
     * Cross-field/cross-record checks the rule list above can't express --
     * every one of them needs the quiz's actual question set to check
     * against, not just the shape of the request body.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $quiz = $this->route('quiz');
            $answers = $this->input('answers');

            if (! $quiz instanceof Quiz || ! is_array($answers)) {
                return;
            }

            $quiz->loadMissing('questions');
            $questions = $quiz->questions->keyBy('id');
            $seenQuestionIds = [];

            foreach ($answers as $index => $answer) {
                if (! is_array($answer)) {
                    continue;
                }

                $questionId = $answer['question_id'] ?? null;
                $answerValue = $answer['answer'] ?? null;

                if ($questionId === null || ! $questions->has($questionId)) {
                    $validator->errors()->add(
                        "answers.{$index}.question_id",
                        'This question does not belong to the quiz.',
                    );

                    continue;
                }

                if (in_array($questionId, $seenQuestionIds, true)) {
                    $validator->errors()->add(
                        "answers.{$index}.question_id",
                        'This question has already been answered.',
                    );

                    continue;
                }
                $seenQuestionIds[] = $questionId;

                $question = $questions->get($questionId);

                if ($question->type === 'multiple_choice') {
                    $options = $question->options ?? [];
                    if (! in_array($answerValue, $options, true)) {
                        $validator->errors()->add(
                            "answers.{$index}.answer",
                            'The answer must match one of the question options.',
                        );
                    }
                } elseif ($question->type === 'true_false') {
                    if (! in_array($answerValue, ['True', 'False'], true)) {
                        $validator->errors()->add(
                            "answers.{$index}.answer",
                            'The answer must be True or False.',
                        );
                    }
                }
            }

            $missing = array_diff($questions->keys()->all(), $seenQuestionIds);
            if (! empty($missing)) {
                $validator->errors()->add('answers', 'Every quiz question must be answered.');
            }
        });
    }
}
