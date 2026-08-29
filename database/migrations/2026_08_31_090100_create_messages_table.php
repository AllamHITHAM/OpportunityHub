<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging MVP: text-only messages inside one Conversation.
 * `sender_user_id` always comes from the authenticated session server-side
 * (`ConversationController::storeMessage()`), never trusted from the
 * request body. `read_at` is `null` until the *recipient* opens the
 * conversation (never the sender's own messages) -- see
 * `ConversationController::show()`.
 *
 * Deliberately no `attachment_path`/`type`/`is_edited`/`deleted_at`
 * column -- this phase is explicitly text-only, no edit/delete, no
 * soft-delete moderation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                  ->constrained('conversations')
                  ->cascadeOnDelete();

            $table->foreignId('sender_user_id')
                  ->constrained('users')
                  ->restrictOnDelete();

            $table->text('body');
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
