<?php

namespace App\Library\Dashboard;

use Carbon\CarbonImmutable;

/**
 * Unified Business Home §14 (H-5) — one thing that actually happened to this
 * Business, as a persisted row proves it.
 *
 * The text is finished, factual and past tense; nothing here is inferred and
 * nothing is an "activity event" this application invented. The DESTINATION is
 * carried as an intent — route, parameters, permissions, feature — rather than
 * a URL, because whether this actor may open it is DashboardLinkGate's
 * decision and nobody else's. An item whose destination the actor cannot open
 * still renders: the fact happened either way (§14).
 */
final class RecentWorkItem
{
    /**
     * @param  array<int, string>  $routeParameters  beyond the Business scope, when businessScoped
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $text,
        public readonly CarbonImmutable $at,
        public readonly string $routeName,
        public readonly array $routeParameters = [],
        public readonly array $permissions = [],
        public readonly ?string $featureKey = null,
        public readonly bool $businessScoped = true,
    ) {
    }
}
