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
     *
     * Fails closed rather than ever returning a fixed amount with a null
     * currency: `CatalogItemLocationOverrideManager` refuses to create
     * that combination going forward, but this resolver does not trust
     * that as its only safeguard — corrupt or legacy data with a non-null
     * `price_minor_override` against a quote-only (null-currency)
     * CatalogItem is refused here too, never silently resolved.
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

        $override = CatalogItemLocationOverride::query()
            ->where('catalog_item_id', $freshItem->id)
            ->where('business_location_id', $freshLocation->id)
            ->first();

        return $this->resolveLoaded($freshItem, $freshLocation, $override);
    }

    /**
     * The same refusals and the same price order as resolve(), over rows the
     * caller has ALREADY read — so a list page can resolve every item of a
     * Location from two bulk reads instead of several queries per item. This
     * is the one place the rule lives: resolve() delegates here, so the two
     * can never drift apart.
     *
     * Read-only. The item and Location must belong to the same Business and
     * the override row (when given) must be for exactly this item and
     * Location, or it refuses. It does NOT re-check which Business the caller
     * is acting for: a caller that loaded `$item` through a Business-scoped
     * query (CatalogLocationOfferReader) has already made that decision; one
     * that has not must use resolve().
     */
    public function resolveLoaded(CatalogItem $item, BusinessLocation $location, ?CatalogItemLocationOverride $override): CatalogItemEffectivePrice
    {
        if ((int) $location->business_id !== (int) $item->business_id) {
            throw new CatalogRuleException('That Location does not belong to this Business.');
        }

        if ($override !== null
            && ((int) $override->catalog_item_id !== (int) $item->id || (int) $override->business_location_id !== (int) $location->id)) {
            throw new CatalogRuleException('That override does not belong to this catalog item and Location.');
        }

        if ($item->isArchived()) {
            throw new CatalogRuleException('That catalog item is archived.');
        }

        if ($override !== null && ! $override->is_enabled) {
            throw new CatalogRuleException('That catalog item is not offered at this Location.');
        }

        $priceMinor = $override?->price_minor_override ?? $item->price_minor;

        if ($priceMinor !== null && $item->currency_code === null) {
            throw new CatalogRuleException('That catalog item has a price with no currency — refusing to resolve a corrupt price.');
        }

        return new CatalogItemEffectivePrice(
            priceMinor: $priceMinor,
            currencyCode: $priceMinor !== null ? $item->currency_code : null,
            isQuoteOnly: $priceMinor === null,
        );
    }
}
