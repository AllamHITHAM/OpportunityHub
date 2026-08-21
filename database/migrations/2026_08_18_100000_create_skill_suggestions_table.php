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
        Schema::create('skill_suggestions', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('normalized_name');

            $table->enum('source', ['ai_cv', 'student', 'organization']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('suggested_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->foreignId('approved_skill_id')
                  ->nullable()
                  ->constrained('skills')
                  ->nullOnDelete();

            $table->timestamps();

            // Not a unique index on `normalized_name` alone -- a name that
            // was already rejected once may legitimately be re-suggested
            // later and go back to `pending`. Reuse of an existing pending
            // suggestion for the same normalized name is enforced in code
            // (AiSkillExtractionService::mapToCatalog, via firstOrCreate
            // scoped to status=pending), not at the schema level.
            $table->index('normalized_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skill_suggestions');
    }
};
