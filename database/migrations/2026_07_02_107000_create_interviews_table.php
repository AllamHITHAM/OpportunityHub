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
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('application_id')
                  ->unique()
                  ->constrained('applications')
                  ->cascadeOnDelete();

            $table->enum('interview_type', ['onsite', 'online', 'phone']);
            $table->enum('decision', [
                         'pending',
                         'passed',
                         'failed',
                         'waiting',
             ])->default('pending');
            $table->timestamp('scheduled_at');
            $table->unsignedInteger('duration_minutes')->default(60);

            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('meeting_link')->nullable();
            $table->string('location')->nullable();
            $table->string('interviewer_name')->nullable();
            $table->string('interviewer_email')->nullable();

            $table->text('notes')->nullable();
            $table->text('company_feedback')->nullable();

            $table->enum('status', [
                'scheduled',
                'completed',
                'cancelled',
                'rescheduled',
                'no_show',
            ])->default('scheduled');

            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
