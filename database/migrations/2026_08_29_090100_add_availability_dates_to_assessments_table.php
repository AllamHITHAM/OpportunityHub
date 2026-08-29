<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A.4B addendum — the candidate-SPECIFIC, FROZEN availability
 * window. Deliberately lives on `Assessment` (the per-candidate entity),
 * never on the shared `Quiz` row -- if the Organization edits the shared
 * policy later, candidates already advanced must not unexpectedly receive
 * different dates. Computed exactly once, at
 * `AssessmentService::advanceToSharedQuiz()` time, from the shared Quiz's
 * policy columns (see the sibling migration) plus the assignment moment
 * (`now()`) -- never recalculated on read.
 *
 * Both nullable, and left `null` forever for every legacy/ad-hoc Assessment
 * (interview or a private Quiz) -- `null` means "no gating", which is
 * exactly the pre-addendum behavior (immediately available, no deadline),
 * preserved automatically with zero special-casing. Only
 * `advanceToSharedQuiz()` ever writes these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->timestamp('available_at')->nullable()->after('quiz_id');
            $table->timestamp('due_at')->nullable()->after('available_at');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['available_at', 'due_at']);
        });
    }
};
