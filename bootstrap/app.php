<?php

use App\Http\Middleware\EnsureOrganizationIsApproved;
use App\Http\Middleware\EnsureStudentProfileExists;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
            'profile.exists' => EnsureStudentProfileExists::class,
            'org.approved' => EnsureOrganizationIsApproved::class,
        ]);

        // Backend Configuration & Safety Pass: correct https:// URL
        // generation (route()/url(), password-reset and email-
        // verification signed links, ImageStorageService::url()) when
        // staging sits behind a TLS-terminating reverse proxy/PaaS load
        // balancer -- without it, Laravel sees the plain-HTTP connection
        // from the proxy to PHP and generates http:// URLs even though
        // the real client connection was HTTPS.
        //
        // Empty (nothing trusted) by default -- exactly today's local-dev
        // behavior, where there is no proxy and the scheme is whatever
        // the actual connection really is. TRUSTED_PROXIES is set only
        // once the real staging infrastructure is known (a later phase):
        // either a specific proxy IP/CIDR list, or the literal string
        // "*" to trust the immediate connecting proxy unconditionally
        // (Laravel's own supported shorthand for "trust whoever is
        // directly connecting" -- appropriate only when the app is
        // guaranteed unreachable except through that proxy, the normal
        // case for a containerized PaaS deploy). Never trusted blindly
        // by default, and never a specific IP guessed/hardcoded here.
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(
                at: $trustedProxies === ['*'] ? '*' : $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }
    })
   ->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (AuthenticationException $e, Request $request) {
        if ($request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
                'data' => null,
            ], 401);
        }

        return null;
    });

    $exceptions->render(function (NotFoundHttpException $e, Request $request) {
        if ($request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
                'data' => null,
            ], 404);
        }

        return null;
    });
})->create();
