<?php

namespace App\Library\Catalog;

use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\CatalogItemLocationOverride;

/**
 * Implementation Contract 16 §5.2/§6 — the ONE place effective-price
 * resolution lives, "never duplicated or bypassed, including by
 * `PackageSnapshotService`" (§5.2). Not a numeric fallback helper alone:
 * before resolving anything it enforces the enabled/active invariants
 * §5.2 requires, in the exact order §6 requires them (a data-integrity
 * refusal before any ACL concern is even reachable).
 *
 * Read-only: this class never locks or mutates anything. `§7`'s
 * `lockForUpdate()` discipline belongs to the write paths that can change
 * the state this class reads (`CatalogItemManager`,
 * `CatalogItemLocationOverrideManager`) and, in Sub-slice D, to
 * `PackageSnapshotService::snapshot()`'s own locked read immediately
 * before it captures a snapshot — never to this resolver, which callers
 * may use for an ordinary, un-locked read at any other time.
 *
 * NO CONTROLLER, ROUTE, OR VIEW CONSUMES THIS CLASS YET (§12.C).
 */
final class CatalogItemPricingResolver
{
    /**
     * Refuses, in order: the `CatalogItem` does not belong to the given
     * `Business` (a foreign object, however it was obtained); the
     * `BusinessLocation` does not belong to that SAME Business (§6's
     * FK-domain-integrity check, checked before any ACL concern —
     * `LocationAccessGuard` is an HTTP-boundary concern this resolver does
     * not perform at all); the item is archived; an override row exists
     * with `is_enabled = false` ("not offered at this Location" — a
     * business-rule refusal, distinct from the two data-integrity
     * refusals above, but checked only once those have already passed).
     *
     * Only past every refusal does it resolve a price, in exactly this
     * order (§5.2): a non-null `price_minor_override` on the Location's
     * override row; else the CatalogItem's own Business-wide
     * `price_minor`; else no fixed price exists at all (quote-only).
     * There is no currency override (§5.2) — whenever a fixed price
     * resolves, its currency is always the CatalogItem's own
     * `currency_code`; no FX, no per-Location divergence.
     */
    public function resolve(Business $business, CatalogItem $item, BusinessLocation $location): CatalogItemEffectivePrice
    {
        $freshItem = CatalogItem::query()->whereKey($item->id)->first();

        if ($freshItem === null || (int) $freshItem->business_id !== (int) $business->id) {
            throw new CatalogRuleException('That catalog item does not belong to this Business.');
        }

        $freshLocation = BusinessLocation::query()->whereKey($location->id)->first();

        if ($freshLocation === null || (int) $freshLocation->business_id !== (int) $freshItem->business_id) {
            throw new CatalogRuleException('That Location does not belong to this Business.');
        }

        if ($freshItem->isArchived()) {
            throw new CatalogRuleException('That catalog item is archived.');
        }

        $override = CatalogItemLocationOverride::query()
            ->where('catalog_item_id', $freshItem->id)
            ->where('business_location_id', $freshLocation->id)
            ->first();

        if ($override !== null && ! $override->is_enabled) {
            throw new CatalogRuleException('That catalog item is not offered at this Location.');
        }

        $priceMinor = $override?->price_minor_override ?? $freshItem->price_minor;

        return new CatalogItemEffectivePrice(
            priceMinor: $priceMinor,
            currencyCode: $priceMinor !== null ? $freshItem->currency_code : null,
            isQuoteOnly: $priceMinor === null,
        );
    }
}
