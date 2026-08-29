<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Messaging MVP -- one text message inside a `Conversation`.
 * `sender_user_id` is always the authenticated session's own `users.id`
 * (never trusted from the request body -- see
 * `ConversationController::storeMessage()`). `read_at` is `null` until
 * the recipient (never the sender) opens the conversation.
 */
class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
