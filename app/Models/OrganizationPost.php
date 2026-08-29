<?php

namespace App\Models;

use App\Services\ImageStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organization Public Profile phase: one "Updates & Achievements" post --
 * a simple, professional text update, never a social-media post (no
 * likes/comments/followers/shares columns exist here or anywhere in this
 * schema). As of the Company Profile Polish phase, a post may optionally
 * carry ONE image (never a gallery) -- see [image_url].
 */
class OrganizationPost extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'body',
        'image_path',
    ];

    /**
     * `image_path` is a managed storage-relative path -- never exposed
     * directly (mirrors `OrganizationProfile::logo`/`logo_url`).
     */
    protected $hidden = ['image_path'];

    protected $appends = ['image_url'];

    public function organizationProfile(): BelongsTo
    {
        return $this->belongsTo(OrganizationProfile::class, 'organization_id');
    }

    /**
     * The post image's full, publicly-reachable URL -- `null` when this
     * post has no image. Never a raw storage path.
     */
    public function getImageUrlAttribute(): ?string
    {
        return app(ImageStorageService::class)->url($this->image_path);
    }
}
