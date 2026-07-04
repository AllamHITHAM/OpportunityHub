<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()?->role !== $role) {
            return response()->json([
                'success' => false,
                'message' => 'This action is unauthorized for your account type',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
