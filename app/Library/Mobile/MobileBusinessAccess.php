<?php

namespace App\Library\Mobile;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Workspace\WorkspaceMemberModule;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Workspace\BusinessRouteAccess;
use App\Library\Workspace\MemberModuleVisibility;
use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        private readonly CustomerAccountAccessResolver $accountAccess,
        private readonly MemberModuleVisibility $moduleVisibility,
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

        if (! $this->accountUsable($workspace)) {
            // Only someone who could open this Business is told WHY it is closed to them; anyone else gets the same
            // indistinguishable "not found" as for a Business that does not exist.
            if ($business !== null && $this->routeAccess->actorMayUseBusinessRoute($actor, $workspace, $business)) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'account_locked', 'message' => 'This account is locked. Sign in on the web to resolve it.']], 403)->header('Cache-Control', 'no-store'));
            }

            return null;
        }

        if ($business === null || ! $this->routeAccess->actorMayUseBusinessRoute($actor, $workspace, $business)) {
            return null;
        }

        // The web middleware narrows these modules per Workspace membership.
        // Mobile routes do not execute that web middleware, so enforce the same
        // owner-selected visibility here before reading any module data.
        if (! $this->moduleVisible($actor, $workspace, $feature, $permission)) {
            return null;
        }

        if ($permission !== null && ! Gate::forUser($actor)->allows($permission)) {
            return null;
        }

        if ($business->status !== BusinessStatus::Active) {
            return null;
        }

        // Locked (billing/suspension) is reported as such, before the entitlement check would fold it into a 404.
        // Reached only after tenancy and permission passed, so a foreign Business still learns nothing.
        if (app(CustomerAccountAccessGuard::class)->decisionForBusiness($business)->isLocked()) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'account_locked', 'message' => 'This account is locked. Sign in on the web to resolve it.']], 403)->header('Cache-Control', 'no-store'));
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
            if (! $workspace->is_active || ! $this->accountUsable($workspace)) {
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

    /** The same effective account lifecycle authority used by the web gate.
     * Includes managing Agency locks and elapsed grace periods.
     */
    public function accountUsable(Workspace $workspace): bool
    {
        return ! $this->accountAccess->resolve($workspace)->isLocked();
    }

    public function moduleVisible(User $actor, Workspace $workspace, string $feature, ?string $permission): bool
    {
        // Contacts is an independently hideable module despite using the CRM
        // entitlement. Conversations is independently hideable too.
        $module = match (true) {
            $permission === 'view_contact' => WorkspaceMemberModule::Contacts,
            $permission === 'chat_box', $feature === self::FEATURE_CONVERSATIONS => WorkspaceMemberModule::Conversations,
            default => WorkspaceMemberModule::Crm,
        };

        return ! $this->moduleVisibility->isHidden((int) $actor->id, (int) $workspace->id, $module);
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
