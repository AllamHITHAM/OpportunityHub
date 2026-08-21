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
        // Phase 8A-6.1: the smallest record needed to prove a `cv_ai`
        // StudentSkill claim is real rather than client-spoofed. One row
        // per (cv, skill) pair the AI extraction flow has actually
        // resolved to a real catalog Skill for that CV -- never raw
        // parsed_text, never the AI prompt/response. `student_id` is
        // denormalized from the CV's own owner at the moment the evidence
        // is recorded, so verifying a later `cv_ai` claim is a single
        // indexed lookup with no join required.
        Schema::create('cv_skill_evidence', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                  ->constrained('student_profiles')
                  ->cascadeOnDelete();

            $table->foreignId('cv_id')
                  ->constrained('cvs')
                  ->cascadeOnDelete();

            $table->foreignId('skill_id')
                  ->constrained('skills')
                  ->cascadeOnDelete();

            $table->timestamps();

            // One evidence row per (cv, skill) -- repeated Analyze CV
            // calls for the same CV must never create duplicates; the
            // service upserts via firstOrCreate on this pair.
            $table->unique(['cv_id', 'skill_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_skill_evidence');
    }
};
