<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A.4B addendum — the shared Quiz template's own candidate-
 * availability POLICY (configured once, per Opportunity), as distinct from
 * the candidate-SPECIFIC calculated dates it produces (`assessments.available_at`/
 * `due_at` — see the sibling migration). All three columns exist on every
 * `quizzes` row (legacy and template alike, matching this table's existing
 * "one shape, optional fields" convention) but are only ever populated for
 * a shared template; a legacy, per-candidate Quiz leaves them `null`
 * forever, which is exactly what makes it available immediately with no
 * deadline — see `AssessmentService::advanceToSharedQuiz()`'s own doc
 * comment for the computation this feeds.
 *
 * `availability_time` is a plain `TIME` column (a wall-clock value, no
 * timezone of its own) — this project's entire datetime stack is UTC end
 * to end (`config('app.timezone') === 'UTC'`, no `serializeDate()`
 * override, no per-user timezone concept anywhere), confirmed by audit
 * before writing this migration, so `availability_time` is read/written as
 * a plain "HH:MM" UTC clock value, the same convention every other
 * datetime in this codebase already uses -- never a second, inconsistent
 * timezone system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            // Candidate assignment + this many days = the calendar day
            // availability opens on.
            $table->unsignedInteger('availability_delay_days')
                ->nullable()
                ->after('result_release_at');

            // The wall-clock time of day, on that calendar day, availability
            // actually opens at.
            $table->time('availability_time')->nullable()->after('availability_delay_days');

            // How many hours after opening the candidate has to submit --
            // `due_at = available_at + submission_window_hours`.
            $table->unsignedInteger('submission_window_hours')
                ->nullable()
                ->after('availability_time');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn([
                'availability_delay_days',
                'availability_time',
                'submission_window_hours',
            ]);
        });
    }
};
