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
     * Adds the generic `in_assessment` value to `applications.status`. This
     * is the new status any Assessment-creation workflow (interview today,
     * quiz later) writes going forward; `interview_scheduled` stays in the
     * enum purely so existing rows keep loading -- no row is rewritten here.
     *
     * Same doctrine/dbal-free driver split as
     * 2026_08_02_120100_retarget_interviews_to_assessments.php's
     * `makeColumnRequired()`: MySQL/MariaDB get a raw `MODIFY`, SQLite uses
     * Laravel's native `Blueprint::change()` (a safe rebuild-and-copy under
     * the hood), since neither needs doctrine/dbal.
     */
    public function up(): void
    {
        $this->setStatusEnum([
            'pending',
            'reviewed',
            'shortlisted',
            'in_assessment',
            'interview_scheduled',
            'accepted',
            'rejected',
            'withdrawn',
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * The pre-migration enum has no `in_assessment` value, so any row
     * currently holding it is first moved back to `shortlisted` -- the
     * status every `in_assessment` application necessarily came from, and
     * the same safe fallback `AssessmentService`/`InterviewController`
     * already use elsewhere -- before the column is shrunk back down. This
     * keeps rollback non-destructive instead of erroring or truncating.
     */
    public function down(): void
    {
        DB::table('applications')->where('status', 'in_assessment')->update(['status' => 'shortlisted']);

        $this->setStatusEnum([
            'pending',
            'reviewed',
            'shortlisted',
            'interview_scheduled',
            'accepted',
            'rejected',
            'withdrawn',
        ]);
    }

    /**
     * @param  list<string>  $values
     */
    private function setStatusEnum(array $values): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('applications', function (Blueprint $table) use ($values) {
                $table->enum('status', $values)->default('pending')->change();
            });

            return;
        }

        $quoted = implode(',', array_map(fn (string $value) => "'{$value}'", $values));

        DB::statement("alter table `applications` modify `status` enum({$quoted}) not null default 'pending'");
    }
};
