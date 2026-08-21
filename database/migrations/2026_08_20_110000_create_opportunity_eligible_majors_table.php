<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 8B-3.2: explicit, multi-major eligibility for an Opportunity.
     * A plain text list, not a join to a global "Major" catalog -- none
     * exists in this project, and this phase deliberately doesn't
     * introduce one (see docs/ARCHITECTURE.md). `major_name` preserves the
     * organization's own casing/wording exactly as typed, for display;
     * `normalized_major_name` (trim/lowercase/collapse-whitespace via
     * `App\Support\MajorNormalizer` -- the same minimal rule
     * `SkillNameNormalizer` already established for skills) is the only
     * column ever compared against a Student's `major` for eligibility.
     *
     * `unique(['opportunity_id', 'normalized_major_name'])` prevents
     * storing the same major twice for one Opportunity under different
     * casing/whitespace (e.g. "Computer Science" and "computer  science"
     * both normalize to one row) without needing application-level
     * deduplication to be perfectly relied upon.
     *
     * The pre-existing `opportunities.field_of_study` column is left
     * completely untouched by this migration -- it remains the legacy
     * single-major fallback for any Opportunity that has no explicit rows
     * here (see `OpportunityEligibilityService`).
     */
    public function up(): void
    {
        Schema::create('opportunity_eligible_majors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('opportunity_id')
                  ->constrained('opportunities')
                  ->cascadeOnDelete();

            $table->string('major_name');
            $table->string('normalized_major_name');

            $table->timestamps();

            // Explicit short name -- MySQL's default auto-generated name
            // for this column pair exceeds its 64-character identifier
            // limit.
            $table->unique(['opportunity_id', 'normalized_major_name'], 'opp_eligible_majors_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opportunity_eligible_majors');
    }
};
