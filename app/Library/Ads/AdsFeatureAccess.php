<?php

namespace App\Library\Ads;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Navigation\MenuEntitlements;
use App\Models\Business;
use App\Models\Workspace;

/**
 * Meta Ads Module V1 (contract 24 §8, M10) — the ONE place that answers
 * "does this Business have the full Ads capability?".
 *
 *   full module  = ads_module OR google_ads_module OR meta_ads_module
 *   basic        = ads_basic_visibility
 *   any Ads      = basic OR full module
 *
 * `ads_module` is the provider-neutral canonical key; `google_ads_module` and
 * `meta_ads_module` are legacy synonyms that existing plan rows and overrides
 * still hold. No Ads gate (menu, tenancy trait, sync eligibility, mutation
 * services — Google's and Meta's alike) may test a provider-named key itself.
 *
 * Every answer comes from EntitlementManager (one bulk snapshot, a constant
 * read cost regardless of key count), the same eight-step precedence as
 * decide(). The menu variants read the request's already-built
 * MenuEntitlements snapshot, which fails closed on any key missing from
 * CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES — so ALL of
 * gatedFeatureKeys() are listed there.
 *
 * Known edge (documented, contract 24 §8): an explicit override that disables
 * `ads_module` does not remove a still-granted legacy key.
 */
final class AdsFeatureAccess
{
    public function __construct(private readonly EntitlementManager $entitlements)
    {
    }

    /**
     * @return list<string> the keys any one of which grants the full module
     */
    public static function fullModuleKeys(): array
    {
        return [
            PlatformFeature::AdsModule->value,
            PlatformFeature::GoogleAdsModule->value,
            PlatformFeature::MetaAdsModule->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function basicVisibilityKeys(): array
    {
        return [PlatformFeature::AdsBasicVisibility->value];
    }

    /**
     * @return list<string> basic OR full module
     */
    public static function anyAdsKeys(): array
    {
        return array_merge(self::basicVisibilityKeys(), self::fullModuleKeys());
    }

    /**
     * Every key the menu snapshot must carry (CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES).
     *
     * @return list<string>
     */
    public static function gatedFeatureKeys(): array
    {
        return self::anyAdsKeys();
    }

    /**
     * The implementation-availability floor for the bare `/ads` entry: some
     * Ads feature is Available in the registry.
     */
    public static function isImplementedAndAvailable(): bool
    {
        foreach (self::anyAdsKeys() as $key) {
            if (PlatformFeatureRegistry::isAvailable($key)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Menu-snapshot variants (no extra query)
    // ------------------------------------------------------------------

    public static function menuHasFullModule(MenuEntitlements $menu): bool
    {
        return self::menuAllowsAny($menu, self::fullModuleKeys());
    }

    public static function menuHasBasicVisibility(MenuEntitlements $menu): bool
    {
        return self::menuAllowsAny($menu, self::basicVisibilityKeys());
    }

    public static function menuHasAnyAds(MenuEntitlements $menu): bool
    {
        return self::menuAllowsAny($menu, self::anyAdsKeys());
    }

    /**
     * @param  list<string>  $keys
     */
    private static function menuAllowsAny(MenuEntitlements $menu, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($menu->allows($key)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Direct variants (one Workspace + Business + actor)
    // ------------------------------------------------------------------

    /**
     * @param  int  $actorUserId  audit-only; 0 for a background run
     */
    public function hasFullModule(Workspace $workspace, Business $business, int $actorUserId): bool
    {
        return $this->anyAllowed($workspace, $business, self::fullModuleKeys(), $actorUserId);
    }

    public function hasBasicVisibility(Workspace $workspace, Business $business, int $actorUserId): bool
    {
        return $this->anyAllowed($workspace, $business, self::basicVisibilityKeys(), $actorUserId);
    }

    public function hasAnyAds(Workspace $workspace, Business $business, int $actorUserId): bool
    {
        return $this->anyAllowed($workspace, $business, self::anyAdsKeys(), $actorUserId);
    }

    /**
     * A Workspace/Business mismatch is "not entitled", never an error the
     * caller can tell apart.
     *
     * @param  list<string>  $keys
     */
    private function anyAllowed(Workspace $workspace, Business $business, array $keys, int $actorUserId): bool
    {
        try {
            $decisions = $this->entitlements->snapshotBusinessFeatureDecisions($workspace, $business, $keys, $actorUserId);
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }

        foreach ($decisions as $decision) {
            if ($decision->allowed) {
                return true;
            }
        }

        return false;
    }
}
