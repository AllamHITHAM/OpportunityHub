<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The canonical Location Catalog (Phase O8.2) -- a fixed, admin-curated
 * set of real places, each with one stable ID. Every Opportunity/Student
 * reference to a location goes through this table's `id`, never a raw
 * display string, so "Nablus", "Nablus, Palestine", and "نابلس" can never
 * silently become three unrelated logical locations again -- the ambiguity
 * is removed by construction (there is no free-text location input left
 * anywhere in this app), not by fuzzy-matching text after the fact.
 */
class Location extends Model
{
    protected $fillable = [
        'canonical_name',
    ];

    /**
     * The raw `aliases` relation is never serialized directly -- only
     * `alias_names` below (plain alias text, never `normalized_alias`,
     * which exists purely for internal comparison) is a safe client-facing
     * view of it. Mirrors `Opportunity::$hidden = ['eligibleMajorRecords']`
     * exactly, including the same "Eloquent's relation-hiding checks the
     * camelCase relation name, not the snake_case attribute it's derived
     * from" convention.
     */
    protected $hidden = ['aliases'];

    /**
     * `alias_names` (Recommendation Accuracy Patch) is always appended --
     * the same "derived, always-safe" convention `Opportunity.eligible_majors`
     * already established -- so a client (Flutter's typed location search)
     * never needs to remember to eager-load `aliases` just to see every
     * name this Location can be typed as. Plain alias text only, never
     * `normalized_alias`, which exists purely for internal comparison.
     */
    protected $appends = ['alias_names'];

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function studentProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            StudentProfile::class,
            'student_available_locations',
        );
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(LocationAlias::class);
    }

    /**
     * @return list<string>
     */
    public function getAliasNamesAttribute(): array
    {
        return $this->aliases->pluck('alias')->values()->all();
    }
}
