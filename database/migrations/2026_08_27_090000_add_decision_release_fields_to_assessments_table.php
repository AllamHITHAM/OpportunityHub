<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A.4A — a Quiz result release time alone is no longer sufficient
 * to release a Student-facing recruitment update; the Organization must
 * also have selected and readied a real next-step decision (Advance to
 * Interview / Proceed to Offer / Reject) for the same Assessment. These
 * columns are the backend-authoritative record of that decision -- see
 * `App\Services\QuizResultReleaseService`'s own doc comment for the full
 * readiness rule that reads them.
 *
 * All nullable, all `null` by default -- an existing (pre-10A.4A)
 * Assessment, including one already released under the old rules, is
 * completely unaffected: `next_action` stays `null` forever for it (no
 * backfill), and `QuizResultReleaseService::isReadyToRelease()` never
 * re-evaluates an already-released Assessment (`isResultReleased()` short-
 * circuits first), so a historical release is never retroactively
 * questioned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            // The Organization's selected next step for this (Quiz)
            // Assessment. Only ever meaningful on a `type=quiz` Assessment
            // that has been graded (`completed_at` set) -- see
            // `QuizResultReleaseService`.
            $table->enum('next_action', ['interview', 'offer', 'reject'])
                ->nullable()
                ->after('result_released_at');

            // Set only when `next_action = 'interview'` and the real,
            // fully-scheduled follow-up Interview Assessment has been
            // created for the same Application -- a self-referencing FK,
            // never a placeholder/fake row. This *is* the Interview
            // decision's readiness signal (see `isNextActionReady()`):
            // non-null means a real Interview genuinely exists.
            $table->foreignId('next_action_assessment_id')
                ->nullable()
                ->after('next_action')
                ->constrained('assessments')
                ->nullOnDelete();

            // Staged Offer terms when `next_action = 'offer'` -- the exact
            // same validated shape `SendOfferRequest`/`OfferService::sendOffer()`
            // already accept (title, salary_*, start_date, message). Kept
            // here, not as a real `Offer` row, specifically so nothing
            // Student-visible is created until release -- see
            // `Organization\QuizController::setNextActionOffer()`.
            $table->json('next_action_data')->nullable()->after('next_action_assessment_id');

            // When the Organization's decision became *ready* (readiness
            // itself is derived, not stored -- see
            // `QuizResultReleaseService::isNextActionReady()` -- but the
            // timestamp is kept for the Organization-facing "Next Step
            // selected on ..." display and as the Reject path's own
            // readiness signal, since Reject has no other data to check).
            $table->timestamp('next_action_prepared_at')->nullable()->after('next_action_data');

            // Set on a follow-up Interview Assessment created via the
            // Phase 10A.4A "prepare next action" flow (never on a directly
            // created Interview -- Path A, "Shortlisted -> Interview
            // directly", leaves this `null`) -- points back at the Quiz
            // Assessment whose decision it is. This is the Student-
            // visibility gate: an Assessment with a non-null
            // `origin_assessment_id` is hidden from every Student-facing
            // endpoint until that origin's own `result_released_at` is
            // set. See `App\Models\Assessment::originAssessment()` and
            // every Student\*Controller this phase updated.
            $table->foreignId('origin_assessment_id')
                ->nullable()
                ->after('next_action_prepared_at')
                ->constrained('assessments')
                ->nullOnDelete();

            // Sent-once guard for the Organization "Decision Required"
            // reminder (fires when a scheduled release time arrives with
            // no ready decision yet) -- prevents a queue retry or a second
            // scheduled-time check from creating a duplicate reminder row.
            $table->timestamp('decision_reminder_sent_at')->nullable()->after('origin_assessment_id');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_assessment_id');
            $table->dropConstrainedForeignId('next_action_assessment_id');
            $table->dropColumn(['next_action', 'next_action_data', 'next_action_prepared_at', 'decision_reminder_sent_at']);
        });
    }
};
