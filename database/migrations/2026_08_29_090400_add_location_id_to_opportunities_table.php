<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Location Catalog addendum (Phase O8.2): adds a canonical, ID-based
 * location reference alongside the pre-existing free-text `location`
 * column -- a purely additive, forward migration. `location` is never
 * touched or backfilled for any existing row, so no historical Opportunity
 * is rewritten; `location_id` is nullable and only ever populated going
 * forward, by `Organization\OpportunityController` mirroring the chosen
 * Location's `canonical_name` into `location` at the same time, so every
 * existing consumer of the plain-text `location` field keeps working
 * unchanged for both old and new Opportunities.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->foreignId('location_id')
                  ->nullable()
                  ->after('location')
                  ->constrained('locations')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
