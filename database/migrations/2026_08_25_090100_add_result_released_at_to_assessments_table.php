<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10A.2 — the actual Student-visibility gate for a Quiz's graded
     * result. Grading itself is unaffected: `Assessment.result` is still
     * written synchronously the moment the Student submits (see
     * `Student\QuizController::submit()`); this column separately records
     * *when the result became visible to the Student*, which may be the
     * same instant (`quiz.result_release_mode = 'immediate'`), a later
     * manual Organization action, or a scheduled release.
     *
     * Backward compatibility: every historical row (interview-type
     * assessments, and every quiz-type assessment completed before this
     * phase) is backfilled to `completed_at` in the same migration --
     * `completed_at` is already the moment grading/decision happened for
     * every one of them, under the pre-Phase-10A.2 "always immediately
     * visible" behavior this column now makes explicit. This is a pure
     * backfill of already-true history, not a behavior change: nothing
     * that was visible to a Student before this migration becomes hidden
     * by it, and nothing not yet completed is affected (`completed_at`
     * stays null for those, so does this column).
     */
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->timestamp('result_released_at')->nullable()->after('completed_at');
        });

        DB::table('assessments')
            ->whereNotNull('completed_at')
            ->update(['result_released_at' => DB::raw('completed_at')]);
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('result_released_at');
        });
    }
};
