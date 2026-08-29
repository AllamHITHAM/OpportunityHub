<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10A.4B — the Organization must now declare, up front, what kind of
 * recruitment evaluation an Opportunity actually uses, backend-authoritative
 * (never inferred client-side). Deliberately **3** values, not the 4
 * conceptually listed in the product spec ("Quiz Only" vs "Quiz -> Interview/
 * Offer decision") -- Phase 10A.4A already made a real Organization decision
 * mandatory before *any* Quiz result can release to a Student
 * (`QuizResultReleaseService::isReadyToRelease()`), so a "Quiz Only, no
 * decision needed" mode would be a structural regression to that phase, not
 * a real product option. See docs/BUSINESS_RULES.md for the full rationale.
 *
 * `not null default 'none'` so every existing Opportunity backfills safely
 * to the one value that changes nothing about its current behavior -- the
 * pre-10A.4B ad-hoc "Choose Assessment" flow (Interview or a private Quiz,
 * freely chosen per candidate) remains fully unrestricted for `none`. Only
 * `quiz` unlocks the new shared-Quiz-template flow this phase adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->enum('recruitment_process', ['none', 'interview', 'quiz'])
                ->default('none')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropColumn('recruitment_process');
        });
    }
};
