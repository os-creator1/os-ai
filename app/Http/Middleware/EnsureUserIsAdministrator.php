<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Explicit admin-only boundary for the admin Business routes (RFC-001 §16,
 * §19 — Milestone 6 hardening).
 *
 * The admin route group's blanket 'can:access backend' gate checks a
 * permission STRING, resolved through AccountRepository::hasPermission() —
 * which also branches on account type internally, and treats users.id === 1
 * as an unconditional super-admin bypass regardless of account type. This
 * middleware is a second, independent boundary that checks only the
 * established account-type flag (users.is_admin — the same flag every other
 * part of this application already uses to distinguish admin accounts from
 * customer accounts) so that Business admin data is never reachable by a
 * non-admin account, regardless of what any permission string resolves to.
 *
 * This does not replace the 'view business' / 'edit business' feature-level
 * checks still performed in BusinessController — both layers are kept
 * (defense in depth).
 */
class EnsureUserIsAdministrator
{
    /**
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || ! $request->user()->is_admin) {
            throw new AuthorizationException('This area is restricted to administrators.');
        }

        return $next($request);
    }
}
