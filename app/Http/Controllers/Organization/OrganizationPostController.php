<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationPostRequest;
use App\Http\Requests\Organization\UpdateOrganizationPostRequest;
use App\Models\OrganizationPost;
use App\Services\ImageStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization Public Profile phase: the Organization's own "Updates &
 * Achievements" post management -- create/update/delete only, since
 * reading (both the owner's own posts and any other Organization's) is
 * already covered by `Public\OrganizationController::posts()`, which
 * works identically for the owner viewing their own profile as it does
 * for a Student. Never a second, duplicate read implementation here.
 *
 * Ownership is enforced on every mutation: `update()`/`destroy()` 404
 * (never `403`, the same "don't confirm another organization's resource
 * exists" convention every other owned-resource endpoint in this API
 * already uses) for a post that exists but doesn't belong to the
 * authenticated Organization.
 */
class OrganizationPostController extends Controller
{
    public function __construct(private readonly ImageStorageService $images)
    {
    }

    public function store(StoreOrganizationPostRequest $request): JsonResponse
    {
        $profile = $request->user()->organizationProfile;

        // Company Profile Polish phase: the post is created first
        // (without the image path), then updated with the stored image
        // path -- so a real, real `organization_id`/id exists before the
        // image's own directory is chosen, matching the per-owner
        // directory shape `organization-logos/{id}` already uses for the
        // logo.
        $post = $profile->posts()->create([
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
        ]);

        if ($request->hasFile('image')) {
            $storedPath = $this->images->store($request->file('image'), "organization-posts/{$profile->id}");
            $post->update(['image_path' => $storedPath]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Post published successfully',
            'data' => $post->fresh(),
        ], 201);
    }

    public function update(
        OrganizationPost $organizationPost,
        UpdateOrganizationPostRequest $request,
    ): JsonResponse {
        if (! $this->belongsToUser($organizationPost, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
                'data' => null,
            ], 404);
        }

        $update = [
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
        ];

        $oldImagePath = $organizationPost->image_path;
        $newImagePath = $oldImagePath;

        if ($request->hasFile('image')) {
            // Replace -- store the new file first, only delete the old
            // one after the row itself is safely updated below, so a
            // mid-request failure can never leave the post pointing at a
            // file that no longer exists.
            $newImagePath = $this->images->store(
                $request->file('image'),
                "organization-posts/{$organizationPost->organization_id}",
            );
        } elseif ($request->boolean('remove_image')) {
            // Remove -- no new file, just clear it.
            $newImagePath = null;
        }
        // Neither present: $newImagePath stays $oldImagePath -- "keep
        // existing" is the default, never touched unless the client
        // explicitly asked for a replace or a removal.

        $update['image_path'] = $newImagePath;
        $organizationPost->update($update);

        if ($newImagePath !== $oldImagePath) {
            $this->images->delete($oldImagePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Post updated successfully',
            'data' => $organizationPost->fresh(),
        ]);
    }

    public function destroy(OrganizationPost $organizationPost, Request $request): JsonResponse
    {
        if (! $this->belongsToUser($organizationPost, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Post not found',
                'data' => null,
            ], 404);
        }

        $imagePath = $organizationPost->image_path;
        $organizationPost->delete();
        $this->images->delete($imagePath);

        return response()->json([
            'success' => true,
            'message' => 'Post deleted successfully',
            'data' => null,
        ]);
    }

    private function belongsToUser(OrganizationPost $post, Request $request): bool
    {
        return $post->organization_id === $request->user()->organizationProfile?->id;
    }
}
