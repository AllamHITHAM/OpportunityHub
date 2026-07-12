<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterOrganizationRequest;
use App\Http\Requests\Auth\RegisterStudentRequest;
use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function registerStudent(RegisterStudentRequest $request): JsonResponse
    {
        $user = User::create($request->only(['name', 'email', 'password']));
        $user->refresh();

        $token = $user->createToken('auth_token')->plainTextToken;

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
