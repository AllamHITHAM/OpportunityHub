<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The organization's final Offer for an `in_assessment` Application (Phase
 * 6C-1) -- v1 has no draft/expiry/cancellation, so an Offer's entire
 * lifecycle is: created `sent` by `OfferService::sendOffer()`, then
 * terminates exactly once via `acceptOffer()`/`declineOffer()`. An
 * application can have **at most one** Offer, enforced by the
 * `offers.application_id` unique constraint -- the same one-per-application
 * pattern `Assessment` already uses.
 *
 * `$fillable` intentionally includes `status`/`sent_at`/`responded_at`
 * alongside the organization-authored terms, matching this codebase's own
 * `Assessment`/`QuizAttempt` convention: those models list every column a
 * service ever mass-assigns, not just what an HTTP client may directly
 * supply. The actual write boundary is enforced one layer up -- only
 * `OfferService` ever sets `status`/`sent_at`/`responded_at`, and
 * `SendOfferRequest`'s validated data (passed through
 * `Organization\OfferController`) never contains those keys at all.
 */
class Offer extends Model
{
    protected $fillable = [
        'application_id',
        'title',
        'salary_amount',
        'salary_currency',
        'salary_period',
        'start_date',
        'message',
        'status',
        'sent_at',
        'responded_at',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    protected function casts(): array
    {
        return [
            'salary_amount' => 'decimal:2',
            'start_date' => 'date',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}
