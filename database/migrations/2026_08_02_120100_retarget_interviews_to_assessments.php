<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Retargets `interviews` from a direct `application_id` foreign key to
     * an `assessment_id` foreign key, backfilling exactly one `assessments`
     * row per pre-existing `interviews` row so no interview data is lost.
     *
     * Order matters and is deliberately split into five phases so the
     * table is never in a state where a constraint could reject valid,
     * pre-existing data:
     *   1. Add `assessment_id` nullable, unconstrained.
     *   2. Backfill `assessments` + populate `assessment_id` for every row.
     *   3. Only now make `assessment_id` NOT NULL (every row is populated).
     *   4. Only now add the FK + unique constraint on `assessment_id`.
     *   5. Only now drop the old `application_id` column/FK/unique.
     */
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->unsignedBigInteger('assessment_id')->nullable()->after('id');
        });

        DB::transaction(function () {
            $interviews = DB::table('interviews')->orderBy('id')->get();

            foreach ($interviews as $interview) {
                $assessmentId = DB::table('assessments')->insertGetId([
                    'application_id' => $interview->application_id,
                    'type' => 'interview',
                    'status' => $this->mapInterviewStatus($interview->status),
                    'result' => $this->mapInterviewDecision($interview->decision),
                    'completed_at' => $interview->completed_at,
                    'created_at' => $interview->created_at,
                    'updated_at' => $interview->updated_at,
                ]);

                DB::table('interviews')
                    ->where('id', $interview->id)
                    ->update(['assessment_id' => $assessmentId]);
            }
        });

        $this->makeColumnRequired('interviews', 'assessment_id');

        Schema::table('interviews', function (Blueprint $table) {
            $table->foreign('assessment_id')->references('id')->on('assessments')->cascadeOnDelete();
            $table->unique('assessment_id');
        });

        Schema::table('interviews', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropUnique(['application_id']);
            $table->dropColumn('application_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores `application_id` (nullable at first, backfilled from
     * `assessments.application_id` via each interview's `assessment_id`,
     * made NOT NULL, then constrained), then drops `assessment_id` and the
     * `assessments` table -- the exact reverse order of `up()`.
     */
    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->unsignedBigInteger('application_id')->nullable()->after('id');
        });

        DB::transaction(function () {
            $interviews = DB::table('interviews')->orderBy('id')->get();

            foreach ($interviews as $interview) {
                $assessment = DB::table('assessments')->where('id', $interview->assessment_id)->first();

                if ($assessment !== null) {
                    DB::table('interviews')
                        ->where('id', $interview->id)
                        ->update(['application_id' => $assessment->application_id]);
                }
            }
        });

        $this->makeColumnRequired('interviews', 'application_id');

        Schema::table('interviews', function (Blueprint $table) {
            $table->unique('application_id');
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
        });

        Schema::table('interviews', function (Blueprint $table) {
            $table->dropForeign(['assessment_id']);
            $table->dropUnique(['assessment_id']);
            $table->dropColumn('assessment_id');
        });
    }

    /**
     * Makes an existing, fully-populated, nullable `bigint unsigned` column
     * NOT NULL, without depending on doctrine/dbal.
     *
     * - MySQL/MariaDB: a plain `ALTER TABLE ... MODIFY` is always available
     *   on the `mysql` PDO driver (used by both), so a raw statement is
     *   simplest and needs no extra package.
     * - SQLite: there is no `ALTER COLUMN ... SET NOT NULL` at all. Laravel's
     *   native SQLite grammar (no doctrine/dbal involved) implements
     *   `Blueprint::change()` as a safe rebuild: it recreates the table with
     *   the new column definition, copies every row across, then swaps it
     *   in — exactly the "safe table-rebuild strategy" this needs, already
     *   built into the framework.
     */
    private function makeColumnRequired(string $table, string $column): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->unsignedBigInteger($column)->nullable(false)->change();
            });

            return;
        }

        DB::statement("alter table `{$table}` modify `{$column}` bigint unsigned not null");
    }

    /**
     * interviews.status -> assessments.status
     */
    private function mapInterviewStatus(string $status): string
    {
        return match ($status) {
            'scheduled', 'rescheduled' => 'scheduled',
            'completed', 'no_show' => 'completed',
            'cancelled' => 'cancelled',
            default => 'scheduled',
        };
    }

    /**
     * interviews.decision -> assessments.result. `pending` (no decision
     * recorded yet) maps to `null`, matching the convention documented on
     * the `assessments` table migration.
     */
    private function mapInterviewDecision(?string $decision): ?string
    {
        return match ($decision) {
            'passed', 'failed', 'waiting' => $decision,
            default => null,
        };
    }
};
