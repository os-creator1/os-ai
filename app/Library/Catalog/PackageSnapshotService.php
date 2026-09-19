<?php

namespace App\Library\Catalog;

use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\PackageSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 16 §5.3 / §6 / §7 / §12.D / §18.D — the canonical
 * immutable snapshot service for Packages & Products.
 *
 * Captures an immutable transactional snapshot of a CatalogItem at a specific
 * BusinessLocation under an exclusive database lock (lockForUpdate()). Slice 17
 * (Proposals, Contracts, E-Signatures) and future booking flows consume this
 * service so a later catalog edit or price override never retroactively alters
 * a past transactional document.
 *
 * NO HTTP AUTHORIZATION, ENTITLEMENT CHECK, OR LOCATION ACL IS PERFORMED HERE.
 * That is intentional (§6, §12.D). Callers are responsible for tenant
 * authorization, customer capability, platform entitlement, and Location ACL
 * before creating a snapshot. This service enforces only data and domain
 * integrity under §7's strict serialization sequence.
 *
 * IMMUTABLE WRITE-ONCE DISCIPLINE (§5.3):
 * Every call creates exactly one new PackageSnapshot row and commits it.
 * No method on this service or model permits updating an existing row, and no
 * production code path may call update()/save() on one after insertion.
 */
final class PackageSnapshotService
{
    public function __construct(
        private readonly CatalogItemPricingResolver $pricingResolver,
    ) {
    }

    /**
     * Creates an immutable PackageSnapshot under §7's locked sequence.
     *
     * @param  CatalogItem  $item  The catalog item to snapshot
     * @param  BusinessLocation  $location  The Location where the transaction occurs (REQUIRED, never nullable)
     * @param  User|null  $actor  The optional staff or customer User who initiated the snapshot (null for public/system flows)
     * @param  int|null  $explicitPriceMinor  Required ONLY for quote-only items; forbidden when a canonical fixed price exists
     *
     * @throws CatalogRuleException When any domain-integrity or pricing invariant is violated
     */
    public function snapshot(
        CatalogItem $item,
        BusinessLocation $location,
        ?User $actor = null,
        ?int $explicitPriceMinor = null,
    ): PackageSnapshot {
        return DB::transaction(function () use ($item, $location, $actor, $explicitPriceMinor) {
            // 1. Re-read and lock the authoritative CatalogItem from persistence
            //    rather than trusting potentially stale caller-supplied fields (§7).
            $lockedItem = CatalogItem::query()->whereKey($item->id)->lockForUpdate()->first();

            if ($lockedItem === null) {
                throw new CatalogRuleException('That catalog item does not exist.');
            }

            // 2. Re-derive the authoritative Business from the locked item.
            $business = Business::query()->whereKey($lockedItem->business_id)->first();

            if ($business === null) {
                throw new CatalogRuleException('That Business no longer exists.');
            }

            // 3. Re-derive/verify the BusinessLocation from persistence and prove it
            //    belongs to the same Business as the locked item (§6 FK-domain-integrity).
            $lockedLocation = BusinessLocation::query()->whereKey($location->id)->first();

            if ($lockedLocation === null || (int) $lockedLocation->business_id !== (int) $lockedItem->business_id) {
                throw new CatalogRuleException('That Location does not belong to this Business.');
            }

            // 4. Refuse an archived/inactive CatalogItem (§5.2, §7).
            if ($lockedItem->isArchived()) {
                throw new CatalogRuleException('That catalog item is archived.');
            }

            // 5. Resolve Location enablement and canonical pricing using Sub-slice C's
            //    pricing resolver (§5.2, §6). This also verifies the item is enabled at
            //    the Location and that price/currency integrity holds.
            $effectivePrice = $this->pricingResolver->resolve($business, $lockedItem, $lockedLocation);

            // 6. Enforce price and currency rules per Contract 16 §5.3, §6:
            //    - Fixed price: explicitPriceMinor MUST be null; explicit price is a refusal.
            //      Currency comes from the CatalogItem's own captured currency_code.
            //    - Quote-only: explicitPriceMinor is REQUIRED; missing or negative is refused.
            //      Currency comes from the Business's current currency_code (§5.3).
            if (! $effectivePrice->isQuoteOnly) {
                if ($explicitPriceMinor !== null) {
                    throw new CatalogRuleException('An explicit price cannot be supplied for an item that has a canonical fixed price.');
                }

                $priceMinor = (int) $effectivePrice->priceMinor;
                $currencyCode = (string) $effectivePrice->currencyCode;
            } else {
                if ($explicitPriceMinor === null) {
                    throw new CatalogRuleException('An explicit price is required for a quote-only catalog item.');
                }

                if ($explicitPriceMinor < 0) {
                    throw new CatalogRuleException('The explicit price cannot be negative.');
                }

                $priceMinor = $explicitPriceMinor;
                $currencyCode = (string) $business->currency_code;

                if (trim($currencyCode) === '') {
                    throw new CatalogRuleException('The Business does not have a currency configured.');
                }
            }

            // 7. Insert the immutable package_snapshots row from one coherent locked state.
            $snapshot = PackageSnapshot::create([
                'business_id' => (int) $business->id,
                'catalog_item_id' => (int) $lockedItem->id,
                'business_location_id' => (int) $lockedLocation->id,
                'name_at_snapshot' => $lockedItem->name,
                'description_at_snapshot' => $lockedItem->description,
                'price_minor_at_snapshot' => $priceMinor,
                'currency_code_at_snapshot' => $currencyCode,
                'schema_version' => 1,
                'created_by_user_id' => $actor?->id,
            ]);

            return $snapshot->refresh();
        });
    }
}
