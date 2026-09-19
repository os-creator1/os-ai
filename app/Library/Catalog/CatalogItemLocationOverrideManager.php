<?php

namespace App\Library\Catalog;

use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\CatalogItemLocationOverride;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 16 §5.2/§7/§12.C — the ONE write path for a
 * catalog item's sparse per-Location deviations. No row for a given
 * (catalog_item_id, business_location_id) pair means the item is enabled
 * at every Location at the Business-wide default price (§5.2's locked
 * sparse-default semantics) — this manager only ever writes a row when a
 * caller actually asks for a deviation from that default.
 *
 * NO CONTROLLER, ROUTE, OR VIEW CONSUMES THIS CLASS YET. It sits behind
 * Sub-slice A's still-`Planned` `PlatformFeature::PackagesProducts` gate.
 * Authorization (tenancy, the `packages_products` capability, platform
 * entitlement, and `LocationAccessGuard`'s Location ACL) is entirely an
 * HTTP-boundary concern Sub-slice E will front this class with (§6) —
 * this manager performs none of it. Its own, narrower and non-negotiable
 * duty is domain integrity: never write an override row for a foreign
 * `CatalogItem`, and never write one for a `BusinessLocation` that does
 * not belong to that same `CatalogItem`'s own Business. Every method
 * re-derives both fresh from persistence and re-checks that ownership
 * itself, never trusting either object a caller supplies.
 *
 * CONCURRENCY (§7). Before touching an override row this manager takes
 * `lockForUpdate()` on the authoritative `catalog_items` row — the same
 * lock `CatalogItemManager`'s own mutations take — so an override write
 * can never race a concurrent item edit/archive/reactivate, and two
 * concurrent override writes for the SAME item (any Location) are
 * themselves fully serialized by that one lock. The `catalog_item_
 * overrides_item_location_unique` DB constraint (§5.2/§7) is kept only as
 * a defense-in-depth backstop for the first-create race that lock
 * discipline should already make unreachable — never the primary
 * mechanism.
 *
 * SPARSE INVARIANT (§5.2). "Holds ONLY Locations that deviate from the
 * Business-wide default" is enforced here, not merely described: after
 * merging a caller's partial update onto the row's current (or sparse-
 * default) state, a resulting state of `(is_enabled: true,
 * price_minor_override: null)` IS the canonical default, so no row may
 * persist in that shape — an existing row is deleted, and a would-be
 * insert is skipped entirely. Every public method therefore returns
 * `?CatalogItemLocationOverride`: `null` is the honest representation of
 * "canonical sparse default; there is no override row," never an invented
 * tombstone/no-op row.
 */
final class CatalogItemLocationOverrideManager
{
    private const DUPLICATE_KEY_CONSTRAINT = 'catalog_item_overrides_item_location_unique';

    /**
     * Location-specific enable/disable (§5.2/§12.C). `null` for the item's
     * `price_minor_override` is left untouched — this is a thin,
     * single-field wrapper over the shared upsert in setOverride(). Returns
     * `null` when the merged result is the canonical sparse default (see
     * the class docblock) — most commonly `setEnabled($b, $i, $l, true)`
     * clearing the last remaining deviation.
     */
    public function setEnabled(Business $business, CatalogItem $item, BusinessLocation $location, bool $isEnabled): ?CatalogItemLocationOverride
    {
        return $this->setOverride($business, $item, $location, ['is_enabled' => $isEnabled]);
    }

    /**
     * Location-specific price override (§5.2/§12.C). `null` clears any
     * previously-set override amount, falling back to the Business-wide
     * `CatalogItem::price_minor` (or quote-only, if that is itself null) —
     * `CatalogItemPricingResolver`'s own fallback chain. Returns `null`
     * when the merged result is the canonical sparse default.
     */
    public function setPriceOverride(Business $business, CatalogItem $item, BusinessLocation $location, ?int $priceMinorOverride): ?CatalogItemLocationOverride
    {
        return $this->setOverride($business, $item, $location, ['price_minor_override' => $priceMinorOverride]);
    }

    /**
     * Creates, updates, or deletes the sparse override row for one
     * (CatalogItem, BusinessLocation) pair. Either key in `$attributes`
     * may be omitted; an omitted key keeps its CURRENT value — the row's
     * persisted value if one already exists, else this table's own sparse
     * defaults (`is_enabled = true`, `price_minor_override = null`) —
     * mirroring `CatalogItemManager::update()`'s merged-state semantics
     * exactly, so a caller can never clear one field by omission while
     * accidentally reverting the other to a stale value.
     *
     * §5.2's sparse invariant is enforced on the MERGED result, not the
     * caller's raw input: if that result is `(true, null)` — the canonical
     * default — no row is written, and any existing row for this pair is
     * deleted instead. `null` is returned in that case; any other merged
     * result is persisted (created or updated) and that row is returned.
     */
    public function setOverride(Business $business, CatalogItem $item, BusinessLocation $location, array $attributes): ?CatalogItemLocationOverride
    {
        return DB::transaction(function () use ($business, $item, $location, $attributes) {
            $lockedItem = $this->lockItemForBusiness($business, $item);
            $lockedLocation = $this->locationOfBusiness($lockedItem, $location);

            $existing = CatalogItemLocationOverride::query()
                ->where('catalog_item_id', $lockedItem->id)
                ->where('business_location_id', $lockedLocation->id)
                ->first();

            $validated = $this->validate(
                $lockedItem,
                array_key_exists('is_enabled', $attributes) ? $attributes['is_enabled'] : ($existing->is_enabled ?? true),
                array_key_exists('price_minor_override', $attributes) ? $attributes['price_minor_override'] : ($existing->price_minor_override ?? null),
            );

            if ($this->isCanonicalDefault($validated)) {
                $existing?->delete();

                return null;
            }

            if ($existing !== null) {
                $existing->fill($validated);
                $existing->save();

                return $existing->refresh();
            }

            return $this->createOverrideWithDuplicateBackstop($lockedItem, $lockedLocation, $validated);
        });
    }

    /**
     * §5.2 — "no row present means enabled at the Business-wide default":
     * the one shape a persisted row must never take.
     */
    private function isCanonicalDefault(array $validated): bool
    {
        return $validated['is_enabled'] === true && $validated['price_minor_override'] === null;
    }

    /**
     * The item-row lock already held by the caller (setOverride()) should
     * make a genuine duplicate-key race on this INSERT unreachable — any
     * other writer for this same CatalogItem blocks on that same lock
     * until this transaction commits. This is nonetheless kept as a real
     * defense-in-depth backstop (§7): if the unique constraint ever fires
     * anyway, resolve deterministically by updating the row the other
     * writer just committed rather than letting a 500 escape.
     */
    private function createOverrideWithDuplicateBackstop(CatalogItem $lockedItem, BusinessLocation $lockedLocation, array $validated): CatalogItemLocationOverride
    {
        try {
            $override = CatalogItemLocationOverride::create($validated + [
                'catalog_item_id' => $lockedItem->id,
                'business_location_id' => $lockedLocation->id,
            ]);

            return $override->refresh();
        } catch (QueryException $e) {
            if (! $this->isDuplicateRace($e)) {
                throw $e;
            }

            // Defense-in-depth path only (see the class docblock) — the
            // item-row lock this method is always called from within
            // should make this branch unreachable. $validated is not the
            // canonical default here (setOverride() already returned early
            // for that case before ever calling this method), so the row
            // the other writer just committed is updated to it, never
            // deleted.
            $override = CatalogItemLocationOverride::query()
                ->where('catalog_item_id', $lockedItem->id)
                ->where('business_location_id', $lockedLocation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $override->fill($validated);
            $override->save();

            return $override->refresh();
        }
    }

    /**
     * Contract §7 — never trusts the caller's copy of `$item`: re-loads it
     * fresh, under lock, by primary key alone, then verifies its own
     * persisted `business_id` matches the given `Business`, mirroring
     * `CatalogItemManager::lockItemForBusiness()` exactly.
     */
    private function lockItemForBusiness(Business $business, CatalogItem $item): CatalogItem
    {
        $locked = CatalogItem::query()->whereKey($item->id)->lockForUpdate()->first();

        if ($locked === null || (int) $locked->business_id !== (int) $business->id) {
            throw new CatalogRuleException('That catalog item does not belong to this Business.');
        }

        return $locked;
    }

    /**
     * Contract §6 — "the FK-domain-integrity check... before... consulted":
     * re-loads the Location fresh and proves it belongs to the SAME
     * Business as the already-locked catalog item. A cross-Business
     * Location (however it was obtained) is refused here with the same
     * message a nonexistent id would get, exactly mirroring how
     * `lockItemForBusiness()` treats a foreign `CatalogItem`. This is a
     * data-integrity refusal, never an ACL one — `LocationAccessGuard` is
     * an HTTP-boundary concern this manager does not perform (§6).
     */
    private function locationOfBusiness(CatalogItem $lockedItem, BusinessLocation $location): BusinessLocation
    {
        $locked = BusinessLocation::query()->whereKey($location->id)->first();

        if ($locked === null || (int) $locked->business_id !== (int) $lockedItem->business_id) {
            throw new CatalogRuleException('That Location does not belong to this Business.');
        }

        return $locked;
    }

    /**
     * §5.2 — "Location override changes the amount only, never the
     * currency": a non-null `price_minor_override` therefore requires the
     * locked `CatalogItem` to already have a canonical fixed-price
     * currency (its own `price_minor`/`currency_code` co-nullable
     * invariant, §5.1, means "has a currency" and "is not quote-only" are
     * the same fact). A quote-only item's Business currency is NEVER
     * substituted here — that would silently turn a quote-only item into a
     * half-defined fixed price, which Contract 16D's snapshot service
     * handles through its own, explicit quote-only path instead.
     */
    private function validate(CatalogItem $lockedItem, mixed $isEnabled, mixed $priceMinorOverride): array
    {
        if (! is_bool($isEnabled)) {
            throw new CatalogRuleException('Enabled must be true or false.');
        }

        $parsedPrice = $this->parsePriceMinorOverride($priceMinorOverride);

        if ($parsedPrice !== null && $lockedItem->currency_code === null) {
            throw new CatalogRuleException('This catalog item has no Business-wide price or currency yet, so it cannot have a Location price override.');
        }

        return [
            'is_enabled' => $isEnabled,
            'price_minor_override' => $parsedPrice,
        ];
    }

    /**
     * Same strict-parse rationale as `CatalogItemManager::
     * parsePriceMinor()`: this manager is a canonical domain validator
     * too, so a bare `(int)` cast — silently coercing "abc" to 0 or
     * `true` to 1 — is never acceptable here either. No floating-point
     * money parsing.
     */
    private function parsePriceMinorOverride(mixed $priceMinorOverride): ?int
    {
        if ($priceMinorOverride === null || $priceMinorOverride === '') {
            return null;
        }

        if (is_int($priceMinorOverride)) {
            $parsed = $priceMinorOverride;
        } elseif (is_string($priceMinorOverride) && preg_match('/^-?\d+$/', trim($priceMinorOverride)) === 1) {
            $digits = trim($priceMinorOverride);
            $magnitude = ltrim($digits, '-');

            if (strlen($magnitude) > strlen((string) PHP_INT_MAX)
                || (strlen($magnitude) === strlen((string) PHP_INT_MAX) && strcmp($magnitude, (string) PHP_INT_MAX) > 0)) {
                throw new CatalogRuleException('The price override is too large to store.');
            }

            $parsed = (int) $digits;
        } else {
            throw new CatalogRuleException('The price override must be a whole, non-negative number of minor currency units.');
        }

        if ($parsed < 0) {
            throw new CatalogRuleException('The price override cannot be negative.');
        }

        return $parsed;
    }

    private function isDuplicateRace(QueryException $e): bool
    {
        $driverErrorCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverErrorCode === 1062 && str_contains($e->getMessage(), self::DUPLICATE_KEY_CONSTRAINT);
    }
}
