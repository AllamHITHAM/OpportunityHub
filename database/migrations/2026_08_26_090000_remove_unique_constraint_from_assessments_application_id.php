<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A.3 — an Application can now accumulate real Assessment
 * *history* (e.g. a completed Quiz followed by a new Interview for the
 * same application), so `assessments.application_id` can no longer be
 * unique. Nothing about existing rows changes -- every application today
 * has at most one Assessment, so dropping the constraint is a pure
 * loosening with zero data impact. A plain (non-unique) index replaces it,
 * since every assessment-history query (`Application::assessments()`,
 * the active-assessment-invariant check in `AssessmentService`) still
 * filters/joins on this column.
 *
 * The "at most one *active* Assessment at a time" invariant this
 * constraint used to enforce is now an application-level guarantee,
 * enforced by row-locking the parent `Application` inside
 * `AssessmentService`'s creation transaction -- see that class's own doc
 * comment. This is a deliberate trade: a partial/conditional unique index
 * (unique only among non-final statuses) is not portably expressible
 * across this project's MySQL (dev) and SQLite (test) connections without
 * a generated-column workaround, and the row-lock approach reuses a
 * pattern already established in this codebase (`OfferService::respondToOffer()`).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            // MySQL/InnoDB refuses to drop `assessments_application_id_unique`
            // in the same statement it's dropped in, if it's the only index
            // still backing the `application_id` foreign key -- the plain
            // index must exist first, so the FK can fall back onto it the
            // instant the unique one goes away, never leaving the FK
            // momentarily unindexed.
            $table->index('application_id');
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropUnique(['application_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Only safe to roll back while every application still has at most one
     * Assessment -- restoring the unique constraint after real Assessment
     * history has been created (more than one Assessment for the same
     * application) will fail with a duplicate-key error, by design: a
     * rollback must never silently discard historical Assessment rows to
     * satisfy the constraint it's restoring.
     */
    public function down(): void
    {
        // Same FK-backing ordering constraint as up(), reversed: the unique
        // index must exist before the plain one backing the FK is dropped.
        Schema::table('assessments', function (Blueprint $table) {
            $table->unique('application_id');
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
        });
    }
};
