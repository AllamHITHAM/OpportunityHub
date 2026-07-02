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
        Schema::create('applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                  ->constrained('student_profiles')
                  ->cascadeOnDelete();

            $table->foreignId('opportunity_id')
                  ->constrained('opportunities')
                  ->cascadeOnDelete();

            $table->foreignId('cv_id')
                  ->constrained('cvs')
                  ->restrictOnDelete();

            $table->enum('status', [
                'pending',
                'reviewed',
                'shortlisted',
                'interview_scheduled',
                'accepted',
                'rejected',
                'withdrawn',
            ])->default('pending');

            $table->decimal('match_score', 5, 2)->nullable();
            $table->text('cover_letter')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->unique(['student_id', 'opportunity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
