<?php

namespace App\Library\Mobile;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\BusinessRouteAccess;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Facades\Gate;

/**
 * The tenancy chain for the mobile API: the SAME authorities, in the SAME order, as
 * ChatBoxController::resolveBusiness() and the `chat.business.{uid}` broadcast channel —
 * Workspace -> Business inside it -> BusinessRouteAccess -> permission -> Business status
 * -> EntitlementManager. It takes the actor explicitly (a token request has no session and
 * no view-as context), and returns null for EVERY failure so a probe cannot tell a foreign
 * Business from a missing one.
 *
 * Phase 1 deliberately supports ordinary tenancy only. An Agency reaching a managed client
 * "as" that client is a web view-as SESSION concept; a token carries none, so it is refused
 * by BusinessRouteAccess rather than approximated here.
 */
final class MobileBusinessAccess
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly WorkspaceManager $workspaceManager,
        private readonly BusinessRouteAccess $routeAccess,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * @return array{0: Workspace, 1: Business}|null
     */
    public function resolve(User $actor, string $workspaceUid, string $businessUid, string $feature, ?string $permission): ?array
    {
        $workspace = $this->workspaces->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            return null;
        }

        $business = $this->workspaces->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->routeAccess->actorMayUseBusinessRoute($actor, $workspace, $business)) {
            return null;
        }

        if ($permission !== null && ! Gate::forUser($actor)->allows($permission)) {
            return null;
        }

        if ($business->status !== BusinessStatus::Active) {
            return null;
        }

        return $this->entitled($actor, $workspace, $business, $feature) ? [$workspace, $business] : null;
    }

    /**
     * Every Active Business this actor may open, with its Workspace.
     *
     * @return list<array{0: Workspace, 1: Business}>
     */
    public function accessibleBusinesses(User $actor): array
    {
        $out = [];

        foreach ($this->workspaces->allForUser((int) $actor->id) as $workspace) {
            if (! $workspace->is_active) {
                continue;
            }

            foreach ($this->workspaces->businessesForWorkspace($workspace) as $business) {
                if ($business->status === BusinessStatus::Active
                    && $this->workspaceManager->userCanAccessBusiness((int) $actor->id, $business)
                    && $this->routeAccess->actorMayUseBusinessRoute($actor, $workspace, $business)) {
                    $out[] = [$workspace, $business];
                }
            }
        }

        return $out;
    }

    public function entitled(User $actor, Workspace $workspace, Business $business, string $feature): bool
    {
        try {
            return $this->entitlements->decide($workspace, $business, $feature, (int) $actor->id)->allowed;
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }
    }

    public const FEATURE_CONVERSATIONS = PlatformFeature::Conversations->value;

    public const FEATURE_CRM = PlatformFeature::Crm->value;
}
