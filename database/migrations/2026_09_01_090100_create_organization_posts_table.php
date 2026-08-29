<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization Public Profile phase: "Updates & Achievements" -- simple,
 * professional, text-only organization posts (never a social-media
 * feed -- no likes/comments/followers/shares anywhere in this schema).
 *
 * `title` is nullable (a post may just be a short update with no
 * headline); `body` is required. There is deliberately no `image_path`
 * column here -- audited first, and no *working* public/served-file
 * upload pipeline exists anywhere in this app yet (the `public` disk is
 * configured in `config/filesystems.php` but has never actually been
 * used, and `storage:link` was never run); the only proven upload
 * pattern in this app (CV/education-verification documents) is
 * *private*, authenticated-download storage, not a public-image one.
 * Per this phase's own instruction, that real gap is reported rather
 * than rushed into a schema column with nothing behind it -- a later
 * phase can add `image_path` via its own forward migration once a real
 * public upload pipeline exists.
 *
 * `organization_id` cascades on delete -- an Organization's own posts
 * are its own content, the same "owning parent" cascade
 * `opportunities.organization_id` already uses (contrast with the
 * Messaging MVP's `conversations.opportunity_id`, which deliberately does
 * NOT cascade because it isn't the owning parent there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')
                  ->constrained('organization_profiles')
                  ->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_posts');
    }
};
