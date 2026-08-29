<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Candidate Profile Enrichment + Messaging MVP: the shared
 * Conversation resource between one Organization and one Student, always
 * anchored to a real recruiting context (`opportunity_id`) -- every valid
 * "who can start a conversation" context this phase supports
 * (Recommended Candidates eligibility, Invitation, Application) is itself
 * Opportunity-scoped, so there is no route to create an opportunity-less
 * conversation in this MVP; the column is nullable purely so a later
 * Opportunity deletion (`nullOnDelete()`) preserves the real message
 * history rather than cascading it away.
 *
 * `unique(organization_id, student_id, opportunity_id)` is the
 * deterministic reuse key -- a repeated "Message Candidate" tap for the
 * same Organization/Student/Opportunity triple always resolves to the
 * same row (`firstOrCreate`), never a duplicate conversation.
 * `application_id` is optional extra context (set once a real
 * Application exists for this pair), never itself part of the identity
 * key -- a Student may be messaged before ever applying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                  ->constrained('organization_profiles')
                  ->cascadeOnDelete();

            $table->foreignId('student_id')
                  ->constrained('student_profiles')
                  ->cascadeOnDelete();

            $table->foreignId('opportunity_id')
                  ->nullable()
                  ->constrained('opportunities')
                  ->nullOnDelete();

            $table->foreignId('application_id')
                  ->nullable()
                  ->constrained('applications')
                  ->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'student_id', 'opportunity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
