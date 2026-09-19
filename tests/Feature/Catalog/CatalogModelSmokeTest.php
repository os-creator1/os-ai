<?php

namespace Tests\Feature\Catalog;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Catalog\CatalogItemType;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\CatalogItemLocationOverride;
use App\Models\PackageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 16 §12.A — model factory smoke tests: casts,
 * relations, uid generation, and the not-fillable lifecycle discipline on
 * CatalogItem (mirroring BusinessLocation). No service/controller behavior
 * is exercised — that is Sub-slices B/C/D.
 */
class CatalogModelSmokeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function location(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    public function test_catalog_item_can_be_created_with_casts_and_relations(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $item = CatalogItem::create([
            'business_id' => $business->id,
            'type' => CatalogItemType::Product->value,
            'name' => 'Deep Clean',
            'price_minor' => 12000,
            'currency_code' => 'USD',
            'position' => 0,
        ]);

        $this->assertTrue(Str::isUuid($item->uid));
        $this->assertInstanceOf(CatalogItemType::class, $item->fresh()->type);
        $this->assertSame(CatalogItemType::Product, $item->fresh()->type);
        $this->assertInstanceOf(CatalogItemLifecycleState::class, $item->fresh()->lifecycle_state);
        $this->assertSame(CatalogItemLifecycleState::Active, $item->fresh()->lifecycle_state);
        $this->assertTrue($item->isActive());
        $this->assertFalse($item->isArchived());
        $this->assertTrue($item->business->is($business));
        $this->assertCount(0, $item->locationOverrides);
        $this->assertCount(0, $item->snapshots);
    }

    public function test_catalog_item_lifecycle_state_and_archived_at_are_not_fillable(): void
    {
        // Mirrors BusinessLocation exactly: mass-assignment must not be
        // able to set these — the only write path is Sub-slice B's
        // archive()/reactivate().
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $item = CatalogItem::create([
            'business_id' => $business->id,
            'type' => CatalogItemType::Product->value,
            'name' => 'Ignored Lifecycle',
            'lifecycle_state' => CatalogItemLifecycleState::Archived->value,
            'archived_at' => now(),
        ]);

        $this->assertSame(CatalogItemLifecycleState::Active, $item->fresh()->lifecycle_state);
        $this->assertNull($item->fresh()->archived_at);
    }

    public function test_catalog_item_generates_a_real_uuid_never_uniqid(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $item = CatalogItem::create([
            'business_id' => $business->id,
            'type' => CatalogItemType::Package->value,
            'name' => 'Wedding Package',
        ]);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $item->uid,
        );
    }

    public function test_catalog_item_location_override_can_be_created_with_casts_and_relations(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $item = CatalogItem::create(['business_id' => $business->id, 'type' => 'product', 'name' => 'Trim']);
        $location = $this->location($business);

        $override = CatalogItemLocationOverride::create([
            'catalog_item_id' => $item->id,
            'business_location_id' => $location->id,
            'is_enabled' => false,
            'price_minor_override' => 4500,
        ]);

        $this->assertIsBool($override->fresh()->is_enabled);
        $this->assertFalse($override->fresh()->is_enabled);
        $this->assertIsInt($override->fresh()->price_minor_override);
        $this->assertTrue($override->catalogItem->is($item));
        $this->assertTrue($override->businessLocation->is($location));
    }

    public function test_package_snapshot_can_be_created_with_casts_and_relations_and_never_touches_updated_at(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $item = CatalogItem::create(['business_id' => $business->id, 'type' => 'product', 'name' => 'Consult']);
        $location = $this->location($business);

        $snapshot = PackageSnapshot::create([
            'business_id' => $business->id,
            'catalog_item_id' => $item->id,
            'business_location_id' => $location->id,
            'name_at_snapshot' => 'Consult',
            'price_minor_at_snapshot' => 5000,
            'currency_code_at_snapshot' => 'USD',
            'created_by_user_id' => $customer->user_id,
        ]);

        $this->assertTrue(Str::isUuid($snapshot->uid));
        $this->assertNull(PackageSnapshot::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $snapshot->fresh()->getAttributes());
        $this->assertTrue($snapshot->business->is($business));
        $this->assertTrue($snapshot->catalogItem->is($item));
        $this->assertTrue($snapshot->businessLocation->is($location));
        $this->assertSame((int) $customer->user_id, (int) $snapshot->creator->id);
    }

    public function test_package_snapshot_accepts_a_null_creator_for_a_public_system_flow(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $item = CatalogItem::create(['business_id' => $business->id, 'type' => 'product', 'name' => 'Self-Booking Item']);
        $location = $this->location($business);

        $snapshot = PackageSnapshot::create([
            'business_id' => $business->id,
            'catalog_item_id' => $item->id,
            'business_location_id' => $location->id,
            'name_at_snapshot' => 'Self-Booking Item',
            'price_minor_at_snapshot' => 3000,
            'currency_code_at_snapshot' => 'USD',
            'created_by_user_id' => null,
        ]);

        $this->assertNull($snapshot->fresh()->created_by_user_id);
        $this->assertNull($snapshot->creator);
    }
}
