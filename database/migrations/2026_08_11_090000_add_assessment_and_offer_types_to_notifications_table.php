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
     * Adds `assessment` and `offer` to `notifications.type` (Phase 7A-1) --
     * the two values `App\Services\NotificationService`'s convenience
     * methods need to precisely categorize Quiz-availability/completion and
     * Offer-lifecycle notifications, rather than overloading the existing
     * `interview`/`application` values for concerns those don't actually
     * describe. No row is rewritten here -- nothing creates a Notification
     * with either new value yet (that's Phase 7A-2's workflow-integration
     * work), so there is nothing to migrate forward.
     *
     * Same doctrine/dbal-free driver split as
     * `2026_08_10_134012_add_offer_sent_status_to_applications_table.php`'s
     * `setStatusEnum()`: MySQL/MariaDB get a raw `MODIFY`, SQLite uses
     * Laravel's native `Blueprint::change()` (a safe rebuild-and-copy under
     * the hood), since neither needs doctrine/dbal. `type` has no default
     * and is not nullable in the original migration -- this preserves both
     * exactly.
     */
    public function up(): void
    {
        $this->setTypeEnum([
            'system',
            'application',
            'interview',
            'assessment',
            'offer',
            'organization',
            'opportunity',
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * The pre-migration enum has neither `assessment` nor `offer`, so any
     * row currently holding either value is first moved to `system` -- the
     * generic fallback type (see `NotificationService`'s own doc comment on
     * why `system` is the only fallback type v1 events use) -- before the
     * column is shrunk back down. Defensive, not currently reachable in
     * practice (nothing writes either value before Phase 7A-2), but this
     * keeps rollback non-destructive instead of erroring or truncating even
     * if a row somehow already holds one, matching the exact convention
     * every other enum-widening migration in this project already follows.
     */
    public function down(): void
    {
        DB::table('notifications')
            ->whereIn('type', ['assessment', 'offer'])
            ->update(['type' => 'system']);

        $this->setTypeEnum([
            'system',
            'application',
            'interview',
            'organization',
            'opportunity',
        ]);
    }

    /**
     * @param  list<string>  $values
     */
    private function setTypeEnum(array $values): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('notifications', function (Blueprint $table) use ($values) {
                $table->enum('type', $values)->change();
            });

            return;
        }

        $quoted = implode(',', array_map(fn (string $value) => "'{$value}'", $values));

        DB::statement("alter table `notifications` modify `type` enum({$quoted}) not null");
    }
};
