<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Profile Polish phase: one optional image per "Updates &
 * Achievements" post -- never a gallery, never multiple images. Nullable
 * string, the same storage-relative-path shape `cvs.file_path` already
 * uses (never a raw filesystem path, never client-controlled). Purely
 * additive -- every existing `organization_posts` row is untouched,
 * `image_path` starts `null` for all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_posts', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('organization_posts', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
