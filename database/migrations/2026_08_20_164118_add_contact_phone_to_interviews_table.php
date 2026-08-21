<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the missing conditional-detail column for `interview_type =
     * phone` (Phase Final-QA-1) — `meeting_link` (online) and `location`
     * (onsite) already exist on this table and are reused as-is. Nullable
     * at the DB level, exactly like those two: only one of the three is
     * ever populated for a given interview, and every pre-existing row
     * must remain valid with no value here. Required-when-`phone` is
     * enforced at the validation layer only (see
     * `App\Http\Requests\Organization\Concerns\InteractsWithInterviewRules`).
     */
    public function up(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->string('contact_phone')->nullable()->after('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('interviews', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }
};
