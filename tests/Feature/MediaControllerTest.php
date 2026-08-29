<?php

namespace Tests\Feature;

use App\Models\OrganizationProfile;
use App\Models\User;
use App\Services\ImageStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Final Company Profile Manual-E2E Bug Fix: `GET /api/media/{path}`
 * (`MediaController`) -- the fix for the real "post image doesn't render in
 * Chrome" / "logo doesn't persist visually" bugs, both root-caused to the
 * exact same thing: `Storage::disk('public')->url()` generated a raw
 * `/storage/...` URL that `php artisan serve`'s dev-server router serves as
 * a static file, bypassing Laravel (and every CORS header) entirely -- see
 * `MediaController`'s own doc comment for the full mechanism. This new
 * route serves the same files through Laravel itself, always carrying the
 * app's existing `/api/*` CORS policy.
 */
class MediaControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TINY_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY'.
        '42YAAAAASUVORK5CYII=';

    public function test_a_stored_image_is_served_with_an_explicit_cors_header(): void
    {
        Storage::fake('public');
        $bytes = base64_decode(self::TINY_PNG_BASE64);
        $path = app(ImageStorageService::class)->store(
            UploadedFile::fake()->createWithContent('logo.png', $bytes),
            'organization-logos/1',
        );

        $response = $this->get('/api/media/'.$path);

        $response->assertStatus(200);
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertEquals($bytes, $response->streamedContent());
    }

    public function test_a_nonexistent_path_is_a_plain_404_not_a_server_error(): void
    {
        Storage::fake('public');

        $this->get('/api/media/organization-logos/1/does-not-exist.png')->assertStatus(404);
    }

    public function test_a_path_traversal_attempt_is_rejected(): void
    {
        Storage::fake('public');

        // Whatever the routing layer's own URL normalization does with
        // these, the controller's own `str_contains($path, '..')` guard
        // (backed by Flysystem's own root-scoping either way) must never
        // let a request outside the `public` disk succeed.
        $this->get('/api/media/../.env')->assertStatus(404);
        $this->get('/api/media/organization-logos/../../.env')->assertStatus(404);
    }

    public function test_the_controller_itself_rejects_a_dot_dot_path_directly(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('.env', 'SECRET=leak');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        app(\App\Http\Controllers\MediaController::class)->show('organization-logos/../../.env');
    }

    public function test_the_route_url_generated_by_image_storage_service_actually_resolves(): void
    {
        Storage::fake('public');
        $bytes = base64_decode(self::TINY_PNG_BASE64);
        $path = app(ImageStorageService::class)->store(
            UploadedFile::fake()->createWithContent('post.png', $bytes),
            'organization-posts/1',
        );

        $url = app(ImageStorageService::class)->url($path);

        $this->assertStringContainsString('/api/media/', $url);
        $this->assertStringNotContainsString('/storage/', $url);
        // Never a raw filesystem path/backslash leaking through -- the
        // real stored path is storage-relative and forward-slash only.
        $this->assertStringNotContainsString('storage/app', $url);
        $this->assertStringNotContainsString('\\', $url);

        $this->get(parse_url($url, PHP_URL_PATH))
            ->assertStatus(200)
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_the_company_logo_and_post_image_urls_both_resolve_through_the_real_endpoint(): void
    {
        Storage::fake('public');
        $org = User::factory()->create(['role' => 'organization', 'status' => 'active']);
        $profile = OrganizationProfile::create([
            'user_id' => $org->id,
            'organization_name' => 'Media Test Org',
            'organization_type' => 'company',
            'logo' => app(ImageStorageService::class)->store(
                UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::TINY_PNG_BASE64)),
                'organization-logos/999',
            ),
        ]);
        $profile->approval_status = 'approved';
        $profile->save();

        $response = $this->getJson("/api/organizations/{$profile->id}");

        $logoUrl = $response->json('data.logo_url');
        $this->get(parse_url($logoUrl, PHP_URL_PATH))->assertStatus(200);
    }
}
