<?php

namespace App\Library\Website\Setup;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Catalog\CatalogFeatureList;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogMoney;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Library\Website\Setup\Exceptions\InvalidAnswerException;
use App\Models\Business;
use App\Models\CatalogItem;

/**
 * Website setup does NOT own a package model. Packages are the
 * Business's canonical Packages & Products rows (`catalog_items`); this
 * class is the wizard's only bridge to them:
 *
 *  - reads the Business's active packages so setup can offer them
 *    ("We found your existing packages");
 *  - writes create/edit made on the setup screen straight through
 *    CatalogItemManager (the one catalog writer), so a package created here
 *    appears in Packages & Products immediately and an edit made here is the
 *    canonical edit;
 *  - returns the selection as bare uids. The questionnaire answer stores
 *    ONLY `[{uid}]` — never a name or a price — so "Edit setup answers" can
 *    never overwrite a later catalog edit with a stale copy.
 */
final class WebsiteSetupPackageManager
{
    public function __construct(private readonly CatalogItemManager $catalog)
    {
    }

    /**
     * @return \Illuminate\Support\Collection<int, CatalogItem> active items, catalog order
     */
    public function activeItems(Business $business): \Illuminate\Support\Collection
    {
        return CatalogItem::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->orderBy('position')
            ->get();
    }

    /**
     * Rows for the setup screen, live from the catalog. Selected packages
     * come first in the owner's order; every other active package follows
     * unchecked. A setup that has never answered this step includes every
     * existing package by default.
     *
     * @param  mixed  $answer  the stored selection (`[{uid}]`) or null when never answered
     * @return array<int, array{item: CatalogItem, included: bool, prose: ?string, features: array<int, string>, price: string}>
     */
    public function rowsFor(Business $business, mixed $answer): array
    {
        $items = $this->activeItems($business)->keyBy('uid');
        $selectedUids = is_array($answer)
            ? array_values(array_filter(array_map(fn ($e) => is_array($e) ? ($e['uid'] ?? null) : null, $answer)))
            : null;

        $ordered = [];
        foreach ($selectedUids ?? [] as $uid) {
            if (isset($items[$uid])) {
                $ordered[] = [$items[$uid], true];
                unset($items[$uid]);
            }
        }
        foreach ($items as $item) {
            $ordered[] = [$item, $selectedUids === null];
        }

        return array_map(function (array $pair) {
            [$item, $included] = $pair;
            [$prose, $features] = CatalogFeatureList::split($item->description);

            return [
                'item' => $item,
                'included' => $included,
                'prose' => $prose,
                'features' => $features,
                'price' => CatalogMoney::toInput($item->price_minor, $item->currency_code),
            ];
        }, $ordered);
    }

    /**
     * Applies the posted package rows to the canonical catalog and returns
     * the selection (`[{uid}]`, owner's order).
     *
     * @param  array<int, array<string, mixed>>  $rows  uid?, include, name, description, price, currency_code, features[], featured
     * @return array<int, array{uid: string}>
     *
     * @throws InvalidAnswerException
     */
    public function sync(Business $business, array $rows, ?int $actorUserId = null): array
    {
        $selection = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $uid = trim((string) ($row['uid'] ?? ''));
            $included = $uid === '' ? true : ! empty($row['include']);

            if ($uid === '' && trim((string) ($row['name'] ?? '')) === '') {
                continue; // a blank "Add package" spare row
            }

            if (! $included) {
                continue;
            }

            try {
                $attributes = $this->attributesFrom($business, $row);

                if ($uid === '') {
                    $item = $this->catalog->create($business, ['type' => 'package'] + $attributes, $actorUserId);
                } else {
                    $item = $this->ownedActiveItem($business, $uid);

                    if ($this->differs($item, $attributes)) {
                        $item = $this->catalog->update($business, $item, $attributes);
                    }
                }
            } catch (CatalogRuleException $e) {
                throw new InvalidAnswerException($e->getMessage());
            }

            $selection[] = ['uid' => (string) $item->uid];
        }

        return $selection;
    }

    private function ownedActiveItem(Business $business, string $uid): CatalogItem
    {
        $item = CatalogItem::query()
            ->where('business_id', $business->id)
            ->where('uid', $uid)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->first();

        if ($item === null) {
            throw new InvalidAnswerException('One of the selected packages is no longer available.');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFrom(Business $business, array $row): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new InvalidAnswerException('Every package needs a name.');
        }

        $priceText = trim((string) ($row['price'] ?? ''));
        $currency = strtoupper(trim((string) ($row['currency_code'] ?? '')));
        if ($currency === '' && $priceText !== '') {
            $currency = strtoupper((string) ($business->currency_code ?: 'USD'));
        }

        $priceMinor = CatalogMoney::toMinor($priceText, $priceText !== '' ? $currency : null);

        $features = array_values(array_filter(
            array_map(fn ($f) => trim((string) $f), (array) ($row['features'] ?? [])),
            fn ($f) => $f !== ''
        ));

        return [
            'name' => $name,
            'description' => CatalogFeatureList::join((string) ($row['description'] ?? ''), $features),
            'price_minor' => $priceMinor,
            'currency_code' => $priceMinor !== null ? $currency : null,
            'featured' => ! empty($row['featured']),
        ];
    }

    private function differs(CatalogItem $item, array $attributes): bool
    {
        return $item->name !== $attributes['name']
            || ($item->description ?? null) !== ($attributes['description'] ?? null)
            || $item->price_minor !== $attributes['price_minor']
            || ($item->currency_code ?? null) !== ($attributes['currency_code'] ?? null)
            || (bool) $item->featured !== (bool) $attributes['featured'];
    }
}
