<?php

namespace App\Library\BusinessEmail;

use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * THE one decision for "may the signed-in user connect, reconnect or
 * disconnect this Business's mailbox" — used by Settings → Email, connect,
 * disconnect and the OAuth callback, so they can never disagree.
 *
 * Allowed: the Workspace's actual owner (workspaces.owner_user_id — the
 * canonical ownership column), or any actor holding the explicit
 * manage_business_email permission. Ownership is read from the persisted
 * Workspace, so existing owners work with no permission backfill; it is not a
 * customer permission and grants nothing else.
 *
 * This never replaces the tenancy chain: callers run
 * ResolvesBusinessTenancy first, which already refuses other tenants,
 * inactive memberships and an inactive Workspace. A View-As session is the
 * whole answer while it lasts and may never manage a client's mailbox, even
 * when the viewing actor owns that Workspace.
 */
class BusinessEmailAuthority
{
    public function __construct(private readonly BusinessRouteAccess $routes)
    {
    }

    public function mayManage(Workspace $workspace): bool
    {
        $actorId = (int) Auth::id();

        if ($actorId === 0) {
            return false;
        }

        if ($this->routes->viewedBusinessIdFor($actorId) !== null) {
            return false;
        }

        if ((int) $workspace->owner_user_id === $actorId) {
            return true;
        }

        return Gate::allows('manage_business_email');
    }
}
