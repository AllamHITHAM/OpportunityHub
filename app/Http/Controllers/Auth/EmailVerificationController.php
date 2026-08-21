<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 8B-2: the smallest correct email-verification flow on top of
 * Laravel's own signed-URL mechanism (`MustVerifyEmail`,
 * `URL::temporarySignedRoute`, both already used by
 * `App\Notifications\VerifyEmailNotification` / `App\Models\User`). No
 * custom verification tokens exist anywhere -- this controller only ever
 * checks the framework's own signature.
 */
class EmailVerificationController extends Controller
{
    /**
     * The link a Student/Organization/Admin clicks from their inbox.
     * Deliberately *not* gated by `auth:sanctum` -- an email client
     * navigating to this link never carries a Bearer token, so the
     * cryptographic signature (verified manually below, not via the
     * `signed` middleware) plus the per-user `$hash` are this action's
     * entire authorization: forging either without the server's APP_KEY
     * is infeasible, and changing `$id` without also breaking the
     * signature is impossible (the signature covers every route
     * parameter). Always redirects to the Flutter Web app -- a safe UI
     * state either way, never a raw Laravel error page or a crash, per
     * this phase's own "safe UI, not a crash" requirement.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');

        if (! $request->hasValidSignature()) {
            return redirect()->away("{$frontendBase}/email-verified?status=invalid");
        }

        $user = User::find((int) $id);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away("{$frontendBase}/email-verified?status=invalid");
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away("{$frontendBase}/email-verified?status=success");
    }

    /**
     * Resends the verification email to the authenticated user.
     * Idempotent/safe if already verified -- never re-sends, never
     * errors, just confirms the current state.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Your email is already verified.',
                'data' => null,
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Verification link sent.',
            'data' => null,
        ]);
    }
}
