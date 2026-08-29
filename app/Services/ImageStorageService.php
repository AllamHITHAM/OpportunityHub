<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Company Profile Polish phase: the one shared place that stores/serves/
 * deletes a managed image (an Organization's Company Logo, or one
 * "Updates & Achievements" post image) -- used by both
 * `Organization\OrganizationProfileController` and
 * `Organization\OrganizationPostController` rather than duplicating the
 * same storage logic twice.
 *
 * Deliberately the `public` disk (`storage/app/public`, served via the
 * `public/storage` symlink -- see `config/filesystems.php`), unlike the
 * `local` disk `CVController`/`EducationVerificationController` use: a
 * Company Logo/post image is meant to be publicly viewable by any
 * Student browsing a Company Profile, not gated behind an authenticated
 * download endpoint the way a private CV document is.
 *
 * Every stored filename is server-generated (`Str::uuid()`), never the
 * client's original filename or anything derived from request input --
 * the same "the client never chooses where its file ends up" doctrine
 * `CVController::store()` already established, which is also what
 * prevents path traversal (there is no client-controlled path segment
 * anywhere in the stored path). The returned path is always
 * storage-relative (e.g. `organization-logos/3/<uuid>.jpg`); the real
 * filesystem root is never exposed to a caller.
 */
class ImageStorageService
{
    private const DISK = 'public';

    /**
     * Stores [$file] under [$directory] with a fresh random filename,
     * preserving only the real (server-validated) extension. Returns the
     * storage-relative path to persist on the owning model.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $filename = Str::uuid()->toString().'.'.$file->extension();

        $storedPath = $file->storeAs($directory, $filename, self::DISK);

        abort_unless($storedPath !== false, 500, 'Failed to store the image.');

        return $storedPath;
    }

    /**
     * The full, publicly-reachable URL for a stored [$path] -- `null`
     * when [$path] itself is `null`, so a caller never needs its own
     * null check before calling this.
     *
     * Final Company Profile Manual-E2E Bug Fix: this is `route('media.show', ...)`
     * (`MediaController`), not the raw `Storage::disk('public')->url()`
     * symlink path this used to return -- see `MediaController`'s own doc
     * comment for the exact reason the symlink URL never actually renders
     * in a real browser (`php artisan serve`'s dev-server router silently
     * bypasses Laravel -- and therefore every CORS header -- for any
     * request that resolves to a real file on disk, which the symlinked
     * path always did). `route()` respects `APP_URL`/the app's real,
     * already-configured URL generation -- never a hardcoded host.
     */
    public function url(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return route('media.show', ['path' => $path]);
    }

    /**
     * Deletes the managed file at [$path], if any -- a `null` path (no
     * image was ever set) is always a safe, silent no-op, never an
     * error. Only ever called with a path this service itself generated
     * via [store] (never a client-supplied string), so there is no
     * legacy/unmanaged-path ambiguity to guard against here the way
     * `CVController::destroy()` must for its own historical rows.
     */
    public function delete(?string $path): void
    {
        if ($path === null) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }
}
