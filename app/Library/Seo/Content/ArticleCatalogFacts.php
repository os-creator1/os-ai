<?php

namespace App\Library\Seo\Content;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Catalog\CatalogMoney;
use App\Models\Business;
use App\Models\CatalogItem;

/**
 * SEO Content Engine V1 — the Business's real Packages & Products, as facts an article may quote.
 * Packages & Products is the ONE pricing truth (see WebsiteCatalogReferences); this reads it and never
 * writes. It is what the AI is allowed to state about price, what the claim guard checks dollar amounts
 * against, and what freshness compares an article to.
 */
final class ArticleCatalogFacts
{
    private const CAP = 60;

    /**
     * @return array<int, array{uid: string, name: string, type: string, description: ?string, price_minor: ?int, currency: ?string, price_label: ?string, updated_at: ?string}>
     */
    public function active(Business $business): array
    {
        return CatalogItem::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::CAP)
            ->get()
            ->map(fn (CatalogItem $item) => [
                'uid' => (string) $item->uid,
                'name' => (string) $item->name,
                'type' => $item->type->value,
                'description' => $item->description !== null && trim((string) $item->description) !== '' ? (string) $item->description : null,
                'price_minor' => $item->price_minor,
                'currency' => $item->currency_code ?: null,
                'price_label' => $item->price_minor !== null && $item->currency_code ? CatalogMoney::format($item->price_minor, $item->currency_code) : null,
                'updated_at' => $item->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every price amount (in whole currency units, as text without symbols/commas, e.g. "450" and "450.00")
     * an article may legitimately mention.
     *
     * @return array<int, string>
     */
    public function allowedAmounts(Business $business): array
    {
        $amounts = [];

        foreach ($this->active($business) as $item) {
            if ($item['price_minor'] === null) {
                continue;
            }

            $whole = intdiv((int) $item['price_minor'], 100);
            $cents = (int) $item['price_minor'] % 100;
            $amounts[] = (string) $whole;
            $amounts[] = $whole . '.' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        }

        return array_values(array_unique($amounts));
    }
}
