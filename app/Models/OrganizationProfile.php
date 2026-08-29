<?php

namespace App\Models;

use App\Services\ImageStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationProfile extends Model
{
    protected $fillable = [
        'user_id',
        'organization_name',
        'organization_type',
        'industry',
        'description',
        'website',
        'logo',
        'phone',
        'location_id',
    ];

    /**
     * `logo` (Company Profile Polish phase) is a managed storage-relative
     * path, not something a client should ever read directly (or
     * infer a filesystem layout from) -- [logo_url] below is the only
     * safe, public view of it, mirroring how [location] is the only safe
     * view of `locationRecord`.
     */
    protected $hidden = ['logo'];

    /**
     * Always available on any serialization of this model (self-view,
     * Admin view, and the new Public Profile view) -- the same
     * "derived, always-safe view of a relation" pattern
     * `StudentProfile::education_verification_status` already
     * establishes. Never the raw `locationRecord` relation, and never a
     * free-text location string.
     */
    protected $appends = ['location', 'logo_url'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'organization_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'organization_id');
    }

    /**
     * Organization Public Profile phase: the Organization's own canonical
     * Location Catalog reference -- optional, mirrors
     * `Opportunity::locationRecord()`/`StudentProfile::currentLocation()`.
     */
    public function locationRecord(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * `{id, canonical_name}`, or `null` when unset -- never a raw ID,
     * never a free-text string. Mirrors
     * `OpportunityRecommendationController`'s own hand-built `location`
     * shape for the same Location Catalog data.
     */
    public function getLocationAttribute(): ?array
    {
        return $this->locationRecord
            ? ['id' => $this->locationRecord->id, 'canonical_name' => $this->locationRecord->canonical_name]
            : null;
    }

    /**
     * The Company Logo's full, publicly-reachable URL -- `null` until an
     * Organization uploads one (see `OrganizationProfileController::uploadLogo()`),
     * in which case Flutter's existing initials-avatar fallback keeps
     * rendering exactly as it already does for every other identity
     * surface in this app. Never a raw storage path, never a fabricated
     * placeholder image.
     */
    public function getLogoUrlAttribute(): ?string
    {
        return app(ImageStorageService::class)->url($this->logo);
    }

    /**
     * Organization Public Profile phase: "Updates & Achievements" --
     * simple, professional text posts. Newest-first is applied by the
     * controller (`->latest()`), not baked into this relation, matching
     * every other ordered `HasMany` in this app (e.g.
     * `StudentProfile::applications()`).
     */
    public function posts(): HasMany
    {
        return $this->hasMany(OrganizationPost::class, 'organization_id');
    }
}
