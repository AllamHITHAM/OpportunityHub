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
        Schema::table('student_skills', function (Blueprint $table) {
            // Phase 8A-6.1: evidence of how this skill entered the
            // student's profile -- `manual` (self-declared, the existing
            // Add Skill flow) or `cv_ai` (accepted from an AI CV
            // suggestion, backend-verified against CvSkillEvidence before
            // it can ever be stored). Defaulting to `manual` means every
            // pre-existing row is correctly classified with no backfill
            // needed -- they were all created through the manual flow,
            // since `cv_ai` did not exist before this phase.
            $table->enum('source', ['manual', 'cv_ai'])->default('manual')->after('years_of_experience');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_skills', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
