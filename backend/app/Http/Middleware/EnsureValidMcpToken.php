<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fixed dev-token auth for the MCP endpoint (routes/ai.php) — deliberately
 * not Sanctum/OAuth for this POC (see laravel/mcp's own OAuth 2.1 and
 * Sanctum support if this ever needs to leave localhost). hash_equals(),
 * not ===, so comparing the presented token against the configured one
 * doesn't leak timing information about how much of it matched.
 */
class EnsureValidMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.mcp.token');
        $provided = $request->bearerToken();

        if (! is_string($expected) || $expected === '' || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
