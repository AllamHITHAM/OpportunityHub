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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('quiz_id')
                  ->constrained('quizzes')
                  ->cascadeOnDelete();

            $table->text('prompt');
            $table->enum('type', ['multiple_choice', 'true_false']);

            // Null for true_false (its two choices are fixed and never
            // stored per-row -- see docs/BUSINESS_RULES.md); a JSON array of
            // option strings for multiple_choice.
            $table->json('options')->nullable();

            // ORGANIZATION-INTERNAL. Never expose this to a future Student
            // Quiz API response -- see Question::ORGANIZATION_ONLY_FIELDS
            // and docs/BUSINESS_RULES.md.
            $table->text('correct_answer');

            $table->unsignedInteger('points')->default(1);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
