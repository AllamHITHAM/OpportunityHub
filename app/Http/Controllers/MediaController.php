<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Final Company Profile Manual-E2E Bug Fix: serves every managed public
 * image (a Company Logo, a post image -- anything {@see \App\Services\ImageStorageService}
 * ever stores) through Laravel itself, at `GET /api/media/{path}`, rather
 * than the raw `public/storage` symlink `Storage::disk('public')->url()`
 * pointed at before.
 *
 * **Root cause this exists to fix**: `php artisan serve`'s router script
 * (`vendor/laravel/framework/.../resources/server.php`) contains
 * `if ($uri !== '/' && file_exists($publicPath.$uri)) { return false; }` --
 * any request whose path resolves to a real file under `public/` (which
 * `public/storage/organization-logos/...` genuinely is, via the
 * `storage:link` symlink) is served as a raw static file by PHP's built-in
 * server itself, *never reaching Laravel's HTTP kernel at all*. That means
 * every middleware this app registers -- including `HandleCors`, which
 * empirically DOES add `Access-Control-Allow-Origin` to every `/api/*`
 * response (verified directly: `curl` against a real running `php artisan
 * serve` instance) -- never runs for a `/storage/...` request. In a real
 * Chrome tab, Flutter Web (CanvasKit renderer) fetches `Image.network`
 * sources via `fetch`/XHR, which the browser blocks from being read when
 * the response carries no CORS header and the request is cross-origin
 * (the Flutter web dev server and the Laravel dev server run on different
 * origins/ports) -- surfacing as the exact "broken image" placeholder this
 * bug report describes. This is inherent to how PHP's built-in dev server
 * behaves with a router script; no Laravel-side CORS *configuration*
 * (`config/cors.php`) can fix it, because the request never reaches
 * Laravel to begin with.
 *
 * **The fix**: a URL that does NOT correspond to any real file under
 * `public/` is never short-circuited by the dev server -- it always falls
 * through to `index.php`/Laravel, where the already-correctly-configured
 * `api` middleware group's `HandleCors` applies exactly like it already
 * does for every other `/api/*` response. `/api/media/{path}` was chosen
 * specifically to reuse the app's existing API/base URL architecture (the
 * same host:port Flutter's `ApiConstants.baseUrl` already talks to), per
 * the bug report's explicit "use the application's real API/base URL
 * architecture, do not hardcode localhost" instruction -- never a second,
 * separate asset host.
 *
 * The `public/storage` symlink itself is untouched (still created by
 * `php artisan storage:link`, still harmless if something else relies on
 * it) -- this controller reads directly from the `public` disk
 * (`storage/app/public`) server-side, so it works correctly whether or not
 * that symlink even exists. `storage:link` is therefore no longer required
 * for this app's own served images to render correctly (documented here
 * since the bug report explicitly asked for that to be called out).
 *
 * **Backend Configuration & Safety Pass**: this controller no longer sets
 * its own `Access-Control-Allow-Origin` header -- now that a real,
 * environment-driven `config/cors.php` exists, `HandleCors` already
 * applies that same policy to this route (it matches the configured
 * `api/*` path exactly like every other `/api/*` endpoint), so a second,
 * separately-hardcoded header here would just be a redundant, competing
 * source of truth. One authoritative CORS policy for the whole app.
 */
class MediaController extends Controller
{
    /**
     * Streams the managed file at [$path] from the `public` disk, with an
     * explicit long-lived cache header (safe: every stored filename is a
     * server-generated UUID that's never reused for different bytes -- see
     * `ImageStorageService::store()` -- so a given URL's content never
     * changes once published). CORS itself is handled by the app's own
     * `HandleCors` middleware + `config/cors.php`, not set here. A path
     * with no matching stored file, or containing a `..` traversal
     * segment, is a plain 404 -- this never reveals whether a *different*,
     * real path exists, and never exposes any server filesystem path in
     * the response.
     */
    public function show(string $path): StreamedResponse
    {
        if (str_contains($path, '..') || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
