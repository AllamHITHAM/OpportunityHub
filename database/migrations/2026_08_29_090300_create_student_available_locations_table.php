<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_available_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_profile_id')
                  ->constrained('student_profiles')
                  ->cascadeOnDelete();

            $table->foreignId('location_id')
                  ->constrained('locations')
                  ->cascadeOnDelete();

            $table->timestamps();

            // MySQL's 64-char identifier limit rejects the Laravel-default
            // auto-generated name for this column pair -- the same issue
            // already documented for `opp_eligible_majors_unique` -- so an
            // explicit short name is required, not stylistic.
            $table->unique(
                ['student_profile_id', 'location_id'],
                'student_available_locations_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_available_locations');
    }
};
