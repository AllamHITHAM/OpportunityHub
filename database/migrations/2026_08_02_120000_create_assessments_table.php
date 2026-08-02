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
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')
                  ->unique()
                  ->constrained('applications')
                  ->cascadeOnDelete();

            $table->enum('type', ['interview', 'quiz']);

            $table->enum('status', [
                'pending',
                'scheduled',
                'in_progress',
                'completed',
                'declined',
                'cancelled',
            ])->default('pending');

            // No decision recorded yet is represented as `null`, not the
            // literal string `pending` -- see docs/BUSINESS_RULES.md for the
            // documented convention. `pending` remains a valid schema value
            // for future assessment types that may want to write it
            // explicitly.
            $table->enum('result', [
                'pending',
                'passed',
                'failed',
                'waiting',
            ])->nullable();

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
