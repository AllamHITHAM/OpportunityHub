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
        Schema::create('location_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            // The normalized (trimmed, collapsed-whitespace, lowercased) form
            // of `alias` -- what `LocationCatalogService` actually compares
            // against on lookup, never the raw display string. Unique so the
            // same alias text can never resolve to two different canonical
            // Locations, and so a duplicate alias can never be attached
            // twice (including a second time under different casing/spacing).
            $table->string('normalized_alias')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_aliases');
    }
};
