<?php

namespace App\Library\Catalog;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\CatalogItemLocationOverride;
use Illuminate\Support\Collection;

/**
 * Implementation Contract 16 §5.2, §12.E — the READ side of the per-Location
 * catalog page: for one Location, every active catalog item and what the
 * Business is actually offering there.
 *
 * STRICTLY READ-ONLY. It writes nothing and locks nothing. Every mutation of
 * a catalog item or an override goes through `CatalogItemManager` /
 * `CatalogItemLocationOverrideManager`; this class only assembles what the
 * page displays.
 *
 * IT DOES NOT RE-DERIVE PRICING OR OFFERING. "Is this item offered here, and
 * at what price?" is answered ONLY by `CatalogItemPricingResolver`, the
 * single place that rule lives (§5.2). When the resolver refuses — the item
 * is disabled at this Location, or its data is corrupt — that refusal is
 * carried through as the row's reason and the row is shown as not offered.
 * Nothing here inspects `is_enabled` to decide a price or an offering; the
 * override row is read only so the form can be pre-filled with its current
 * values.
 *
 * AUTHORIZATION IS NOT DECIDED HERE. The caller has already passed tenancy,
 * capability, entitlement and `LocationAccessGuard` for this exact Location
 * (§6); this class trusts none of that to mean anything about data and never
 * widens it — it only ever reads items of the Business it was handed, and
 * the resolver re-verifies the item/Business/Location relationship itself.
 */
class CatalogLocationOfferReader
{
    public function __construct(private readonly CatalogItemPricingResolver $resolver)
    {
    }

    /**
     * @return Collection<int, array{
     *     item: CatalogItem,
     *     override: ?CatalogItemLocationOverride,
     *     isOffered: bool,
     *     price: ?CatalogItemEffectivePrice,
     *     reason: ?string,
     *     hasOverridePrice: bool,
     * }>
     */
    public function rowsFor(Business $business, BusinessLocation $location): Collection
    {
        $items = CatalogItem::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $overrides = CatalogItemLocationOverride::query()
            ->where('business_location_id', $location->id)
            ->whereIn('catalog_item_id', $items->pluck('id')->all())
            ->get()
            ->keyBy('catalog_item_id');

        return $items->map(function (CatalogItem $item) use ($business, $location, $overrides): array {
            $override = $overrides->get($item->id);

            try {
                $price = $this->resolver->resolve($business, $item, $location);
                $offered = true;
                $reason = null;
            } catch (CatalogRuleException $e) {
                $price = null;
                $offered = false;
                $reason = $e->getMessage();
            }

            return [
                'item' => $item,
                'override' => $override,
                'isOffered' => $offered,
                'price' => $price,
                'reason' => $reason,
                'hasOverridePrice' => $override?->price_minor_override !== null,
            ];
        })->values();
    }
}
