<?php

namespace App\Library\Navigation;

use App\Enums\Workspace\WorkspaceMemberModule;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Workspace;

/**
 * Slice 2A §6.4 — the navigation-facing carrier for entitlement decisions
 * EntitlementManager has already made.
 *
 * THIS CLASS HOLDS NO POLICY, and that is its entire point. RFC-004 §14's
 * eight-step precedence lives in EntitlementManager::decide() and its bulk
 * sibling snapshotBusinessFeatureDecisions(); a menu that re-derived any part
 * of it would be a second authority able to drift from the first, and the
 * drift would show up as a customer seeing an entry they cannot open, or
 * losing one they pay for. So this object only remembers answers.
 *
 * It is immutable and request-local: built once beside the CustomerContext
 * that names the selected Workspace and Business, then read as many times as
 * the menu needs. Reading it never touches the database.
 *
 * In the Account frame there is no selected Business to evaluate against, so
 * the object is built empty and every Business feature answers false — at a
 * cost of zero queries (§6.5).
 */
final class MenuEntitlements
{
    /**
     * @param  array<string, bool>  $allowed  featureKey => entitled
     */
    private function __construct(
        private readonly array $allowed,
        public readonly bool $evaluated,
        /** @var list<WorkspaceMemberModule> modules the owner switched off for THIS viewer */
        private readonly array $hiddenModules = [],
    ) {
    }

    /**
     * The Account frame, or any request with no Business in scope.
     *
     * Not an error state: "no Business selected" is a legitimate frame, and
     * answering every Business-scoped feature as not-applicable is the
     * correct answer there, not a degraded one.
     */
    public static function none(): self
    {
        return new self([], false);
    }

    /**
     * Ask EntitlementManager once for every feature the menu can gate on.
     *
     * The feature list is passed in rather than discovered here so the caller
     * — and its test — controls exactly what is asked, and so growing the
     * list cannot change the query count.
     *
     * @param  array<int, string>  $featureKeys
     */
    public static function forBusiness(
        EntitlementManager $entitlements,
        Workspace $workspace,
        Business $business,
        array $featureKeys,
        int $actorUserId,
    ): self {
        $decisions = $entitlements->snapshotBusinessFeatureDecisions(
            $workspace,
            $business,
            $featureKeys,
            $actorUserId,
        );

        $allowed = [];

        foreach ($decisions as $featureKey => $decision) {
            $allowed[(string) $featureKey] = $decision->allowed;
        }

        return new self($allowed, true);
    }

    /**
     * Per-member module visibility (Settings → Team → Visible modules).
     *
     * NOT a policy and NOT a grant: it can only turn answers OFF. Every feature
     * key a hidden module is entitled by is answered "not entitled" for this
     * viewer, so everything that already reads this snapshot — the sidebar, Home
     * cards and links, the assistant's tools, the tenancy trait's entitlement
     * step — follows without a second code path. Returns a new object; EntitlementManager's
     * own decisions, and every background caller that asks it directly, are
     * untouched, which is why hiding a module never stops it running.
     *
     * @param  list<WorkspaceMemberModule>  $hidden
     */
    public function narrowedBy(array $hidden): self
    {
        if ($hidden === []) {
            return $this;
        }

        $allowed = $this->allowed;

        foreach ($hidden as $module) {
            foreach ($module->featureKeys() as $featureKey) {
                if (array_key_exists($featureKey, $allowed)) {
                    $allowed[$featureKey] = false;
                }
            }
        }

        $merged = [];

        foreach (array_merge($this->hiddenModules, $hidden) as $module) {
            $merged[$module->value] = $module;
        }

        return new self($allowed, $this->evaluated, array_values($merged));
    }

    public function moduleHidden(WorkspaceMemberModule $module): bool
    {
        return in_array($module, $this->hiddenModules, true);
    }

    /**
     * Whether a route belongs to a module this viewer cannot see. Used by the
     * menu and the dashboard link gate, which share this one rule.
     */
    public function routeHidden(?string $routeName): bool
    {
        if ($this->hiddenModules === []) {
            return false;
        }

        $module = WorkspaceMemberModule::forRouteName($routeName);

        return $module !== null && $this->moduleHidden($module);
    }

    /**
     * @return list<WorkspaceMemberModule>
     */
    public function hiddenModules(): array
    {
        return $this->hiddenModules;
    }

    /**
     * Fail closed: a feature nobody asked about is not entitled.
     *
     * A menu builder that gates on a key this snapshot was never given would
     * otherwise silently show the entry to everyone, which is the exact
     * failure mode §6.1 forbids.
     */
    public function allows(string $featureKey): bool
    {
        return $this->allowed[$featureKey] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->allowed;
    }
}
