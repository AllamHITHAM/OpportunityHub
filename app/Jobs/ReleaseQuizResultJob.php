<?php

namespace App\Jobs;

use App\Models\Assessment;
use App\Services\QuizResultReleaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Releases one candidate's already-graded Quiz result at its Quiz's
 * configured `result_release_at` time (Phase 10A.2) -- delayed until that
 * timestamp via Laravel's own queue `delay()`, backed by the real
 * `database` queue connection already configured in this project
 * (`QUEUE_CONNECTION=database`, a genuine `jobs` table -- not `sync`, not
 * faked). Requires a queue worker (`php artisan queue:work`, or an
 * equivalent supervised process in production) actually running for this
 * to fire -- this is the same operational requirement any Laravel queue
 * job has, not something specific to this feature; see
 * docs/BUSINESS_RULES.md for the explicit note.
 *
 * **Phase 10A.4B — keyed by Assessment, not Quiz.** Before this phase, a
 * Quiz had exactly one Assessment, so dispatching once per Quiz at publish
 * time was equivalent to dispatching once per Assessment. That equivalence
 * breaks once a Quiz can be a *shared template* referenced by many
 * candidates' Assessments (`Assessment.quiz_id`) -- there is no longer one
 * single Assessment "the" job could resolve from a Quiz id, and a
 * candidate's Assessment doesn't even exist yet at template-publish time.
 * So this job is now dispatched **per candidate Assessment**: for the
 * legacy ad-hoc path, still from `Organization\QuizController::publish()`
 * (the Assessment already exists then, exactly as before); for the shared
 * path, from `AssessmentService::advanceToSharedQuiz()`, at the moment each
 * candidate is individually advanced to the (already-published) shared
 * Quiz -- see that method's own doc comment.
 *
 * Implements `ShouldQueueAfterCommit` (not plain `ShouldQueue`) for the
 * same reason `QueuedTransactionalMail` does -- dispatched from inside a
 * `DB::transaction()`, this must only actually reach the queue once that
 * transaction commits, never before, and never at all if it rolls back.
 *
 * A pure no-op if the Student hasn't submitted by the time this fires --
 * `QuizResultReleaseService::attemptRelease()` itself already guards on
 * `completed_at !== null`, so this job carries no separate check of its
 * own. If the Student submits *after* this would have fired (a genuinely
 * late attempt), `Student\QuizController::submit()` attempts release
 * immediately at that point instead -- this job is never re-dispatched or
 * re-checked.
 *
 * **Phase 10A.4A**: firing at `result_release_at` is no longer sufficient
 * on its own -- the Organization's next-step decision must also be ready
 * (see `QuizResultReleaseService`'s own doc comment on the full readiness
 * rule). If it is, this releases exactly as before. If it isn't, this job
 * sends a dedup'd "Decision Required" reminder to the Organization instead
 * (`QuizResultReleaseService::sendDecisionReminder()`) and does **not**
 * reschedule itself or poll again later -- when the Organization
 * eventually completes the decision, the decision-completion action itself
 * detects that the scheduled time has already passed and releases
 * immediately (`QuizResultReleaseService::attemptRelease()`, called from
 * every `Organization\QuizController::setNextAction*()` method).
 */
class ReleaseQuizResultJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 300, 1800];

    public function __construct(public readonly int $assessmentId)
    {
    }

    public function handle(QuizResultReleaseService $resultRelease): void
    {
        $assessment = Assessment::find($this->assessmentId);

        if ($assessment === null) {
            return;
        }

        if ($resultRelease->attemptRelease($assessment)) {
            return;
        }

        // Either already released (nothing to do -- `sendDecisionReminder()`
        // itself also no-ops on an already-released Assessment, but this
        // avoids the extra query/transaction in the common case) or the
        // decision genuinely isn't ready yet.
        if (! $assessment->isResultReleased()) {
            $resultRelease->sendDecisionReminder($assessment);
        }
    }
}
