<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\Application;

/**
 * Strips organization-internal Application fields from student-facing
 * responses only -- the Application counterpart to
 * `HidesInternalInterviewFields`/`HidesInternalQuestionFields`. `Application`
 * deliberately has no `$hidden` of its own -- doing this globally on the
 * model would also hide `match_score` from the Organization endpoints that
 * generate and rely on it (see `Organization\ApplicationController`,
 * `ApplicationAnalysisController`). Applying `makeHidden()` per-instance
 * here, only from the Student response paths (`Student\ApplicationController::store/index`,
 * `Student\InterviewController::index` via the nested `assessment.application`,
 * `Student\AssessmentController::index/show` via the nested `application`),
 * keeps the Organization contract byte-for-byte unchanged while closing the
 * privacy gap on the Student side (Phase 8A-1).
 */
trait HidesInternalApplicationFields
{
    /**
     * - `match_score`: the AI/deterministic matching score
     *   (`App\Services\MatchingService`) is an organization-internal
     *   ranking aid, never a candidate-facing detail -- a student has no
     *   product reason to see how they were scored, and exposing it would
     *   also leak whether/how they were ranked against other applicants.
     */
    private const HIDDEN_APPLICATION_FIELDS = ['match_score'];

    private function hideInternalApplicationFields(?Application $application): void
    {
        $application?->makeHidden(self::HIDDEN_APPLICATION_FIELDS);
    }
}
