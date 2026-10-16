<?php

namespace App\Library\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * THE one owner-aware decision for credential-class management permissions
 * (manage_business_email, manage_google_ads, manage_meta_ads). Every UI flag,
 * connect / callback / disconnect / selection / refresh route and provider
 * mutation asks this instead of the bare permission, so they cannot disagree.
 *
 * Allowed: the active Workspace's actual owner (workspaces.owner_user_id, the
 * canonical ownership column) — so existing owners need no permission backfill
 * and no default changes — or an actor holding the explicit permission.
 * Ownership grants this management authority only: never other permissions,
 * and never anything a provider (Google, Meta) must itself grant.
 *
 * It does not replace tenancy or entitlement: callers run their tenancy chain
 * first. While a View-As session is live it is the whole answer and nothing is
 * manageable, owner or not.
 */
class WorkspaceManagementAuthority
{
    public function __construct(private readonly BusinessRouteAccess $routes)
    {
    }

    /** For the signed-in user (controllers, views). */
    public function mayManage(Workspace $workspace, string $permission): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && $this->allows($actor, $workspace, $permission);
    }

    public function allows(User $actor, Workspace $workspace, string $permission): bool
    {
        if ($this->routes->viewedBusinessIdFor((int) $actor->id) !== null) {
            return false;
        }

        if ($workspace->is_active && (int) $workspace->owner_user_id === (int) $actor->id) {
            return true;
        }

        return Gate::forUser($actor)->allows($permission);
    }
}
