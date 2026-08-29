<?php

namespace App\Http\Controllers\Student\Concerns;

use App\Models\Assessment;

/**
 * Strips `result` from a student-facing [Assessment] response until it's
 * actually been released (Phase 10A.2) -- see `Assessment::isResultReleased()`
 * and `QuizResultReleaseService`. Applies uniformly to both Assessment
 * types: an Interview's result is always released the instant it's
 * completed (`Organization\InterviewController::complete()` sets
 * `result_released_at` in the same instant as `completed_at`, unaffected
 * by this phase), so this never actually hides an Interview result in
 * practice -- only a Quiz result configured for manual/scheduled release
 * that hasn't reached its release moment yet.
 *
 * `status`/`completed_at` are left untouched -- the Student is always
 * allowed to know an assessment was completed, only the *result* (pass/
 * fail) is gated. Mirrors `HidesInternalQuestionFields`'s own
 * per-instance `makeHidden()` pattern rather than a model-global
 * `$hidden`, for the same reason: the Organization side must keep seeing
 * `result` unconditionally (see `Organization\AssessmentController`,
 * `Organization\QuizController::show()`), so this can only ever be
 * applied from Student response paths.
 *
 * **Phase 10A.4A**: also unconditionally strips the decision-internal
 * columns (`next_action`, `next_action_assessment_id`, `next_action_data`,
 * `next_action_prepared_at`, `origin_assessment_id`,
 * `decision_reminder_sent_at`) from every Student-facing Assessment
 * response, regardless of release state -- these are purely Organization-
 * internal bookkeeping (the Organization's own not-yet-released decision,
 * or the reminder-dedup timestamp) and must never reach a Student response
 * at all, released or not. This is a *different* concern from hiding
 * `result`, which is why both are stripped from the same method rather
 * than two separately-named ones -- both exist to keep this one trait the
 * single place that decides what a Student is and isn't shown on an
 * Assessment. Note this does **not** by itself hide a whole *linked*
 * Assessment (e.g. a staged "Advance to Interview" follow-up) from a
 * listing -- that's `Assessment::isPendingDecisionRelease()`, applied at
 * the query level in `Student\AssessmentController`/`Student\InterviewController`.
 */
trait HidesUnreleasedQuizResult
{
    private function hideUnreleasedQuizResult(?Assessment $assessment): void
    {
        if ($assessment === null) {
            return;
        }

        $assessment->makeHidden([
            'next_action',
            'next_action_assessment_id',
            'next_action_data',
            'next_action_prepared_at',
            'origin_assessment_id',
            'decision_reminder_sent_at',
        ]);

        if ($assessment->isResultReleased()) {
            return;
        }

        $assessment->makeHidden(['result']);
    }
}
