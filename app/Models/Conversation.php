<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Messaging MVP -- the shared conversation resource between one
 * `OrganizationProfile` and one `StudentProfile`, always started by the
 * Organization and always anchored to a real Opportunity recruiting
 * context (see `MessagingService::startOrReuseConversation()` for the
 * exact authorization rule and `ConversationController` for how both
 * roles reach the same resource). `unique(organization_id, student_id,
 * opportunity_id)` at the database level is the deterministic reuse key.
 */
class Conversation extends Model
{
    protected $fillable = [
        'organization_id',
        'student_id',
        'opportunity_id',
        'application_id',
    ];

    public function organizationProfile(): BelongsTo
    {
        return $this->belongsTo(OrganizationProfile::class, 'organization_id');
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The single most recent message, for conversation-list previews --
     * `hasOne(...)->latestOfMany()`, eager-loadable without an N+1 across
     * a whole conversation list (`ConversationController::index()`).
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
