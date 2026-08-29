<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An alternate spelling/name/language form that resolves to exactly one
 * canonical [Location] (Recommendation Accuracy Patch) -- e.g. "Nablus
 * City", "Nablus, Palestine", or "نابلس" all resolving to the single
 * canonical "Nablus" [Location] row. `normalized_alias` (not `alias`) is
 * what lookups actually compare against -- see `LocationNormalizer` and
 * `LocationCatalogService`.
 */
class LocationAlias extends Model
{
    protected $fillable = [
        'location_id',
        'alias',
        'normalized_alias',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
