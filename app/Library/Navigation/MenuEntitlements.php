<?php

namespace App\Library\Navigation;

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
