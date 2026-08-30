<?php

namespace Tests\Feature\Organization;

use App\Http\Requests\Organization\UploadOrganizationLogoRequest;
use App\Models\Location;
use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The smallest possible valid PNG (1x1, transparent) -- used instead
     * of `UploadedFile::fake()->image()` because this test environment
     * has no `gd` extension, which that helper requires to generate real
     * image bytes. Wrapping genuine (if tiny) real image bytes via
     * `createWithContent()` still exercises Laravel's real `image` rule
     * (`getimagesize()`-based, not just an extension/MIME check)
     * end-to-end without needing `gd`.
     */
    private const TINY_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    public function test_organization_can_view_its_own_profile(): void
    {
        $org = $this->organizationWithProfile('approved', ['organization_name' => 'Acme Corp']);

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.organization_name', 'Acme Corp');
    }

    public function test_organization_can_update_its_own_profile(): void
    {
        $org = $this->organizationWithProfile('approved', ['organization_name' => 'Old Name']);

        Sanctum::actingAs($org->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => 'New Name',
            'organization_type' => 'company',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.organization_name', 'New Name');

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $org->user->id,
            'organization_name' => 'New Name',
        ]);
    }

    public function test_student_cannot_access_organization_profile_routes(): void
    {
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($student);

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized for your account type');
    }

    /**
     * The update endpoint has no route parameter — it always targets
     * $request->user()->organizationProfile — so there is structurally no way
     * for one organization to target another's profile through this route.
     * This test proves the resulting data isolation: organization B's update
     * only ever touches its own row, never organization A's.
     */
    public function test_organization_cannot_modify_another_organizations_profile(): void
    {
        $orgA = $this->organizationWithProfile('approved', ['organization_name' => 'Org A']);
        $orgB = $this->organizationWithProfile('approved', ['organization_name' => 'Org B']);

        Sanctum::actingAs($orgB->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => 'Hijacked Name',
            'organization_type' => 'company',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $orgA->user->id,
            'organization_name' => 'Org A',
        ]);

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $orgB->user->id,
            'organization_name' => 'Hijacked Name',
        ]);
    }

    public function test_approval_status_cannot_be_changed_by_the_organization(): void
    {
        $org = $this->organizationWithProfile('pending');

        Sanctum::actingAs($org->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => $org->profile->organization_name,
            'organization_type' => $org->profile->organization_type,
            'approval_status' => 'approved',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $org->user->id,
            'approval_status' => 'pending',
        ]);
    }

    /**
     * Organization Public Profile phase: `location_id` is a real,
     * canonical Location Catalog reference -- the response exposes it as
     * `{id, canonical_name}` (never a raw ID) via the model's own
     * `location` accessor.
     */
    public function test_organization_can_set_its_own_canonical_location(): void
    {
        $org = $this->organizationWithProfile('approved');
        $location = Location::create(['canonical_name' => 'Ramallah']);

        Sanctum::actingAs($org->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => $org->profile->organization_name,
            'organization_type' => $org->profile->organization_type,
            'location_id' => $location->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.location.id', $location->id)
            ->assertJsonPath('data.location.canonical_name', 'Ramallah');

        $this->assertDatabaseHas('organization_profiles', [
            'user_id' => $org->user->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_organization_location_is_null_when_not_set(): void
    {
        $org = $this->organizationWithProfile('approved');

        Sanctum::actingAs($org->user);

        $response = $this->getJson('/api/organization/profile');

        $response->assertStatus(200)->assertJsonPath('data.location', null);
    }

    public function test_a_nonexistent_location_id_is_rejected(): void
    {
        $org = $this->organizationWithProfile('approved');

        Sanctum::actingAs($org->user);

        $response = $this->putJson('/api/organization/profile', [
            'organization_name' => $org->profile->organization_name,
            'organization_type' => $org->profile->organization_type,
            'location_id' => 999999,
        ]);

        $response->assertStatus(422);
    }

    public function test_logo_url_is_null_when_no_logo_has_been_uploaded(): void
    {
        $org = $this->organizationWithProfile('approved');

        Sanctum::actingAs($org->user);

        $this->getJson('/api/organization/profile')
            ->assertStatus(200)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonMissingPath('data.logo');
    }

    public function test_organization_can_upload_a_real_company_logo(): void
    {
        Storage::fake('public');
        $org = $this->organizationWithProfile('approved');

        Sanctum::actingAs($org->user);

        $file = UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::TINY_PNG_BASE64));
        $response = $this->postJson('/api/organization/profile/logo', ['logo' => $file]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $logoUrl = $response->json('data.logo_url');
        $this->assertNotNull($logoUrl);
        // Final Company Profile Manual-E2E Bug Fix: `/api/media/...`
        // (`MediaController`, routed through Laravel/CORS), never the raw
        // `/storage/...` symlink path -- that never actually rendered in
        // a real browser (see `MediaController`'s own doc comment for the
        // exact `php artisan serve` router-script mechanism). Never a raw
        // filesystem path either.
        $this->assertStringContainsString('/api/media/organization-logos/', $logoUrl);
        $this->assertStringNotContainsString('/storage/', $logoUrl);
        $this->assertArrayNotHasKey('logo', $response->json('data'));

        $profile = $org->profile->fresh();
        Storage::disk('public')->assertExists($profile->logo);

        // The URL the API actually returned really does resolve to the
        // real uploaded bytes -- not just a plausible-looking string.
        // CORS policy itself is covered by CorsConfigurationTest/
        // MediaControllerTest -- this test's own concern is that the
        // logo genuinely uploads and is fetchable.
        $mediaResponse = $this->get(parse_url($logoUrl, PHP_URL_PATH));
        $mediaResponse->assertStatus(200);
        $this->assertEquals(
            base64_decode(self::TINY_PNG_BASE64),
            $mediaResponse->streamedContent(),
        );
    }

    public function test_an_invalid_file_type_is_rejected_for_logo_upload(): void
    {
        Storage::fake('public');
        $org = $this->organizationWithProfile('approved');

        Sanctum::actingAs($org->user);

        $file = UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain');
        $response = $this->postJson('/api/organization/profile/logo', ['logo' => $file]);

        $response->assertStatus(422);
        $this->assertNull($org->profile->fresh()->logo);
    }

    /**
     * This test environment has no `gd` extension, so a real oversized
     * image can't be generated to exercise `max:2048` end-to-end over
     * HTTP (see the `TINY_PNG_BASE64` doc comment above). Verified
     * directly against the real validation rules instead -- still a
     * genuine assertion that the size cap exists, not a placeholder.
     */
    public function test_the_logo_upload_request_enforces_a_maximum_file_size(): void
    {
        $rules = (new UploadOrganizationLogoRequest())->rules();

        $this->assertContains('max:2048', $rules['logo']);
    }

    public function test_only_the_owning_organization_can_upload_its_logo(): void
    {
        Storage::fake('public');
        $orgA = $this->organizationWithProfile('approved');
        $orgB = $this->organizationWithProfile('approved');

        Sanctum::actingAs($orgB->user);
        $file = UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::TINY_PNG_BASE64));
        $this->postJson('/api/organization/profile/logo', ['logo' => $file])->assertStatus(200);

        $this->assertNull($orgA->profile->fresh()->logo);
        $this->assertNotNull($orgB->profile->fresh()->logo);
    }

    public function test_a_student_cannot_upload_an_organization_logo(): void
    {
        Storage::fake('public');
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        Sanctum::actingAs($student);
        $file = UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::TINY_PNG_BASE64));

        $this->postJson('/api/organization/profile/logo', ['logo' => $file])->assertStatus(403);
    }

    public function test_an_unauthenticated_request_cannot_upload_a_logo(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::TINY_PNG_BASE64));

        $this->postJson('/api/organization/profile/logo', ['logo' => $file])->assertStatus(401);
    }

    public function test_replacing_a_logo_deletes_the_old_managed_file_and_keeps_the_profile_intact(): void
    {
        Storage::fake('public');
        $org = $this->organizationWithProfile('approved');
        Sanctum::actingAs($org->user);

        $first = UploadedFile::fake()->createWithContent('first.png', base64_decode(self::TINY_PNG_BASE64));
        $this->postJson('/api/organization/profile/logo', ['logo' => $first])->assertStatus(200);
        $firstPath = $org->profile->fresh()->logo;
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->createWithContent('second.png', base64_decode(self::TINY_PNG_BASE64));
        $response = $this->postJson('/api/organization/profile/logo', ['logo' => $second]);

        $response->assertStatus(200)->assertJsonPath('data.organization_name', $org->profile->organization_name);
        $secondPath = $org->profile->fresh()->logo;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_organization_can_remove_its_logo(): void
    {
        Storage::fake('public');
        $org = $this->organizationWithProfile('approved');
        Sanctum::actingAs($org->user);

        $file = UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::TINY_PNG_BASE64));
        $this->postJson('/api/organization/profile/logo', ['logo' => $file])->assertStatus(200);
        $path = $org->profile->fresh()->logo;

        $response = $this->deleteJson('/api/organization/profile/logo');

        $response->assertStatus(200)->assertJsonPath('data.logo_url', null);
        $this->assertNull($org->profile->fresh()->logo);
        Storage::disk('public')->assertMissing($path);
    }

    private function organizationWithProfile(string $approvalStatus = 'approved', array $attributes = []): object
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'status' => 'active',
        ]);

        $profile = OrganizationProfile::create(array_merge([
            'user_id' => $user->id,
            'organization_name' => 'Test Organization',
            'organization_type' => 'company',
        ], $attributes));

        $profile->approval_status = $approvalStatus;
        $profile->save();

        return (object) ['user' => $user, 'profile' => $profile];
    }
}
