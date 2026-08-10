<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quiz_id')
                  ->constrained('quizzes')
                  ->cascadeOnDelete();

            $table->foreignId('application_id')
                  ->constrained('applications')
                  ->cascadeOnDelete();

            // The student's submitted answers, normalized to
            // `[{question_id, answer}, ...]` by StudentQuizController::submit()
            // -- null until submission (Phase 6B-3 has no partial/autosave
            // concept; an attempt is either in progress with no answers
            // recorded yet, or submitted with all of them at once).
            $table->json('answers')->nullable();

            // An integer percentage (0-100), computed server-side at submit
            // time -- see docs/BUSINESS_RULES.md for the exact rounding
            // rule. Null until submission.
            $table->unsignedTinyInteger('score')->nullable();

            // Always set the moment the attempt is created (Start Quiz is a
            // single atomic action: create + stamp `started_at`) -- never
            // reset afterward, including on a resumed (re-)fetch of the same
            // attempt or a later save() during grading. This is what
            // server-side time-limit enforcement is measured from.
            //
            // Schema-nullable (application code always populates it, so it
            // is never actually null in practice) specifically to avoid a
            // MySQL/MariaDB footgun: with `explicit_defaults_for_timestamp`
            // OFF (this project's local server's default), the *first*
            // NOT-NULL `timestamp` column with no explicit default in a
            // table is silently given `DEFAULT CURRENT_TIMESTAMP ON UPDATE
            // CURRENT_TIMESTAMP` -- which would have quietly bumped
            // `started_at` forward on every later `save()` (e.g. grading at
            // submit time), corrupting time-limit enforcement. Nullable
            // columns are exempt from that implicit behavior.
            $table->timestamp('started_at')->nullable();

            // Null while the attempt is in progress; set once, atomically
            // with `answers`/`score`, at submission. A non-null value is
            // this table's only "submitted" signal -- there is deliberately
            // no separate status enum (see docs/ARCHITECTURE.md).
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            // Enforces "one attempt per application" at the database level,
            // not just in application logic -- also what a concurrent
            // double "Start" race resolves against (see
            // Student\QuizController::start()).
            $table->unique(['quiz_id', 'application_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
