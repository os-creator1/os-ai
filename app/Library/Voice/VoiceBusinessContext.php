<?php

namespace App\Library\Voice;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerContext;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Support\Facades\Gate;

/**
 * The tenancy decision for every voice route — the same chain Conversations applies (active Workspace,
 * a Business of that Workspace, BusinessRouteAccess, active Business, the Conversations entitlement,
 * the `chat_box` permission), plus one voice-specific refusal: calling is never available while an
 * Agency is "viewing as" a client, because a call is a live act by that person, not a view.
 *
 * Every failure is the same null so a caller learns nothing about which part failed.
 */
final class VoiceBusinessContext
{
    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly BusinessRouteAccess $access,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /** @return array{0: Workspace, 1: Business}|null */
    public function resolve(User $actor, string $workspaceUid, string $businessUid): ?array
    {
        $workspace = $this->workspaces->findByUid($workspaceUid);

        if ($workspace === null || ! $workspace->is_active) {
            return null;
        }

        $business = $this->workspaces->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid);

        if ($business === null || ! $this->access->actorMayUseBusinessRoute($actor, $workspace, $business)) {
            return null;
        }

        $context = app()->bound(CustomerContext::class) ? app(CustomerContext::class) : null;

        if ($context?->viewAs !== null) {
            return null;
        }

        if (! Gate::forUser($actor)->allows('chat_box') || $business->status !== BusinessStatus::Active) {
            return null;
        }

        try {
            $decision = $this->entitlements->decide($workspace, $business, PlatformFeature::Conversations->value, (int) $actor->id);
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return null;
        }

        return $decision->allowed ? [$workspace, $business] : null;
    }
}
