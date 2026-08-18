<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8A-5: deterministic server-side PDF text extraction. Nullable
     * so every existing row remains valid without a backfill -- a CV
     * created before this phase (or one whose PDF genuinely has no
     * extractable text) simply has `parsed_text = null`.
     */
    public function up(): void
    {
        Schema::table('cvs', function (Blueprint $table) {
            $table->longText('parsed_text')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('cvs', function (Blueprint $table) {
            $table->dropColumn('parsed_text');
        });
    }
};
