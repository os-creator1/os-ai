<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a phone's token on its own surface. The legacy /api/v3 (SMS send, contact writes) predates
 * Workspaces, so a mobile-scoped token must never reach it even though both authenticate via Sanctum.
 */
final class RejectMobileTokenOnLegacyApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && in_array('mobile', (array) ($token->abilities ?? []), true)) {
            return response()->json(['status' => 'error', 'message' => 'This token cannot be used here.'], 403);
        }

        return $next($request);
    }
}
