<?php

namespace App\Library\Catalog;

/**
 * Implementation Contract 16 §5.2 — the result of
 * `CatalogItemPricingResolver::resolve()`. Reaching this object at all
 * already proves the item is active and offered at the Location (the
 * resolver refuses/throws before ever constructing one); this is purely
 * the resolved price fact.
 */
final class CatalogItemEffectivePrice
{
    public function __construct(
        public readonly ?int $priceMinor,
        public readonly ?string $currencyCode,
        public readonly bool $isQuoteOnly,
    ) {
    }
}
