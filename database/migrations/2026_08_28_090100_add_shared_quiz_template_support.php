<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A.4B — lets ONE `Quiz` row (with its `Question`s) be authored
 * once for an Opportunity and shared across every candidate's Assessment,
 * instead of one private Quiz row per Application (the pre-10A.4B shape,
 * fully preserved below as "legacy").
 *
 * Two independent, additive changes, deliberately NOT unified into one:
 *
 * 1. `quizzes.opportunity_id` (new, nullable, unique) -- set only on a
 *    *shared template* Quiz (`Opportunity::quizTemplate()`), one per
 *    Opportunity. `quizzes.assessment_id` (now nullable, was required)
 *    stays the exact, untouched 1:1 shape for a *legacy* per-candidate
 *    Quiz -- the pre-existing ad-hoc "Choose Assessment -> Quiz" flow keeps
 *    writing it exactly as before; nothing about that flow changes in this
 *    migration or the code that follows it. A Quiz row has exactly one of
 *    `assessment_id`/`opportunity_id` set, never both, never neither --
 *    enforced at the application layer (see `App\Models\Quiz`), not a
 *    database CHECK constraint (this project's two real connections, MySQL
 *    and SQLite, don't share one portable way to express it).
 *
 * 2. `assessments.quiz_id` (new, nullable, self-contained FK to `quizzes.id`)
 *    -- set only when a candidate's Quiz-type Assessment *references* the
 *    Opportunity's shared template (`App\Services\AssessmentService::advanceToSharedQuiz()`).
 *    A legacy Assessment never sets this; its Quiz is still found the old
 *    way, through `Assessment::quiz(): HasOne`. `App\Models\Assessment::sharedQuiz(): BelongsTo`
 *    is the new relation this column backs -- a deliberately *separate*
 *    relation from the existing `quiz()`, not a conditional/merged one, so
 *    eager-loading either relation is always unambiguous regardless of
 *    which kind of Assessment is in the collection.
 *
 * `quizzes.assessment_id`'s existing `unique()` and FK are left completely
 * intact -- only its `NOT NULL` is relaxed. Both MySQL and SQLite treat
 * multiple `NULL`s as distinct under a unique index, so any number of
 * shared-template Quiz rows (which never populate `assessment_id`) can
 * coexist without violating it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->makeQuizAssessmentIdNullable();

        Schema::table('quizzes', function (Blueprint $table) {
            $table->foreignId('opportunity_id')
                ->nullable()
                ->unique()
                ->after('assessment_id')
                ->constrained('opportunities')
                ->cascadeOnDelete();
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('quiz_id')
                ->nullable()
                ->after('application_id')
                ->constrained('quizzes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quiz_id');
        });

        Schema::table('quizzes', function (Blueprint $table) {
            // Explicit 3-step order, not `dropConstrainedForeignId()` --
            // MySQL refuses to drop `quizzes_opportunity_id_unique` while
            // the FK constraint still depends on it ("Cannot drop index...
            // needed in a foreign key constraint"), so the FK must go
            // first; the column can only go last, after both constraints
            // referencing it are gone.
            $table->dropForeign(['opportunity_id']);
            $table->dropUnique(['opportunity_id']);
            $table->dropColumn('opportunity_id');
        });

        $this->makeQuizAssessmentIdRequired();
    }

    /**
     * Relaxes `quizzes.assessment_id` from `NOT NULL` to nullable without
     * depending on doctrine/dbal -- same driver split already established
     * by `2026_08_02_120100_retarget_interviews_to_assessments.php`'s
     * `makeColumnRequired()` (the exact inverse operation).
     */
    private function makeQuizAssessmentIdNullable(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('quizzes', function (Blueprint $table) {
                $table->foreignId('assessment_id')->nullable()->change();
            });

            return;
        }

        DB::statement('alter table `quizzes` modify `assessment_id` bigint unsigned null');
    }

    /**
     * Rollback counterpart -- only safe because every legacy row already
     * has `assessment_id` populated and no shared-template row (which would
     * have `assessment_id = null`) can exist unless this migration's `up()`
     * ran, so a straight `NOT NULL` restore never truncates real data.
     */
    private function makeQuizAssessmentIdRequired(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('quizzes', function (Blueprint $table) {
                $table->foreignId('assessment_id')->nullable(false)->change();
            });

            return;
        }

        DB::statement('alter table `quizzes` modify `assessment_id` bigint unsigned not null');
    }
};
