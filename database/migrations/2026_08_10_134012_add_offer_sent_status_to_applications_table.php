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
     * Adds the `offer_sent` value to `applications.status` -- the status
     * `App\Services\OfferService::sendOffer()` (Phase 6C-1, not yet
     * implemented) will write once an organization sends a final Offer for
     * an `in_assessment` application. No existing row is rewritten here.
     *
     * Same doctrine/dbal-free driver split as
     * 2026_08_08_161938_add_in_assessment_status_to_applications_table.php's
     * `setStatusEnum()`: MySQL/MariaDB get a raw `MODIFY`, SQLite uses
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
            'offer_sent',
            'interview_scheduled',
            'accepted',
            'rejected',
            'withdrawn',
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * The pre-migration enum has no `offer_sent` value, so any row
     * currently holding it is first moved back to `in_assessment` -- the
     * status every `offer_sent` application necessarily came from (see
     * `OfferService::sendOffer()`'s precondition), and the same safe
     * fallback `2026_08_08_161938_add_in_assessment_status_to_applications_table.php`
     * already uses for its own new value -- before the column is shrunk
     * back down. This keeps rollback non-destructive instead of erroring or
     * truncating, and never touches rows in any other status.
     */
    public function down(): void
    {
        DB::table('applications')->where('status', 'offer_sent')->update(['status' => 'in_assessment']);

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
