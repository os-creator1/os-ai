<?php

namespace App\Library\Documents\Editor;

use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogItemPricingResolver;
use App\Library\Catalog\CatalogMoney;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;

/**
 * Implementation Contract 17B — the editor's product picker. A read over the
 * Business's ACTIVE catalog (priced through the one CatalogItemPricingResolver,
 * at the document's Location) plus the "create a product here" shortcut, which
 * goes through the EXISTING CatalogItemManager::create() seam and so inherits
 * every catalog rule. Nothing here snapshots or prices a document line: that is
 * DocumentManager::addCatalogLine().
 */
final class DocumentCatalogPicker
{
    public function __construct(
        private readonly CatalogItemManager $items,
        private readonly CatalogItemPricingResolver $pricing,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(Business $business, BusinessDocument $document, string $search, int $limit = 30): array
    {
        $location = BusinessLocation::query()->where('business_id', $business->id)->find($document->business_location_id);
        $query = CatalogItem::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            ->orderBy('position')
            ->orderBy('id');

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('description', 'like', $like));
        }

        $out = [];
        foreach ($query->limit($limit)->get() as $item) {
            $priceMinor = $item->price_minor === null ? null : (int) $item->price_minor;

            if ($location !== null) {
                try {
                    $effective = $this->pricing->resolve($business, $item, $location);
                    $priceMinor = $effective->priceMinor;
                } catch (CatalogRuleException) {
                    continue; // not offered at this document's Location
                }
            }

            $out[] = $this->present($item, $priceMinor, (string) $document->currency_code);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data  type (product|package), name, description?, price? (decimal string)
     * @return array<string, mixed>
     *
     * @throws CatalogRuleException
     */
    public function create(Business $business, BusinessDocument $document, array $data, int $actorUserId): array
    {
        $currency = (string) $business->currency_code;
        $price = isset($data['price']) ? trim((string) $data['price']) : '';
        $priceMinor = CatalogMoney::toMinor($price === '' ? null : $price, $currency);

        $item = $this->items->create($business, [
            'type' => $data['type'] ?? null,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'price_minor' => $priceMinor,
            'currency_code' => $priceMinor === null ? null : $currency,
        ], $actorUserId);

        return $this->present($item, $item->price_minor === null ? null : (int) $item->price_minor, (string) $document->currency_code);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CatalogItem $item, ?int $priceMinor, string $documentCurrency): array
    {
        $currency = $item->currency_code;

        return [
            'uid' => (string) $item->uid,
            'name' => (string) $item->name,
            'description' => $item->description,
            'type' => $item->type?->value,
            'price_minor' => $priceMinor,
            'price_formatted' => $priceMinor === null ? null : CatalogMoney::format($priceMinor, $currency),
            'currency_code' => $currency,
            'quote_only' => $priceMinor === null,
            'addable' => $currency === null || $currency === $documentCurrency,
        ];
    }
}
