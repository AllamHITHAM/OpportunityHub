<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 8B-3: an Organization-initiated invitation for a Student to
     * apply to one of its own Opportunities (Flow B). No `organization_id`
     * column -- ownership is always derived through
     * `opportunity.organization_id`, the exact same shape `applications`
     * already uses for `student_id`/`opportunity_id` (see
     * `Application::studentProfile()`/`opportunity()`).
     *
     * `unique(['opportunity_id', 'student_id'])` mirrors `applications`'
     * own `unique(['student_id', 'opportunity_id'])` constraint exactly --
     * it is deliberately NOT scoped to `status`, so a student can only
     * ever have one Invitation per Opportunity for its entire lifetime,
     * regardless of whether it's pending/accepted/declined. This is the
     * simplest non-duplicating rule that satisfies every conflict case in
     * one constraint: a second pending invite, a re-invite after
     * acceptance, and a re-invite after decline are all rejected the same
     * way (see docs/BUSINESS_RULES.md).
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('opportunity_id')
                  ->constrained('opportunities')
                  ->cascadeOnDelete();

            $table->foreignId('student_id')
                  ->constrained('student_profiles')
                  ->cascadeOnDelete();

            $table->enum('status', [
                'pending',
                'accepted',
                'declined',
            ])->default('pending');

            $table->text('message')->nullable();

            $table->timestamps();

            $table->unique(['opportunity_id', 'student_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
