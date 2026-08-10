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
        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            // One Offer per Application, enforced at the database level --
            // the same `->unique()` pattern `assessments.application_id`
            // already uses, and for the same reason: the pre-check inside
            // `OfferService::sendOffer()` handles the common case, this
            // constraint is the final authority against a genuine
            // concurrent double-send.
            $table->foreignId('application_id')
                  ->unique()
                  ->constrained('applications')
                  ->cascadeOnDelete();

            $table->string('title', 255)->nullable();

            // Compensation is optional but internally consistent --
            // `SendOfferRequest` requires `salary_currency`/`salary_period`
            // whenever `salary_amount` is provided, and rejects either
            // being supplied alone. Nothing at the schema level enforces
            // that consistency; it's a request-validation concern, not a
            // data-integrity one (matching how `Quiz`/`Question` validation
            // is likewise request-only).
            $table->decimal('salary_amount', 10, 2)->nullable();
            $table->string('salary_currency', 10)->nullable();
            $table->enum('salary_period', ['hourly', 'monthly', 'yearly'])->nullable();

            $table->date('start_date')->nullable();
            $table->text('message')->nullable();

            // No `draft`, `expired`, or `cancelled` in v1 -- see
            // docs/BUSINESS_RULES.md. An Offer is created and sent in one
            // organization action (no multi-step authoring to protect
            // against, unlike Quiz), and neither automatic expiry nor
            // organization-side rescinding is in scope for Phase 6C-1.
            $table->enum('status', ['sent', 'accepted', 'declined'])->default('sent');

            // Always set at creation by `OfferService::sendOffer()` (never
            // left to a default database timestamp) -- kept as a plain,
            // required-in-practice column rather than `useCurrent()`, the
            // same way `assessments.completed_at` is application-set rather
            // than database-defaulted.
            $table->timestamp('sent_at');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
