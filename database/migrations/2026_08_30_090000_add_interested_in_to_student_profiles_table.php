<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidate Opportunity Preferences patch: the Student's own multi-select
 * "Interested In" preference -- a JSON array of canonical Opportunity Type
 * values (`App\Support\OpportunityType::ALL`), never free text and never a
 * second vocabulary/table. Nullable and purely additive: an existing
 * Student profile has no preference recorded and remains fully readable;
 * the "at least one selection required" rule is enforced only at the
 * validation layer going forward (Store/Update requests), never backfilled
 * here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->json('interested_in')->nullable()->after('current_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('interested_in');
        });
    }
};
