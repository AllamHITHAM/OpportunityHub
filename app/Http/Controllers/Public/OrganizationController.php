<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\OrganizationProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Organization Public Profile phase: the read-only, public-facing
 * counterpart of `Organization\OrganizationProfileController` -- reachable
 * unauthenticated, exactly like `Public\OpportunityController` (mirrors
 * its own "no auth middleware" registration in routes/api.php). A Student
 * reaches this by tapping an Organization's identity from an Opportunity
 * they're already viewing (both `Public\OpportunityController::show()`
 * and `::index()` already eager-load the full `organizationProfile`
 * relation, so its `id` is already on every Opportunity response).
 *
 * Every returned row is an explicit, deliberately narrow array -- never
 * the raw `OrganizationProfile` model -- so this can never accidentally
 * leak `user_id`/`approval_status` (internal workflow state) just because
 * a future change adds a field to the model. Not reachable for an
 * Organization that isn't `approved` -- the exact same gate
 * `Public\OpportunityController` already applies before showing any of
 * that Organization's Opportunities, so a pending/rejected Organization
 * can never be browsed publicly -- **except by that Organization's own
 * signed-in account** (`isOwnerViewing()` below): the Flutter Company
 * Profile screen deliberately reuses this exact endpoint for the owner's
 * own "view my profile" screen (never a second, richer self-view
 * endpoint), so a not-yet-approved Organization must still be able to see
 * its own profile/posts before Admin approval, exactly like
 * `GET /organization/profile` already lets it. `Auth::guard('sanctum')`
 * is read directly (no `auth:sanctum` middleware on this route) so an
 * anonymous request is never rejected -- it only ever *upgrades* an
 * already-authenticated, matching Organization past the approval gate,
 * never a new authorization surface for anyone else.
 */
class OrganizationController extends Controller
{
    public function show(OrganizationProfile $organizationProfile): JsonResponse
    {
        if ($organizationProfile->approval_status !== 'approved'
            && ! $this->isOwnerViewing($organizationProfile)) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
                'data' => null,
            ], 404);
        }

        $organizationProfile->loadMissing('locationRecord');

        return response()->json([
            'success' => true,
            'message' => 'Organization retrieved successfully',
            'data' => [
                'id' => $organizationProfile->id,
                'organization_name' => $organizationProfile->organization_name,
                'organization_type' => $organizationProfile->organization_type,
                'industry' => $organizationProfile->industry,
                'description' => $organizationProfile->description,
                'website' => $organizationProfile->website,
                'phone' => $organizationProfile->phone,
                'location' => $organizationProfile->location,
                'logo_url' => $organizationProfile->logo_url,
            ],
        ]);
    }

    /**
     * This Organization's own "Updates & Achievements" posts, newest
     * first -- text-only, never a social feed (no likes/comments/
     * followers/shares anywhere in this response).
     */
    public function posts(OrganizationProfile $organizationProfile): JsonResponse
    {
        if ($organizationProfile->approval_status !== 'approved'
            && ! $this->isOwnerViewing($organizationProfile)) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found',
                'data' => null,
            ], 404);
        }

        $posts = $organizationProfile->posts()
            ->latest()
            ->get(['id', 'organization_id', 'title', 'body', 'image_path', 'created_at', 'updated_at']);

        return response()->json([
            'success' => true,
            'message' => 'Organization posts retrieved successfully',
            'data' => $posts,
        ]);
    }

    /**
     * True only when the current request carries a valid Sanctum token
     * for the exact Organization account [$organizationProfile] belongs
     * to. Never trusts a route parameter or request body -- always
     * re-derives the viewer from the token itself.
     */
    private function isOwnerViewing(OrganizationProfile $organizationProfile): bool
    {
        $viewer = Auth::guard('sanctum')->user();

        return $viewer !== null
            && $viewer->role === 'organization'
            && $viewer->organizationProfile?->id === $organizationProfile->id;
    }
}
