<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging MVP: adds `message` to `notifications.type` -- the value
 * `NotificationService::notifyNewMessage()` needs to categorize a new
 * chat message notification distinctly from the pre-existing
 * `application`/`interview`/`assessment`/`offer`/`organization`/
 * `opportunity` events. Same enum-widening pattern as
 * `2026_08_11_090000_add_assessment_and_offer_types_to_notifications_table`.
 */
return new class extends Migration
{
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
            'message',
        ]);
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('type', 'message')
            ->update(['type' => 'system']);

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
