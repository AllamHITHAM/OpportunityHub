<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterOrganizationRequest;
use App\Http\Requests\Auth\RegisterStudentRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function registerStudent(RegisterStudentRequest $request): JsonResponse
    {
        $user = User::create($request->only(['name', 'email', 'password']));
        $user->refresh();

        $token = $user->createToken('auth_token')->plainTextToken;

        // Phase 8B-2: fired after the row is fully committed (student
        // registration has no surrounding transaction), so the queued
        // notification job can never run before the user it references
        // exists in the database.
        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Student registered successfully',
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
        ], 201);
    }

    public function registerOrganization(RegisterOrganizationRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->only(['name', 'email', 'password']));
            $user->role = 'organization';
            $user->save();
            $user->refresh();

            OrganizationProfile::create([
                'user_id' => $user->id,
                'organization_name' => $request->input('organization_name'),
                'organization_type' => $request->input('organization_type'),
                'industry' => $request->input('industry'),
                'description' => $request->input('description'),
                'website' => $request->input('website'),
                'logo' => $request->input('logo'),
                'phone' => $request->input('phone'),
            ]);

            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        // Phase 8B-2: deliberately called after the transaction above has
        // already committed, exactly like `$token = ...` on the previous
        // line -- queuing this from inside the transaction closure could
        // let a queue worker try to process it before the user row (and
        // its organization profile) are actually visible to other
        // connections.
        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Organization registered successfully',
            'data' => [
                'user' => $user->load('organizationProfile'),
                'token' => $token,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only(['email', 'password']))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
                'data' => null,
            ], 401);
        }

        $user = User::where('email', $request->input('email'))->firstOrFail();

        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is not active',
                'data' => null,
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
        ]);
    }

    /**
     * Phase 8B-2: requests a password-reset email via Laravel's own
     * password broker (`Password::sendResetLink()`) -- token generation,
     * hashing, storage (`password_reset_tokens`), and the 60-second
     * per-email throttle are all its untouched standard behavior (see
     * `config/auth.php`).
     *
     * Security: the response is byte-for-byte identical whether the email
     * belongs to a real account, was just throttled, or doesn't exist at
     * all -- `$status` is deliberately never inspected or exposed, so this
     * endpoint can never be used to enumerate registered accounts.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'success' => true,
            'message' => 'If an account exists for this email, password reset instructions have been sent.',
            'data' => null,
        ]);
    }

    /**
     * Phase 8B-2: completes a password reset via Laravel's own password
     * broker (`Password::reset()`), which independently re-validates the
     * token against the hashed row in `password_reset_tokens` (rejecting
     * it if it's wrong, expired, or already consumed) before this
     * callback ever runs -- no token validation happens in this method.
     *
     * The new password is assigned as plain text and saved -- `User`'s
     * own `'password' => 'hashed'` cast hashes it automatically, exactly
     * like registration does; never calling `Hash::make()` here avoids
     * any risk of double-hashing.
     *
     * Every existing Sanctum token for this user is revoked on success —
     * a leaked/stale token must not survive a password reset (see
     * docs/BUSINESS_RULES.md for this decision).
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => __($status),
                'data' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset successfully.',
            'data' => null,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Authenticated user retrieved',
            'data' => $request->user(),
        ]);
    }
}
