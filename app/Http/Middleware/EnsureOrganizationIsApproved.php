<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->organizationProfile?->approval_status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Organization is not approved to publish opportunities',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
