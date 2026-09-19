<?php

namespace Tests\Feature\Catalog;

use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogItemPricingResolver;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 16 §5.2/§6/§12.C — `CatalogItemPricingResolver`:
 * the one place effective-price resolution lives. No controller, route, or
 * view exists yet (Sub-slice E).
 */
class CatalogItemPricingResolverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function resolver(): CatalogItemPricingResolver
    {
        return app(CatalogItemPricingResolver::class);
    }

    private function overrides(): CatalogItemLocationOverrideManager
    {
        return app(CatalogItemLocationOverrideManager::class);
    }

    private function items(): CatalogItemManager
    {
        return app(CatalogItemManager::class);
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    private function location(Business $business, string $name = 'Main Street'): BusinessLocation
    {
        $id = DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'name' => $name,
            'service_mode' => 'storefront', 'city' => 'Springfield', 'region' => 'IL', 'country_code' => 'US',
            'public_address' => false, 'is_primary' => true, 'lifecycle_state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return BusinessLocation::findOrFail($id);
    }

    private function pricedItem(Business $business, int $priceMinor = 5000, string $currencyCode = 'USD'): CatalogItem
    {
        return $this->items()->create($business, [
            'type' => 'product',
            'name' => 'Deep Clean',
            'price_minor' => $priceMinor,
            'currency_code' => $currencyCode,
        ]);
    }

    private function quoteOnlyItem(Business $business): CatalogItem
    {
        return $this->items()->create($business, [
            'type' => 'package',
            'name' => 'Custom Wedding Package',
        ]);
    }

    // -----------------------------------------------------------------
    // The sparse-default chain: override price -> item price -> quote-only
    // -----------------------------------------------------------------

    public function test_no_override_row_resolves_the_business_wide_default_price(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertSame(5000, $effective->priceMinor);
        $this->assertSame('USD', $effective->currencyCode);
        $this->assertFalse($effective->isQuoteOnly);
    }

    public function test_an_explicit_enabled_override_with_no_price_still_resolves_the_business_wide_price(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);
        $this->overrides()->setEnabled($business, $item, $location, true);

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertSame(5000, $effective->priceMinor);
    }

    public function test_a_disabled_override_refuses_resolution(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business);
        $location = $this->location($business);
        $this->overrides()->setEnabled($business, $item, $location, false);

        $this->expectException(CatalogRuleException::class);
        $this->resolver()->resolve($business, $item, $location);
    }

    public function test_a_price_override_resolves_over_the_business_wide_price(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);
        $this->overrides()->setPriceOverride($business, $item, $location, 3000);

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertSame(3000, $effective->priceMinor);
        $this->assertSame('USD', $effective->currencyCode);
    }

    public function test_a_null_override_price_falls_back_to_the_catalog_price(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);
        $this->overrides()->setPriceOverride($business, $item, $location, 3000);
        $this->overrides()->setPriceOverride($business, $item, $location, null);

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertSame(5000, $effective->priceMinor);
    }

    public function test_after_clearing_a_deviation_back_to_default_the_resolver_treats_it_as_no_override(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        // Create a deviation, then clear it back to the canonical default
        // — the override row is deleted (§5.2 sparse invariant) — and
        // confirm the resolver's result is indistinguishable from a
        // Location that never had an override row at all.
        $this->overrides()->setPriceOverride($business, $item, $location, 3000);
        $cleared = $this->overrides()->setPriceOverride($business, $item, $location, null);
        $this->assertNull($cleared);
        $this->assertSame(0, DB::table('catalog_item_location_overrides')->where('catalog_item_id', $item->id)->count());

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertSame(5000, $effective->priceMinor);
        $this->assertSame('USD', $effective->currencyCode);
        $this->assertFalse($effective->isQuoteOnly);
    }

    public function test_a_quote_only_item_resolves_with_no_fixed_price(): void
    {
        $business = $this->business();
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertNull($effective->priceMinor);
        $this->assertNull($effective->currencyCode);
        $this->assertTrue($effective->isQuoteOnly);
    }

    public function test_a_quote_only_item_with_a_non_null_location_override_is_refused_at_write_time(): void
    {
        $business = $this->business();
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);

        // The manager, not the resolver, is where this is refused (§5.2:
        // a Location override cannot exist without a CatalogItem
        // currency to express it in). Proven here so this file documents
        // the resolver's own precondition, not just the manager's.
        $this->expectException(CatalogRuleException::class);
        $this->overrides()->setPriceOverride($business, $item, $location, 3000);
    }

    public function test_a_quote_only_item_with_a_null_or_default_override_remains_valid_quote_only(): void
    {
        $business = $this->business();
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);
        $result = $this->overrides()->setPriceOverride($business, $item, $location, null);
        $this->assertNull($result, 'null on a quote-only item is still the canonical default — no row.');

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertNull($effective->priceMinor);
        $this->assertNull($effective->currencyCode);
        $this->assertTrue($effective->isQuoteOnly);
    }

    public function test_a_manually_inserted_impossible_override_state_causes_resolver_refusal(): void
    {
        $business = $this->business();
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);

        // Bypasses CatalogItemLocationOverrideManager entirely — the
        // manager can no longer create this row, but the resolver must
        // still fail closed against corrupt/legacy data that somehow
        // contains it, rather than ever returning a fixed amount with a
        // null currency.
        DB::table('catalog_item_location_overrides')->insert([
            'catalog_item_id' => $item->id, 'business_location_id' => $location->id,
            'is_enabled' => true, 'price_minor_override' => 3000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(CatalogRuleException::class);
        $this->resolver()->resolve($business, $item, $location);
    }

    // -----------------------------------------------------------------
    // Refusals — order matters: data integrity before business rules
    // -----------------------------------------------------------------

    public function test_an_archived_catalog_item_refuses_resolution(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business);
        $location = $this->location($business);
        $this->items()->archive($business, $item);

        $this->expectException(CatalogRuleException::class);
        $this->resolver()->resolve($business, $item->fresh(), $location);
    }

    public function test_a_location_belonging_to_a_different_business_refuses_resolution(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $item = $this->pricedItem($businessA);
        $locationB = $this->location($businessB);

        $this->expectException(CatalogRuleException::class);
        $this->resolver()->resolve($businessA, $item, $locationB);
    }

    public function test_a_catalog_item_belonging_to_a_different_business_refuses_resolution(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $itemB = $this->pricedItem($businessB);
        $locationA = $this->location($businessA);

        $this->expectException(CatalogRuleException::class);
        $this->resolver()->resolve($businessA, $itemB, $locationA);
    }

    // -----------------------------------------------------------------
    // No currency override — the catalog item's currency always wins
    // -----------------------------------------------------------------

    public function test_currency_never_diverges_from_the_catalog_items_own_currency_with_an_override(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'EUR');
        $location = $this->location($business);
        $this->overrides()->setPriceOverride($business, $item, $location, 3000);

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertSame('EUR', $effective->currencyCode);
    }

    public function test_currency_never_diverges_from_the_catalog_items_own_currency_without_an_override(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'GBP');
        $location = $this->location($business);

        $effective = $this->resolver()->resolve($business, $item, $location);

        $this->assertSame('GBP', $effective->currencyCode);
    }
}
