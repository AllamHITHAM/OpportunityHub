<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student Location Profile Patch: a Student's own current/home location --
 * a single canonical Location Catalog reference, deliberately distinct
 * from `student_available_locations` (the multiple work locations they'd
 * be willing to work in). Nullable and purely additive: an existing
 * Student profile has no current location, and nothing here requires one.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('current_location_id')
                  ->nullable()
                  ->after('bio')
                  ->constrained('locations')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_location_id');
        });
    }
};
