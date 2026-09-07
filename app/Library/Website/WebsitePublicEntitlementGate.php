<?php

namespace App\Library\Website;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Website\WebsiteStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;

/**
 * Website Generation + Hosting Slice A contract §26.4 — the bounded,
 * 60-second cached public entitlement gate. Public rendering has no
 * browser session and must never call Auth::id() or fabricate an actor
 * (contract §26.3) — (int) $business->customer_id, the Business's real
 * persistence owner, is used instead, mirroring the exact precedent B4
 * already established.
 *
 * Two independent layers, both mandatory: cheap always-fresh state
 * checks (never cached — negligible cost, already-loaded columns), and
 * a cached EntitlementManager::decide() result (60 seconds). A snapshot-
 * cache hit (WebsiteSnapshotCache) must NEVER be returned without this
 * gate passing first, on every request, whether the entitlement result
 * itself came from cache or from a fresh decide() call.
 */
final class WebsitePublicEntitlementGate
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    public function allows(Website $website): bool
    {
        if ($website->status !== WebsiteStatus::Published || $website->published_revision_id === null) {
            return false;
        }

        $business = $website->business;

        if ($business === null || $business->status !== BusinessStatus::Active) {
            return false;
        }

        $workspace = $business->workspace;

        if ($workspace === null || ! $workspace->is_active) {
            return false;
        }

        $cacheKey = "website_public_entitlement_{$business->id}";

        return (bool) Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($workspace, $business) {
            $decision = $this->entitlementManager->decide(
                $workspace,
                $business,
                PlatformFeature::WebsiteGeneration->value,
                (int) $business->customer_id,
            );

            return $decision->allowed;
        });
    }
}
