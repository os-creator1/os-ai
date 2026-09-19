<?php

namespace Tests\Feature\Catalog;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
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
 * Implementation Contract 16 §5.2/§7/§12.C —
 * `CatalogItemLocationOverrideManager`: the sparse per-Location
 * enable/disable and price-override write path. No controller, route, or
 * view exists yet (Sub-slice E); this file exercises the manager
 * directly. Tenancy, the `packages_products` capability,
 * `EntitlementManager`, and `LocationAccessGuard`'s Location ACL are
 * HTTP-layer concerns this manager deliberately does not perform (§6) —
 * what it DOES enforce on its own is that both the `CatalogItem` and the
 * `BusinessLocation` genuinely belong to the same `Business`.
 */
class CatalogItemLocationOverrideManagerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function manager(): CatalogItemLocationOverrideManager
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

    /**
     * A plain, active, raw-inserted Location. Bypasses BusinessLocationManager
     * deliberately — this file's own subject is CatalogItemLocationOverrideManager,
     * not location creation/capacity, mirroring how CatalogItemManagerTest
     * never exercises BusinessLocationManager either.
     */
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

    private function item(Business $business, array $attributes = []): CatalogItem
    {
        return $this->items()->create($business, array_merge([
            'type' => 'product',
            'name' => 'Deep Clean',
            'price_minor' => 5000,
            'currency_code' => 'USD',
        ], $attributes));
    }

    // -----------------------------------------------------------------
    // setEnabled() / setPriceOverride() — create
    // -----------------------------------------------------------------

    public function test_set_enabled_creates_an_override_row_when_none_exists(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $override = $this->manager()->setEnabled($business, $item, $location, false);

        $this->assertSame((int) $item->id, (int) $override->catalog_item_id);
        $this->assertSame((int) $location->id, (int) $override->business_location_id);
        $this->assertFalse($override->is_enabled);
        $this->assertNull($override->price_minor_override);
    }

    public function test_set_price_override_creates_an_override_row_with_the_price(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $override = $this->manager()->setPriceOverride($business, $item, $location, 4200);

        $this->assertTrue($override->is_enabled);
        $this->assertSame(4200, $override->price_minor_override);
    }

    public function test_set_override_merges_partial_updates_without_losing_the_other_field(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $this->manager()->setPriceOverride($business, $item, $location, 4200);
        $updated = $this->manager()->setEnabled($business, $item, $location, false);

        $this->assertFalse($updated->is_enabled);
        $this->assertSame(4200, $updated->price_minor_override, 'Setting enabled alone must not revert the previously-set price.');
    }

    public function test_set_price_override_null_clears_a_previously_set_amount(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $this->manager()->setPriceOverride($business, $item, $location, 4200);
        $cleared = $this->manager()->setPriceOverride($business, $item, $location, null);

        $this->assertNull($cleared->price_minor_override);
        $this->assertTrue($cleared->is_enabled, 'Clearing price alone must not revert the enabled flag.');
    }

    public function test_only_one_override_row_exists_per_item_location_pair_after_repeated_writes(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $this->manager()->setEnabled($business, $item, $location, false);
        $this->manager()->setPriceOverride($business, $item, $location, 1000);
        $this->manager()->setEnabled($business, $item, $location, true);

        $this->assertSame(1, DB::table('catalog_item_location_overrides')
            ->where('catalog_item_id', $item->id)
            ->where('business_location_id', $location->id)
            ->count());
    }

    // -----------------------------------------------------------------
    // Domain-integrity refusals — never trust a foreign object
    // -----------------------------------------------------------------

    public function test_set_override_refuses_a_catalog_item_belonging_to_a_different_business(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $itemB = $this->item($businessB);
        $locationA = $this->location($businessA);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->setEnabled($businessA, $itemB, $locationA, false);
    }

    public function test_set_override_refuses_a_location_belonging_to_a_different_business(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();
        $itemA = $this->item($businessA);
        $locationB = $this->location($businessB);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->setEnabled($businessA, $itemA, $locationB, false);
    }

    // -----------------------------------------------------------------
    // Strict price validation — mirrors CatalogItemManager exactly
    // -----------------------------------------------------------------

    public function test_set_price_override_refuses_a_non_numeric_price_string(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->setOverride($business, $item, $location, ['price_minor_override' => 'abc']);
    }

    public function test_set_price_override_refuses_a_negative_price(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->setPriceOverride($business, $item, $location, -1);
    }

    public function test_set_enabled_refuses_a_non_boolean_value(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $this->expectException(CatalogRuleException::class);
        $this->manager()->setOverride($business, $item, $location, ['is_enabled' => 'yes']);
    }

    // -----------------------------------------------------------------
    // §7 concurrency — row-lock behavior, practical proof
    // -----------------------------------------------------------------

    public function test_set_override_takes_an_exclusive_row_lock_on_the_catalog_item_before_writing(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $sawLockingSelect = false;

        DB::listen(function ($query) use (&$sawLockingSelect) {
            if (str_contains(strtolower($query->sql), 'select') && str_contains(strtolower($query->sql), 'for update')) {
                $sawLockingSelect = true;
            }
        });

        $this->manager()->setEnabled($business, $item, $location, false);

        $this->assertTrue($sawLockingSelect, 'setOverride() must take the catalog_items row lock per Contract 16 §7.');
    }

    public function test_the_underlying_catalog_item_lifecycle_state_is_unaffected_by_an_override(): void
    {
        $business = $this->business();
        $item = $this->item($business);
        $location = $this->location($business);

        $this->manager()->setEnabled($business, $item, $location, false);

        $this->assertSame(CatalogItemLifecycleState::Active, $item->fresh()->lifecycle_state);
    }
}
