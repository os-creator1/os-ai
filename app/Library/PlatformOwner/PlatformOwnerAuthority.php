<?php

namespace App\Library\PlatformOwner;

use App\Library\ViewAs\ViewAsManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Platform Owner / Admin V1 — the ONE rule for "is this actor a Platform
 * Owner right now".
 *
 * There is deliberately no second admin-role system. The canonical
 * platform-administrator marker in this codebase is `users.is_admin` — the
 * account-type flag EnsureUserIsAdministrator, EntitlementManager's own
 * assertPlatformAdministrator() and every other admin write already use.
 * Per-feature permission strings ('view workspace', 'manage workspace
 * plans', 'edit business', ...) stay a SECOND, independent layer on top of
 * this one, exactly as before; this class never replaces them.
 *
 * What does NOT confer authority, by construction:
 *  - owning a Workspace, or being a member of one;
 *  - owning an Agency Workspace (an Agency owner is a customer account);
 *  - View As. An actor with an open View-as-client frame is acting inside a
 *    customer's context; that frame must never double as a way into the
 *    platform-owner surface, so allowsRequest() refuses while one is open
 *    even for an account that is otherwise an administrator. The admin
 *    "login as customer" support mechanism swaps the authenticated user for
 *    the customer's own (non-admin) account, which fails the account-type
 *    check outright.
 *  - any identifier in the request. Nothing here reads a request parameter
 *    to decide authority; the actor is always the authenticated user.
 *
 * Two entry points on purpose:
 *  - allowsRequest(): the route boundary. Reads the authenticated user and
 *    the session's View As frame.
 *  - assertAdministrator(): the write-path re-check. Re-reads `is_admin`
 *    from the database by id (never from a possibly stale in-memory model),
 *    so a service that mutates on a Platform Owner's behalf does not trust
 *    the controller that called it.
 */
final class PlatformOwnerAuthority
{
    public function allowsRequest(Request $request): bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_admin) {
            return false;
        }

        // A View As frame is a customer context. See the class docblock.
        if ($request->hasSession() && $request->session()->has(ViewAsManager::SESSION_KEY)) {
            return false;
        }

        return true;
    }

    /**
     * The `->missing()` callback for a Platform Owner route whose {workspace}
     * or {business} did not resolve.
     *
     * Route-model binding runs BEFORE the route's authority middleware, so
     * without this a non-owner would see "refused" for a Workspace that exists
     * and "not found" for one that does not — an existence oracle on exactly
     * the identifiers this surface is about. Here the authority question is
     * asked first: a non-owner gets the same refusal whether the target exists
     * or not, and only a real Platform Owner is told "not found".
     *
     * @throws AuthorizationException
     */
    public function refuseMissingTarget(Request $request): never
    {
        if (! $this->allowsRequest($request)) {
            throw new AuthorizationException('This area is restricted to administrators.');
        }

        abort(404);
    }

    /**
     * @throws AuthorizationException
     */
    public function assertAdministrator(int $actorUserId): void
    {
        $isAdmin = (bool) User::query()->whereKey($actorUserId)->value('is_admin');

        if (! $isAdmin) {
            throw new AuthorizationException('This action is restricted to platform administrators.');
        }
    }
}
