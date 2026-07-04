<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentProfileExists
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'You must create a student profile first',
                'data' => null,
            ], 404);
        }

        return $next($request);
    }
}
