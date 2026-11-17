<?php

namespace App\Library\Workspace;

use App\Enums\Workspace\WorkspaceMemberModule;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * Which modules the Workspace owner has switched OFF for one person in one
 * Workspace. The single reader every enforcement point asks (menu snapshot,
 * route middleware, Growth reads, search) so they cannot drift apart.
 *
 * NARROWING ONLY. The answer can remove access a person would otherwise have;
 * it can never add any, and it holds no entitlement or permission policy of its
 * own — those stay with EntitlementManager and the Gate.
 *
 * WHO IS EVER NARROWED: only an ACTIVE, non-removed Workspace member of THAT
 * Workspace. Everyone else gets "nothing hidden", which is what keeps the
 * existing authorization paths separate (see AGENTS.md "Workspace authorization"):
 *  - the Workspace owner always has full access (owner wins over any anomalous
 *    coexisting membership row);
 *  - an Agency actor in View As is not a member of the client Workspace, so the
 *    View-As session is untouched;
 *  - platform admins, sub-account parents and background work (no actor) are not
 *    members and are never narrowed — so hiding SEO from a person never stops SEO
 *    audits or automations running.
 *
 * Request-scoped memo (on the request's attribute bag, so it cannot outlive a
 * request or leak between users in a long-lived worker); a save clears it.
 */
final class MemberModuleVisibility
{
    private const ATTRIBUTE = 'memberModuleVisibility';

    /**
     * @return list<WorkspaceMemberModule>
     */
    public function hiddenFor(?int $userId, ?int $workspaceId): array
    {
        if ($userId === null || $userId <= 0 || $workspaceId === null || $workspaceId <= 0) {
            return [];
        }

        $request = app()->bound('request') ? request() : null;
        $key = $workspaceId . ':' . $userId;
        $memo = $request?->attributes->get(self::ATTRIBUTE, []);

        if (is_array($memo) && array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $hidden = $this->fromResolvedContext($request, $userId, $workspaceId) ?? $this->read($userId, $workspaceId);

        if ($request !== null) {
            $memo = is_array($memo) ? $memo : [];
            $memo[$key] = $hidden;
            $request->attributes->set(self::ATTRIBUTE, $memo);
        }

        return $hidden;
    }

    public function isHidden(?int $userId, ?int $workspaceId, WorkspaceMemberModule $module): bool
    {
        return in_array($module, $this->hiddenFor($userId, $workspaceId), true);
    }

    /**
     * What the Team editor shows: the modules this member currently SEES.
     *
     * @return list<WorkspaceMemberModule>
     */
    public static function visibleFor(WorkspaceMembership $membership): array
    {
        $hidden = self::normalize($membership->hidden_modules);

        return array_values(array_filter(
            WorkspaceMemberModule::cases(),
            fn (WorkspaceMemberModule $m): bool => ! in_array($m, $hidden, true),
        ));
    }

    /**
     * Drops anything that is not a known module (a stale or forged value is
     * ignored, never trusted) and de-duplicates.
     *
     * @return list<WorkspaceMemberModule>
     */
    public static function normalize(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $modules = [];

        foreach ($stored as $value) {
            $module = is_string($value) ? WorkspaceMemberModule::tryFrom($value) : null;

            if ($module !== null) {
                $modules[$module->value] = $module;
            }
        }

        return array_values($modules);
    }

    public function forget(): void
    {
        if (app()->bound('request')) {
            request()->attributes->remove(self::ATTRIBUTE);
        }
    }

    /**
     * The answer the request's CustomerContext already holds (built from one joined
     * query by CustomerContextSnapshot), so asking costs no query. Null when the
     * context cannot vouch for this exact actor + Workspace — the caller then
     * reads the membership row itself.
     *
     * @return list<WorkspaceMemberModule>|null
     */
    private function fromResolvedContext(?\Illuminate\Http\Request $request, int $userId, int $workspaceId): ?array
    {
        $context = $request?->attributes->get('customerContext');

        // CustomerShellComposer binds the same resolved context into the container when it resolves one outside
        // the middleware, so a caller deeper than the HTTP layer is vouched for by the very same object.
        if (! $context instanceof \App\Library\Navigation\CustomerContext && app()->bound(\App\Library\Navigation\CustomerContext::class)) {
            $context = app(\App\Library\Navigation\CustomerContext::class);
        }

        if (! $context instanceof \App\Library\Navigation\CustomerContext || $context->userId !== $userId) {
            return null;
        }

        foreach ($context->workspaces as $candidate) {
            if ($candidate->id === $workspaceId) {
                return $candidate->hiddenModules;
            }
        }

        return null;
    }

    /**
     * @return list<WorkspaceMemberModule>
     */
    private function read(int $userId, int $workspaceId): array
    {
        $ownerId = Workspace::query()->whereKey($workspaceId)->value('owner_user_id');

        // Owner role wins over any membership row; an unknown Workspace has nobody to narrow.
        if ($ownerId === null || (int) $ownerId === $userId) {
            return [];
        }

        $stored = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereNull('removed_at')
            ->value('hidden_modules');

        // value() on a cast column returns the raw JSON string.
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return self::normalize($stored);
    }
}
