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
        Schema::create('education_verifications', function (Blueprint $table) {
            $table->id();

            // One-to-one: a single active/latest verification per student
            // is sufficient for v1 (Phase 8B-1) -- a rejected submission is
            // resubmitted by updating this same row, not by creating a new
            // one. `student_id` (not `student_profile_id`) matches the
            // existing FK-naming convention already used by `cvs`,
            // `student_skills`, and `cv_skill_evidence`, even though it
            // references `student_profiles.id`.
            $table->foreignId('student_id')
                ->unique()
                ->constrained('student_profiles')
                ->cascadeOnDelete();

            $table->string('institution_name');
            $table->string('degree_or_program');

            // Never exposed in any API response -- see
            // EducationVerification::$hidden. A document is only ever
            // reached through the dedicated, ownership-checked streaming
            // endpoints (student's own, or Admin's).
            $table->string('document_path');

            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('education_verifications');
    }
};
