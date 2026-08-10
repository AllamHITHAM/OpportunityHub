<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\Question;
use App\Models\Quiz;

/**
 * Strips organization-internal Question fields from student-facing
 * responses only -- the Quiz counterpart to `HidesInternalInterviewFields`.
 * `Question` deliberately has no `$hidden` of its own -- doing this
 * globally on the model would also hide `correct_answer` from the
 * Organization endpoints that authored it and rely on seeing it (see
 * `Organization\QuizController`). Applying `makeHidden()` per-instance
 * here, only from the Student response paths
 * (`Student\QuizController::show()`, `Student\AssessmentController::index/show()`),
 * keeps the Organization contract byte-for-byte unchanged while ensuring
 * `correct_answer` never reaches a student.
 *
 * Uses `Question::ORGANIZATION_ONLY_FIELDS` (defined alongside the model
 * back in Phase 6B-1 specifically for this purpose) as the single source of
 * truth for which fields are sensitive, rather than repeating the field
 * name here.
 */
trait HidesInternalQuestionFields
{
    private function hideInternalQuestionFields(?Quiz $quiz): void
    {
        if ($quiz === null) {
            return;
        }

        foreach ($quiz->questions as $question) {
            $question->makeHidden(Question::ORGANIZATION_ONLY_FIELDS);
        }
    }
}
