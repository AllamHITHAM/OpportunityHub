<?php

namespace Tests\Feature\Student;

use App\Http\Requests\Student\UploadStudentProfilePhotoRequest;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student Profile Photo: `POST`/`DELETE /api/student/profile/photo` --
 * mirrors `OrganizationProfileTest`'s own Company Logo coverage exactly,
 * since `StudentProfileController::uploadPhoto()`/`removePhoto()` mirror
 * `OrganizationProfileController::uploadLogo()`/`removeLogo()` exactly.
 */
class StudentProfilePhotoTest extends TestCase
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

    private function studentWithProfile(array $overrides = []): object
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $profile = StudentProfile::create(array_merge(['user_id' => $user->id], $overrides));

        return (object) ['user' => $user, 'profile' => $profile];
    }

    public function test_student_can_upload_a_real_profile_photo(): void
    {
        Storage::fake('public');
        $student = $this->studentWithProfile(['bio' => 'Aspiring engineer.']);

        Sanctum::actingAs($student->user);

        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::TINY_PNG_BASE64));
        $response = $this->postJson('/api/student/profile/photo', ['photo' => $file]);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $photoUrl = $response->json('data.profile_photo_url');
        $this->assertNotNull($photoUrl);
        $this->assertStringContainsString('/api/media/student-profile-photos/', $photoUrl);
        $this->assertStringNotContainsString('/storage/', $photoUrl);
        $this->assertArrayNotHasKey('profile_image', $response->json('data'));

        $profile = $student->profile->fresh();
        Storage::disk('public')->assertExists($profile->profile_image);

        // Existing, unrelated profile data survives the photo upload
        // untouched.
        $this->assertSame('Aspiring engineer.', $profile->bio);

        // The URL the API actually returned really does resolve to the
        // real uploaded bytes, and is publicly readable per the existing
        // MediaController policy -- not just a plausible-looking string.
        $mediaResponse = $this->get(parse_url($photoUrl, PHP_URL_PATH));
        $mediaResponse->assertStatus(200);
        $this->assertEquals(
            base64_decode(self::TINY_PNG_BASE64),
            $mediaResponse->streamedContent(),
        );
    }

    public function test_the_profile_response_includes_the_photo_url_field(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->getJson('/api/student/profile');

        $response->assertStatus(200)->assertJsonPath('data.profile_photo_url', null);
        $this->assertArrayNotHasKey('profile_image', $response->json('data'));
    }

    public function test_an_invalid_file_type_is_rejected_for_photo_upload(): void
    {
        Storage::fake('public');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $file = UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain');
        $response = $this->postJson('/api/student/profile/photo', ['photo' => $file]);

        $response->assertStatus(422);
        $this->assertNull($student->profile->fresh()->profile_image);
    }

    /**
     * This test environment has no `gd` extension, so a real oversized
     * image can't be generated to exercise `max:2048` end-to-end over
     * HTTP (see the `TINY_PNG_BASE64` doc comment above). Verified
     * directly against the real validation rules instead -- still a
     * genuine assertion that the size cap exists, not a placeholder.
     */
    public function test_the_photo_upload_request_enforces_a_maximum_file_size(): void
    {
        $rules = (new UploadStudentProfilePhotoRequest())->rules();

        $this->assertContains('max:2048', $rules['photo']);
    }

    public function test_only_the_owning_student_can_upload_its_photo(): void
    {
        Storage::fake('public');
        $studentA = $this->studentWithProfile();
        $studentB = $this->studentWithProfile();

        Sanctum::actingAs($studentB->user);
        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::TINY_PNG_BASE64));
        $this->postJson('/api/student/profile/photo', ['photo' => $file])->assertStatus(200);

        $this->assertNull($studentA->profile->fresh()->profile_image);
        $this->assertNotNull($studentB->profile->fresh()->profile_image);
    }

    public function test_an_organization_cannot_upload_a_student_profile_photo(): void
    {
        Storage::fake('public');
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        Sanctum::actingAs($organizationUser);
        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::TINY_PNG_BASE64));

        $this->postJson('/api/student/profile/photo', ['photo' => $file])->assertStatus(403);
    }

    public function test_an_organization_cannot_remove_a_student_profile_photo(): void
    {
        Storage::fake('public');
        $organizationUser = User::factory()->create(['role' => 'organization', 'status' => 'active']);

        Sanctum::actingAs($organizationUser);

        $this->deleteJson('/api/student/profile/photo')->assertStatus(403);
    }

    public function test_an_unauthenticated_request_cannot_upload_a_photo(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::TINY_PNG_BASE64));

        $this->postJson('/api/student/profile/photo', ['photo' => $file])->assertStatus(401);
    }

    public function test_replacing_a_photo_deletes_the_old_managed_file_and_keeps_the_profile_intact(): void
    {
        Storage::fake('public');
        $student = $this->studentWithProfile(['university' => 'State University']);
        Sanctum::actingAs($student->user);

        $first = UploadedFile::fake()->createWithContent('first.png', base64_decode(self::TINY_PNG_BASE64));
        $this->postJson('/api/student/profile/photo', ['photo' => $first])->assertStatus(200);
        $firstPath = $student->profile->fresh()->profile_image;
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->createWithContent('second.png', base64_decode(self::TINY_PNG_BASE64));
        $response = $this->postJson('/api/student/profile/photo', ['photo' => $second]);

        $response->assertStatus(200)->assertJsonPath('data.university', 'State University');
        $secondPath = $student->profile->fresh()->profile_image;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_student_can_remove_its_photo(): void
    {
        Storage::fake('public');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::TINY_PNG_BASE64));
        $this->postJson('/api/student/profile/photo', ['photo' => $file])->assertStatus(200);
        $path = $student->profile->fresh()->profile_image;

        $response = $this->deleteJson('/api/student/profile/photo');

        $response->assertStatus(200)->assertJsonPath('data.profile_photo_url', null);
        $this->assertNull($student->profile->fresh()->profile_image);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_removing_a_photo_when_none_exists_is_a_safe_no_op(): void
    {
        Storage::fake('public');
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        $response = $this->deleteJson('/api/student/profile/photo');

        $response->assertStatus(200)->assertJsonPath('data.profile_photo_url', null);
    }

    public function test_a_missing_profile_returns_a_controlled_404_for_photo_upload(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->createWithContent('photo.png', base64_decode(self::TINY_PNG_BASE64));

        $this->postJson('/api/student/profile/photo', ['photo' => $file])->assertStatus(404);
    }

    public function test_the_general_profile_update_cannot_set_profile_image_directly(): void
    {
        $student = $this->studentWithProfile();
        Sanctum::actingAs($student->user);

        // profile_image is no longer an accepted field on the general
        // update request -- settable only through the dedicated
        // upload/remove endpoints above.
        $this->putJson('/api/student/profile', [
            'bio' => 'Updated bio.',
            'profile_image' => 'organization-logos/1/hacked.png',
        ])->assertStatus(200);

        $this->assertNull($student->profile->fresh()->profile_image);
    }
}
