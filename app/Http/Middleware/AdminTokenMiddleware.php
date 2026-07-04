<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AdminTokenMiddleware
 *
 * Simple bearer token guard for admin/knowledge routes.
 * The token is set in .env as ADMIN_API_TOKEN.
 *
 * Usage in React admin panel:
 *   fetch('/api/admin/knowledge/rebuild', {
 *     method: 'POST',
 *     headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
 *   })
 */
class AdminTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expected = config('services.admin.api_token');

        if (!$token || !$expected || !hash_equals($expected, $token)) {
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
