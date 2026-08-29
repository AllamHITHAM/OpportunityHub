<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Public Profile phase: a canonical, ID-based Location
 * reference for an Organization's own profile -- mirrors
 * `2026_08_29_090400_add_location_id_to_opportunities_table.php` and
 * `2026_08_29_090500_add_current_location_id_to_student_profiles_table.php`
 * exactly (same nullable + `constrained('locations')->nullOnDelete()`
 * shape), reusing the existing Location Catalog rather than a free-text
 * field. Purely additive -- every existing `organization_profiles` row is
 * left untouched, `location_id` starts `null` and is only ever set going
 * forward via `Organization\OrganizationProfileController::update()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            $table->foreignId('location_id')
                  ->nullable()
                  ->after('industry')
                  ->constrained('locations')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
