<?php

namespace Tests\Feature\Organization;

use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Organization Public Profile phase: "Updates & Achievements" post
 * create/update/delete, ownership enforcement, and validation. Reading
 * (both the owner's own posts and any other Organization's) is covered by
 * `tests/Feature/Public/OrganizationPublicProfileTest.php`, since
 * `GET /organizations/{organizationProfile}/posts` is the one real read
 * path for both contexts.
 */
class OrganizationPostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The smallest possible valid PNG (1x1, transparent) -- see
     * `OrganizationProfileTest`'s identical constant for why (`gd` is
     * unavailable in this test environment).
     */
    private const TINY_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function tinyPngFile(string $name = 'image.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::TINY_PNG_BASE64));
    }

    public function test_organization_can_create_a_post(): void
    {
        $org = $this->approvedOrganization();

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/posts', [
            'title' => 'Summer Internship Program',
            'body' => 'Applications for our Summer Internship Program are now open.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Summer Internship Program')
            ->assertJsonPath('data.body', 'Applications for our Summer Internship Program are now open.');

        $this->assertDatabaseHas('organization_posts', [
            'organization_id' => $org->profile->id,
            'title' => 'Summer Internship Program',
        ]);
    }

    public function test_a_post_can_be_created_without_a_title(): void
    {
        $org = $this->approvedOrganization();

        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/posts', [
            'body' => 'Just a quick update.',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.title', null);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $org = $this->approvedOrganization();

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/posts', ['body' => ''])->assertStatus(422);
    }

    public function test_a_whitespace_only_body_is_rejected(): void
    {
        $org = $this->approvedOrganization();

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/posts', ['body' => '   '])->assertStatus(422);
    }

    public function test_a_pending_organization_cannot_create_a_post(): void
    {
        $org = $this->organizationWithProfile('pending');

        Sanctum::actingAs($org->user);

        $this->postJson('/api/organization/posts', ['body' => 'Hello'])->assertStatus(403);
    }

    public function test_a_student_cannot_create_a_post(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);

        $this->postJson('/api/organization/posts', ['body' => 'Hello'])->assertStatus(403);
    }

    public function test_an_unauthenticated_request_cannot_create_a_post(): void
    {
        $this->postJson('/api/organization/posts', ['body' => 'Hello'])->assertStatus(401);
    }

    public function test_organization_can_update_its_own_post(): void
    {
        $org = $this->approvedOrganization();
        $post = $org->profile->posts()->create(['title' => 'Old', 'body' => 'Old body']);

        Sanctum::actingAs($org->user);

        $response = $this->putJson("/api/organization/posts/{$post->id}", [
            'title' => 'New',
            'body' => 'New body',
        ]);

        $response->assertStatus(200)->assertJsonPath('data.title', 'New');
        $this->assertDatabaseHas('organization_posts', ['id' => $post->id, 'title' => 'New', 'body' => 'New body']);
    }

    public function test_organization_cannot_update_another_organizations_post(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $post = $orgA->profile->posts()->create(['title' => 'A post', 'body' => 'A body']);

        Sanctum::actingAs($orgB->user);

        $this->putJson("/api/organization/posts/{$post->id}", [
            'title' => 'Hijacked',
            'body' => 'Hijacked body',
        ])->assertStatus(404);

        $this->assertDatabaseHas('organization_posts', ['id' => $post->id, 'title' => 'A post']);
    }

    public function test_organization_can_delete_its_own_post(): void
    {
        $org = $this->approvedOrganization();
        $post = $org->profile->posts()->create(['title' => 'Doomed', 'body' => 'Doomed body']);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/posts/{$post->id}")->assertStatus(200);

        $this->assertDatabaseMissing('organization_posts', ['id' => $post->id]);
    }

    public function test_organization_cannot_delete_another_organizations_post(): void
    {
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $post = $orgA->profile->posts()->create(['title' => 'A post', 'body' => 'A body']);

        Sanctum::actingAs($orgB->user);

        $this->deleteJson("/api/organization/posts/{$post->id}")->assertStatus(404);

        $this->assertDatabaseHas('organization_posts', ['id' => $post->id]);
    }

    public function test_a_student_cannot_update_or_delete_a_post(): void
    {
        $org = $this->approvedOrganization();
        $post = $org->profile->posts()->create(['title' => 'A post', 'body' => 'A body']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($student);

        $this->putJson("/api/organization/posts/{$post->id}", ['body' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/organization/posts/{$post->id}")->assertStatus(403);

        $this->assertDatabaseHas('organization_posts', ['id' => $post->id, 'body' => 'A body']);
    }

    public function test_an_unauthenticated_request_cannot_update_or_delete_a_post(): void
    {
        $org = $this->approvedOrganization();
        $post = $org->profile->posts()->create(['title' => 'A post', 'body' => 'A body']);

        $this->putJson("/api/organization/posts/{$post->id}", ['body' => 'Hijacked'])->assertStatus(401);
        $this->deleteJson("/api/organization/posts/{$post->id}")->assertStatus(401);
    }

    public function test_deleting_a_post_does_not_affect_the_organizations_profile_or_opportunities(): void
    {
        $org = $this->approvedOrganization();
        $post = $org->profile->posts()->create(['title' => 'A post', 'body' => 'A body']);
        $opportunity = $org->profile->opportunities()->create([
            'title' => 'Backend Developer',
            'description' => 'Great role.',
            'opportunity_type' => 'job',
            'employment_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'junior',
            'status' => 'open',
        ]);

        Sanctum::actingAs($org->user);

        $this->deleteJson("/api/organization/posts/{$post->id}")->assertStatus(200);

        $this->assertDatabaseHas('organization_profiles', ['id' => $org->profile->id]);
        $this->assertDatabaseHas('opportunities', ['id' => $opportunity->id]);
    }

    public function test_deleting_the_organizations_profile_deletes_its_posts(): void
    {
        $org = $this->approvedOrganization();
        $post = $org->profile->posts()->create(['title' => 'A post', 'body' => 'A body']);

        $org->profile->delete();

        $this->assertDatabaseMissing('organization_posts', ['id' => $post->id]);
    }

    public function test_a_text_only_post_still_works_with_no_image(): void
    {
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/posts', ['body' => 'Just text, no image.']);

        $response->assertStatus(201)->assertJsonPath('data.image_url', null);
    }

    public function test_a_post_can_be_created_with_a_valid_image(): void
    {
        Storage::fake('public');
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/posts', [
            'body' => 'An update with a real photo.',
            'image' => $this->tinyPngFile(),
        ]);

        $response->assertStatus(201);
        $imageUrl = $response->json('data.image_url');
        $this->assertNotNull($imageUrl);
        // Final Company Profile Manual-E2E Bug Fix: `/api/media/...`
        // (`MediaController`, routed through Laravel/CORS), never the raw
        // `/storage/...` symlink path -- see `MediaController`'s own doc
        // comment for why that never actually rendered in a real browser.
        $this->assertStringContainsString('/api/media/organization-posts/', $imageUrl);
        $this->assertStringNotContainsString('/storage/', $imageUrl);

        $post = \App\Models\OrganizationPost::find($response->json('data.id'));
        Storage::disk('public')->assertExists($post->image_path);

        // The URL the API actually returned really does resolve to the
        // real uploaded bytes -- not just a plausible-looking string.
        $mediaResponse = $this->get(parse_url($imageUrl, PHP_URL_PATH));
        $mediaResponse->assertStatus(200);
        $mediaResponse->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertEquals(
            base64_decode(self::TINY_PNG_BASE64),
            $mediaResponse->streamedContent(),
        );
    }

    public function test_an_invalid_image_file_is_rejected(): void
    {
        Storage::fake('public');
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $response = $this->postJson('/api/organization/posts', [
            'body' => 'Trying to attach a non-image.',
            'image' => UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain'),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('organization_posts', 0);
    }

    public function test_another_organization_cannot_replace_or_remove_a_posts_image(): void
    {
        Storage::fake('public');
        $orgA = $this->approvedOrganization();
        $orgB = $this->approvedOrganization();
        $post = $orgA->profile->posts()->create(['body' => 'A body', 'image_path' => 'organization-posts/1/original.png']);

        Sanctum::actingAs($orgB->user);

        $this->putJson("/api/organization/posts/{$post->id}", [
            'body' => 'Hijacked',
            'image' => $this->tinyPngFile(),
        ])->assertStatus(404);

        $this->putJson("/api/organization/posts/{$post->id}", [
            'body' => 'Hijacked',
            'remove_image' => true,
        ])->assertStatus(404);

        $this->assertSame('organization-posts/1/original.png', $post->fresh()->image_path);
    }

    public function test_a_student_cannot_upload_a_post_image(): void
    {
        Storage::fake('public');
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($student);

        $this->postJson('/api/organization/posts', [
            'body' => 'A body',
            'image' => $this->tinyPngFile(),
        ])->assertStatus(403);
    }

    public function test_a_posts_image_can_be_replaced(): void
    {
        Storage::fake('public');
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $created = $this->postJson('/api/organization/posts', [
            'body' => 'Original',
            'image' => $this->tinyPngFile('first.png'),
        ])->assertStatus(201);
        $postId = $created->json('data.id');
        $firstPath = \App\Models\OrganizationPost::find($postId)->image_path;
        Storage::disk('public')->assertExists($firstPath);

        $response = $this->putJson("/api/organization/posts/{$postId}", [
            'body' => 'Updated',
            'image' => $this->tinyPngFile('second.png'),
        ]);

        $response->assertStatus(200);
        $secondPath = \App\Models\OrganizationPost::find($postId)->image_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_a_posts_image_can_be_removed_without_replacing_it(): void
    {
        Storage::fake('public');
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $created = $this->postJson('/api/organization/posts', [
            'body' => 'Original',
            'image' => $this->tinyPngFile(),
        ])->assertStatus(201);
        $postId = $created->json('data.id');
        $path = \App\Models\OrganizationPost::find($postId)->image_path;

        $response = $this->putJson("/api/organization/posts/{$postId}", [
            'body' => 'Still here, image gone',
            'remove_image' => true,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.image_url', null);
        Storage::disk('public')->assertMissing($path);
        $this->assertNull(\App\Models\OrganizationPost::find($postId)->image_path);
    }

    public function test_updating_a_post_without_touching_image_fields_keeps_the_existing_image(): void
    {
        Storage::fake('public');
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $created = $this->postJson('/api/organization/posts', [
            'body' => 'Original',
            'image' => $this->tinyPngFile(),
        ])->assertStatus(201);
        $postId = $created->json('data.id');
        $path = \App\Models\OrganizationPost::find($postId)->image_path;

        $this->putJson("/api/organization/posts/{$postId}", ['body' => 'Text only edit'])
            ->assertStatus(200);

        $this->assertSame($path, \App\Models\OrganizationPost::find($postId)->image_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_deleting_a_post_cleans_up_its_managed_image(): void
    {
        Storage::fake('public');
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $created = $this->postJson('/api/organization/posts', [
            'body' => 'Original',
            'image' => $this->tinyPngFile(),
        ])->assertStatus(201);
        $postId = $created->json('data.id');
        $path = \App\Models\OrganizationPost::find($postId)->image_path;
        Storage::disk('public')->assertExists($path);

        $this->deleteJson("/api/organization/posts/{$postId}")->assertStatus(200);

        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_failed_image_replacement_does_not_remove_the_existing_valid_post_or_image(): void
    {
        Storage::fake('public');
        $org = $this->approvedOrganization();
        Sanctum::actingAs($org->user);

        $created = $this->postJson('/api/organization/posts', [
            'body' => 'Original',
            'image' => $this->tinyPngFile(),
        ])->assertStatus(201);
        $postId = $created->json('data.id');
        $originalPath = \App\Models\OrganizationPost::find($postId)->image_path;

        $response = $this->putJson("/api/organization/posts/{$postId}", [
            'body' => 'Attempted update',
            'image' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
        ]);

        $response->assertStatus(422);
        $post = \App\Models\OrganizationPost::find($postId);
        $this->assertSame('Original', $post->body);
        $this->assertSame($originalPath, $post->image_path);
        Storage::disk('public')->assertExists($originalPath);
    }

    private function approvedOrganization(): object
    {
        return $this->organizationWithProfile('approved');
    }

    private function organizationWithProfile(string $approvalStatus): object
    {
        $user = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        $profile = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ]);
        $profile->approval_status = $approvalStatus;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
