<?php

namespace App\Library\Catalog;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Catalog\CatalogItemType;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\CatalogItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 16 §12.B — the ONE canonical service boundary for
 * a Business's Packages & Products catalog: create/update, archive/
 * reactivate, and deterministic reorder. Mirrors
 * `App\Library\Crm\CrmPipelineService` (§3.3's own cited precedent) for its
 * lock-then-mutate transaction shape and its manual, customer-facing
 * validation-exception style, and mirrors
 * `EloquentBusinessLocationRepository` for the archive()/reactivate()-only
 * write path on `lifecycle_state`/`archived_at` — both columns are already
 * excluded from `CatalogItem::$fillable` (Sub-slice A), so this class is the
 * only route to changing them.
 *
 * NO CONTROLLER, ROUTE, OR VIEW CONSUMES THIS CLASS YET. It sits entirely
 * behind Sub-slice A's still-`Planned` `PlatformFeature::PackagesProducts`
 * gate; Sub-slice E wires the capability/entitlement/tenancy checks (§6) in
 * front of it. This class enforces none of those three gates itself — its
 * own, narrower job is the two things §6 says a domain manager must still
 * do regardless of what authorized it: never trust a foreign `CatalogItem`
 * object a caller supplies, and re-derive/validate that it genuinely
 * belongs to the `Business` the caller claims to be acting on. Every method
 * that receives an existing `CatalogItem` re-loads it fresh, under lock, by
 * primary key alone, and compares its own persisted `business_id` against
 * the given `Business` — never trusting any other field the caller's copy
 * of the model happened to carry.
 *
 * CONCURRENCY (§7). Every mutation that can change snapshot-relevant state
 * — create, update, archive, reactivate, reorder — takes `lockForUpdate()`
 * before writing, so `PackageSnapshotService` (Sub-slice D), which takes the
 * same lock before it reads, can never observe a half-applied combination
 * of old/new price, lifecycle or currency.
 */
final class CatalogItemManager
{
    public const NAME_MAX = 160;

    public const DESCRIPTION_MAX = 5000;

    /**
     * Contract §12.B — Business-wide create. `$actorUserId` is the optional
     * customer/staff actor recorded on `created_by_user_id`; `null` for a
     * system-authored row, mirroring `CatalogItem.created_by_user_id`'s own
     * nullable design (§5.1).
     */
    public function create(Business $business, array $attributes, ?int $actorUserId = null): CatalogItem
    {
        $validated = $this->validate(
            $attributes['type'] ?? null,
            $attributes['name'] ?? null,
            $attributes['description'] ?? null,
            $attributes['price_minor'] ?? null,
            $attributes['currency_code'] ?? null,
        );

        return DB::transaction(function () use ($business, $validated, $actorUserId) {
            // A brand-new row has no prior snapshot-relevant state (§7) to
            // race against, so this is not the §7 serialization lock — it
            // exists purely so two concurrent creates for the same Business
            // cannot both compute the same "next" position. Locking the
            // active set is the same mechanism reorder() already needs.
            $this->lockActiveItems($business);

            $nextPosition = (int) (CatalogItem::query()->where('business_id', $business->id)->max('position') ?? -1) + 1;

            $item = CatalogItem::create($validated + [
                'business_id' => $business->id,
                'position' => $nextPosition,
                'created_by_user_id' => $actorUserId,
            ]);

            // Refreshed so the returned model carries the DB-computed
            // defaults (lifecycle_state/archived_at) rather than an
            // in-memory instance that never read them back.
            return $item->refresh();
        });
    }

    /**
     * Contract §12.B — Business-wide update. Any of the five editable
     * fields may be omitted; an omitted field keeps its current persisted
     * value, and the co-nullable price/currency invariant (§5.1) is
     * re-checked against the resulting MERGED state, never against only the
     * fields supplied in this call — so a caller cannot clear `price_minor`
     * alone and leave a stale `currency_code` behind, or vice versa.
     */
    public function update(Business $business, CatalogItem $item, array $attributes): CatalogItem
    {
        return DB::transaction(function () use ($business, $item, $attributes) {
            $locked = $this->lockItemForBusiness($business, $item);

            $validated = $this->validate(
                array_key_exists('type', $attributes) ? $attributes['type'] : $locked->type?->value,
                array_key_exists('name', $attributes) ? $attributes['name'] : $locked->name,
                array_key_exists('description', $attributes) ? $attributes['description'] : $locked->description,
                array_key_exists('price_minor', $attributes) ? $attributes['price_minor'] : $locked->price_minor,
                array_key_exists('currency_code', $attributes) ? $attributes['currency_code'] : $locked->currency_code,
            );

            $locked->fill($validated);
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * Idempotent: an already-archived item is returned unchanged, mirroring
     * `BusinessLocationManager::archiveLocation()`'s own precedent — never
     * an exception for "ensure this is archived" being asked twice.
     */
    public function archive(Business $business, CatalogItem $item): CatalogItem
    {
        return DB::transaction(function () use ($business, $item) {
            $locked = $this->lockItemForBusiness($business, $item);

            if ($locked->isArchived()) {
                return $locked;
            }

            $locked->forceFill([
                'lifecycle_state' => CatalogItemLifecycleState::Archived,
                'archived_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    /** Idempotent, mirroring `BusinessLocationManager::reactivateLocation()`. */
    public function reactivate(Business $business, CatalogItem $item): CatalogItem
    {
        return DB::transaction(function () use ($business, $item) {
            $locked = $this->lockItemForBusiness($business, $item);

            if ($locked->isActive()) {
                return $locked;
            }

            $locked->forceFill([
                'lifecycle_state' => CatalogItemLifecycleState::Active,
                'archived_at' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Contract §7 — mirrors `CrmPipelineService::applyOrder()` exactly: the
     * caller submits the complete ordered list of the Business's ACTIVE
     * catalog-item uids, every row is rewritten to its 0-based array index
     * in one transaction, and the full active set is locked
     * (`lockForUpdate()`, scoped by `business_id`) for the duration of the
     * rewrite — itself one instance of §7's general serialization rule,
     * since a reorder is a catalog-item mutation.
     *
     * Refuses, as one combined check (mirroring `CrmPipelineService`'s own
     * `array_diff`-based validation): the list omits an active item, names
     * an id twice, or names an id that is archived or belongs to a
     * different Business entirely (an id from a foreign Business is simply
     * absent from this Business's own locked, scoped set).
     *
     * @param  list<string>  $orderedUids  every active catalog-item uid of
     *                                     this Business, exactly once
     */
    public function reorder(Business $business, array $orderedUids): void
    {
        DB::transaction(function () use ($business, $orderedUids) {
            $items = $this->lockActiveItems($business)->keyBy('uid');

            if (count($orderedUids) !== $items->count()
                || count(array_unique($orderedUids)) !== count($orderedUids)
                || array_diff($orderedUids, $items->keys()->all()) !== []) {
                throw new CatalogRuleException('The order must list every active catalog item of this Business exactly once.');
            }

            foreach ($orderedUids as $position => $uid) {
                /** @var CatalogItem $item */
                $item = $items[$uid];

                if ($item->position !== $position) {
                    $item->forceFill(['position' => $position])->save();
                }
            }
        });
    }

    /**
     * Contract §6/§7 — never trusts the caller's copy of `$item`: re-loads
     * it fresh, under lock, by primary key alone, then verifies its own
     * persisted `business_id` matches the given `Business`. A foreign
     * `CatalogItem` (a different Business's row, however it was obtained)
     * is refused here with the same message a nonexistent id would get —
     * this manager never distinguishes "wrong Business" from "doesn't
     * exist" in its own error text, leaving existence-disclosure policy to
     * the HTTP layer that will front it (§6).
     */
    private function lockItemForBusiness(Business $business, CatalogItem $item): CatalogItem
    {
        $locked = CatalogItem::query()->whereKey($item->id)->lockForUpdate()->first();

        if ($locked === null || (int) $locked->business_id !== (int) $business->id) {
            throw new CatalogRuleException('That catalog item does not belong to this Business.');
        }

        return $locked;
    }

    /** @return Collection<int, CatalogItem> */
    private function lockActiveItems(Business $business): Collection
    {
        return CatalogItem::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Contract §5.1's canonical validation, the one place it lives:
     * `type` is `product`/`package`; `name` is 1-{self::NAME_MAX} trimmed
     * characters; `description` is nullable, at most
     * {self::DESCRIPTION_MAX} trimmed characters (mirroring
     * `UpdateBusinessRequest`'s own `description` bound exactly);
     * `price_minor`/`currency_code` are both null (a quote-only item) or
     * both set, never one alone; `currency_code` is normalized upper-case
     * and exactly 3 characters, mirroring `UpdateBusinessRequest`'s own
     * `currency_code` rule (`required|string|size:3` plus an upper-casing
     * `prepareForValidation()` step) verbatim — no ISO-4217 lookup table is
     * consulted, because none is consulted for a Business's own
     * `currency_code` either.
     *
     * Returns only the five known-good keys — `lifecycle_state`/
     * `archived_at` can never reach `fill()`/`create()` through this
     * method, however the caller's own `$attributes` array was shaped.
     *
     * @return array{type: string, name: string, description: ?string, price_minor: ?int, currency_code: ?string}
     */
    private function validate(mixed $type, mixed $name, mixed $description, mixed $priceMinor, mixed $currencyCode): array
    {
        $typeEnum = is_string($type) ? CatalogItemType::tryFrom($type) : null;

        if ($typeEnum === null) {
            throw new CatalogRuleException('Choose product or package.');
        }

        $name = trim((string) $name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new CatalogRuleException('Use a name of 1 to ' . self::NAME_MAX . ' characters.');
        }

        $description = $description !== null ? trim((string) $description) : null;

        if ($description === '') {
            $description = null;
        }

        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX) {
            throw new CatalogRuleException('Use a description of at most ' . self::DESCRIPTION_MAX . ' characters.');
        }

        $priceMinor = $priceMinor !== null && $priceMinor !== '' ? (int) $priceMinor : null;
        $currencyCode = $currencyCode !== null && $currencyCode !== '' ? strtoupper(trim((string) $currencyCode)) : null;

        if ($priceMinor !== null && $priceMinor < 0) {
            throw new CatalogRuleException('The price cannot be negative.');
        }

        if (($priceMinor === null) !== ($currencyCode === null)) {
            throw new CatalogRuleException('Set both a price and a currency, or leave both blank for a quote-only item.');
        }

        if ($currencyCode !== null && strlen($currencyCode) !== 3) {
            throw new CatalogRuleException('Currency must be a 3-letter code.');
        }

        return [
            'type' => $typeEnum->value,
            'name' => $name,
            'description' => $description,
            'price_minor' => $priceMinor,
            'currency_code' => $currencyCode,
        ];
    }
}
