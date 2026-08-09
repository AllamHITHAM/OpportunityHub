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
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assessment_id')
                  ->unique()
                  ->constrained('assessments')
                  ->cascadeOnDelete();

            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('time_limit_minutes')->nullable();

            // 0..100 is enforced by request validation (StoreAssessmentRequest);
            // unsignedTinyInteger comfortably covers that range at the schema
            // level, mirroring interviews.rating's use of the same type.
            $table->unsignedTinyInteger('passing_score');

            // Deliberately does NOT hold score/attempt/student-answer data --
            // that belongs to a future student-attempt table, not here.
            $table->enum('status', ['draft', 'published'])->default('draft');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
