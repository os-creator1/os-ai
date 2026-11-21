<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for /api/mobile/v1 (after auth:sanctum). Only a token minted by the mobile login (the explicit
 * `mobile` ability — a wildcard `*` token does NOT qualify) may call it, and its owner must still be an enabled user: a disabled account loses
 * the phone immediately rather than at token expiry.
 */
final class EnsureMobileToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user === null || $token === null || ! in_array('mobile', (array) ($token->abilities ?? []), true) || ! $user->status) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in again.']], 401);
        }

        // Web sessions are revoked by users.password_changed_at. A Sanctum
        // token must respect the same account-wide cutoff, including after
        // password resets and "Sign out of all sessions". Compare database
        // timestamps conservatively: a token minted in the same second as
        // the revocation is also denied rather than allowing an old token.
        if ($user->password_changed_at !== null
            && ($token->created_at === null || $token->created_at->lessThanOrEqualTo($user->password_changed_at))) {
            return response()->json(['error' => ['code' => 'unauthenticated', 'message' => 'Sign in again.']], 401);
        }

        return $next($request);
    }
}
