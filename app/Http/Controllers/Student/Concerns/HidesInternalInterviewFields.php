<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\Interview;

/**
 * Strips organization-internal Interview fields from student-facing
 * responses only. `Interview` deliberately has no `$hidden` of its own —
 * doing this globally on the model would also hide these fields from the
 * Organization endpoints that own and rely on them (see
 * `Organization\InterviewController`/`AssessmentController`). Applying
 * `makeHidden()` per-instance here, only from the three Student response
 * paths (`Student\AssessmentController::index/show`,
 * `Student\InterviewController::index`), keeps the Organization contract
 * byte-for-byte unchanged while closing the privacy gap on the Student side.
 */
trait HidesInternalInterviewFields
{
    /**
     * - `interviewer_email`: a staff member's personal work email, not a
     *   candidate-facing detail.
     * - `company_feedback` / `rating`: organization-internal post-interview
     *   evaluation data, only ever written via the Organization's own
     *   "complete interview" action (see `CompleteInterviewRequest`).
     * - `decision`: the raw DB column defaults to `pending` and is never
     *   null, so exposing it verbatim would leak an internal "not yet
     *   decided" signal even when nothing has been communicated. The
     *   student-facing outcome is `Assessment.result` (`null` until a real
     *   decision exists), already available via the eager-loaded
     *   `assessment` relation — nothing here needs to duplicate it.
     */
    private const HIDDEN_INTERVIEW_FIELDS = [
        'interviewer_email',
        'company_feedback',
        'rating',
        'decision',
    ];

    private function hideInternalInterviewFields(?Interview $interview): void
    {
        $interview?->makeHidden(self::HIDDEN_INTERVIEW_FIELDS);
    }
}
