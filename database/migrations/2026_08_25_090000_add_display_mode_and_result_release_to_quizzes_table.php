<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10A.2 — two independent, purely additive Quiz-authoring
     * concerns, both organization-configured at quiz creation:
     *
     * - `display_mode`/`questions_per_page`: how Student Quiz-taking
     *   renders questions (one at a time, N per page, or all at once).
     *   Default `all` exactly matches the pre-Phase-10A.2 behavior (every
     *   question on one scrollable page), so every existing Quiz row
     *   keeps rendering identically with zero backfill needed.
     *
     * - `result_release_mode`/`result_release_at`: when the graded result
     *   becomes visible to the Student, independent of when it's computed
     *   (grading is always synchronous at submit -- see `quiz_attempts`).
     *   Default `immediate` exactly matches the pre-Phase-10A.2 behavior
     *   (result visible the moment the Student submits), so every
     *   existing completed attempt's result stays visible exactly as
     *   before -- see `assessments.result_released_at` (companion
     *   migration) for the actual visibility gate.
     */
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->enum('display_mode', ['single', 'paginated', 'all'])
                ->default('all')
                ->after('status');
            $table->unsignedInteger('questions_per_page')->nullable()->after('display_mode');
            $table->enum('result_release_mode', ['manual', 'immediate', 'scheduled'])
                ->default('immediate')
                ->after('questions_per_page');
            $table->timestamp('result_release_at')->nullable()->after('result_release_mode');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn([
                'display_mode',
                'questions_per_page',
                'result_release_mode',
                'result_release_at',
            ]);
        });
    }
};
