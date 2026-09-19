<?php

namespace Tests\Feature\Catalog;

use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Library\Catalog\PackageSnapshotService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\PackageSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 16 §5.3 / §6 / §7 / §12.D / §18.D —
 * `PackageSnapshotServiceTest`: tests for the canonical immutable snapshot service.
 */
class PackageSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function service(): PackageSnapshotService
    {
        return app(PackageSnapshotService::class);
    }

    private function items(): CatalogItemManager
    {
        return app(CatalogItemManager::class);
    }

    private function overrides(): CatalogItemLocationOverrideManager
    {
        return app(CatalogItemLocationOverrideManager::class);
    }

    private function business(string $currency = 'USD'): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, array_merge($this->businessAttributes(), [
            'currency_code' => $currency,
        ]));
    }

    private function location(Business $business, string $name = 'Main Street'): BusinessLocation
    {
        $id = DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => 'storefront',
            'city' => 'Springfield',
            'region' => 'IL',
            'country_code' => 'US',
            'public_address' => false,
            'is_primary' => true,
            'lifecycle_state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BusinessLocation::findOrFail($id);
    }

    private function pricedItem(Business $business, int $priceMinor = 5000, string $currencyCode = 'USD'): CatalogItem
    {
        return $this->items()->create($business, [
            'type' => 'package',
            'name' => 'Complete Wedding Package',
            'description' => 'Includes 4 hours of photo booth, props, and album.',
            'price_minor' => $priceMinor,
            'currency_code' => $currencyCode,
        ]);
    }

    private function quoteOnlyItem(Business $business): CatalogItem
    {
        return $this->items()->create($business, [
            'type' => 'package',
            'name' => 'Custom Enterprise Package',
            'description' => 'Custom pricing based on client specifications.',
        ]);
    }

    private function createStaffUser(): User
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Staff',
            'last_name' => 'Member',
            'email' => 'staff-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    // -----------------------------------------------------------------
    // Correct snapshot fields & identity
    // -----------------------------------------------------------------

    public function test_correct_snapshot_fields_populated_on_fixed_price_item(): void
    {
        $business = $this->business('USD');
        $item = $this->pricedItem($business, 7500, 'USD');
        $location = $this->location($business, 'Downtown Branch');
        $actor = $this->createStaffUser();

        $snapshot = $this->service()->snapshot($item, $location, $actor);

        $this->assertInstanceOf(PackageSnapshot::class, $snapshot);
        $this->assertNotEmpty($snapshot->uid);
        $this->assertTrue(Str::isUuid($snapshot->uid));
        $this->assertSame((int) $business->id, (int) $snapshot->business_id);
        $this->assertSame((int) $item->id, (int) $snapshot->catalog_item_id);
        $this->assertSame((int) $location->id, (int) $snapshot->business_location_id);
        $this->assertSame('Complete Wedding Package', $snapshot->name_at_snapshot);
        $this->assertSame('Includes 4 hours of photo booth, props, and album.', $snapshot->description_at_snapshot);
        $this->assertSame(7500, (int) $snapshot->price_minor_at_snapshot);
        $this->assertSame('USD', $snapshot->currency_code_at_snapshot);
        $this->assertSame(1, (int) $snapshot->schema_version);
        $this->assertSame((int) $actor->id, (int) $snapshot->created_by_user_id);
        $this->assertNotNull($snapshot->created_at);

        // Verify persisted record matches in database
        $persisted = PackageSnapshot::query()->whereKey($snapshot->id)->first();
        $this->assertNotNull($persisted);
        $this->assertSame((int) $snapshot->id, (int) $persisted->id);
        $this->assertSame($snapshot->uid, $persisted->uid);
    }

    public function test_business_id_and_catalog_identity_fields(): void
    {
        $business = $this->business('EUR');
        $item = $this->pricedItem($business, 9900, 'EUR');
        $location = $this->location($business);

        $snapshot = $this->service()->snapshot($item, $location);

        $this->assertSame((int) $business->id, (int) $snapshot->business_id);
        $this->assertSame((int) $item->id, (int) $snapshot->catalog_item_id);
        $this->assertSame($item->name, $snapshot->name_at_snapshot);
        $this->assertSame($item->description, $snapshot->description_at_snapshot);
    }

    public function test_business_location_id_is_always_populated_even_without_override_row(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 4000, 'USD');
        $location = $this->location($business);

        // No override row exists in database for this (item, location) pair
        $this->assertDatabaseMissing('catalog_item_location_overrides', [
            'catalog_item_id' => $item->id,
            'business_location_id' => $location->id,
        ]);

        $snapshot = $this->service()->snapshot($item, $location);

        $this->assertSame((int) $location->id, (int) $snapshot->business_location_id);
    }

    // -----------------------------------------------------------------
    // Pricing & Currency resolution
    // -----------------------------------------------------------------

    public function test_correct_fixed_price_from_business_wide_default(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 12500, 'USD');
        $location = $this->location($business);

        $snapshot = $this->service()->snapshot($item, $location);

        $this->assertSame(12500, (int) $snapshot->price_minor_at_snapshot);
        $this->assertSame('USD', $snapshot->currency_code_at_snapshot);
    }

    public function test_correct_location_override_price(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        // Apply a location price override
        $this->overrides()->setPriceOverride($business, $item, $location, 6500);

        $snapshot = $this->service()->snapshot($item, $location);

        $this->assertSame(6500, (int) $snapshot->price_minor_at_snapshot);
        $this->assertSame('USD', $snapshot->currency_code_at_snapshot);
    }

    public function test_correct_currency_for_canonical_fixed_price(): void
    {
        $business = $this->business('GBP');
        $item = $this->pricedItem($business, 8000, 'GBP');
        $location = $this->location($business);

        $snapshot = $this->service()->snapshot($item, $location);

        $this->assertSame('GBP', $snapshot->currency_code_at_snapshot);
    }

    public function test_quote_only_with_explicit_price_succeeds_and_takes_business_currency(): void
    {
        $business = $this->business('CAD');
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);

        $snapshot = $this->service()->snapshot($item, $location, null, 15000);

        $this->assertSame(15000, (int) $snapshot->price_minor_at_snapshot);
        $this->assertSame('CAD', $snapshot->currency_code_at_snapshot);
    }

    public function test_quote_only_with_zero_explicit_price_succeeds(): void
    {
        $business = $this->business('USD');
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);

        $snapshot = $this->service()->snapshot($item, $location, null, 0);

        $this->assertSame(0, (int) $snapshot->price_minor_at_snapshot);
        $this->assertSame('USD', $snapshot->currency_code_at_snapshot);
    }

    // -----------------------------------------------------------------
    // Actor tests
    // -----------------------------------------------------------------

    public function test_actor_populated_when_supplied(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);
        $actor = $this->createStaffUser();

        $snapshot = $this->service()->snapshot($item, $location, $actor);

        $this->assertSame((int) $actor->id, (int) $snapshot->created_by_user_id);
        $this->assertNotNull($snapshot->creator);
        $this->assertSame((int) $actor->id, (int) $snapshot->creator->id);
    }

    public function test_actor_null_accepted_and_records_null_without_fake_user(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        $userCountBefore = DB::table('users')->count();

        $snapshot = $this->service()->snapshot($item, $location, null);

        $this->assertNull($snapshot->created_by_user_id);
        $this->assertSame($userCountBefore, DB::table('users')->count(), 'No fake user should be created.');
    }

    // -----------------------------------------------------------------
    // Invariant and Refusal tests
    // -----------------------------------------------------------------

    public function test_foreign_business_location_is_refused(): void
    {
        $businessA = $this->business();
        $businessB = $this->business();

        $itemA = $this->pricedItem($businessA, 5000, 'USD');
        $locationB = $this->location($businessB);

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('That Location does not belong to this Business.');

        $this->service()->snapshot($itemA, $locationB);
    }

    public function test_archived_catalog_item_is_refused(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        $this->items()->archive($business, $item);

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('That catalog item is archived.');

        $this->service()->snapshot($item, $location);
    }

    public function test_disabled_at_location_item_is_refused(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        $this->overrides()->setEnabled($business, $item, $location, false);

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('That catalog item is not offered at this Location.');

        $this->service()->snapshot($item, $location);
    }

    public function test_quote_only_with_no_explicit_price_is_refused(): void
    {
        $business = $this->business();
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('An explicit price is required for a quote-only catalog item.');

        $this->service()->snapshot($item, $location, null, null);
    }

    public function test_canonical_fixed_price_with_explicit_price_is_refused(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('An explicit price cannot be supplied for an item that has a canonical fixed price.');

        $this->service()->snapshot($item, $location, null, 3000);
    }

    public function test_location_override_fixed_price_with_explicit_price_is_refused(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        $this->overrides()->setPriceOverride($business, $item, $location, 6000);

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('An explicit price cannot be supplied for an item that has a canonical fixed price.');

        $this->service()->snapshot($item, $location, null, 4000);
    }

    public function test_negative_explicit_price_is_refused(): void
    {
        $business = $this->business();
        $item = $this->quoteOnlyItem($business);
        $location = $this->location($business);

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('The explicit price cannot be negative.');

        $this->service()->snapshot($item, $location, null, -100);
    }

    public function test_nonexistent_catalog_item_is_refused(): void
    {
        $business = $this->business();
        $location = $this->location($business);

        $phantomItem = new CatalogItem();
        $phantomItem->id = 999999999;

        $this->expectException(CatalogRuleException::class);
        $this->expectExceptionMessage('That catalog item does not exist.');

        $this->service()->snapshot($phantomItem, $location);
    }

    public function test_reloads_item_from_persistence_rather_than_trusting_caller_mutations(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        // Caller tampers with in-memory model fields without saving
        $item->price_minor = 100;
        $item->name = 'Tampered Name';

        $snapshot = $this->service()->snapshot($item, $location);

        $this->assertSame(5000, (int) $snapshot->price_minor_at_snapshot, 'Must re-read persisted price.');
        $this->assertSame('Complete Wedding Package', $snapshot->name_at_snapshot, 'Must re-read persisted name.');
    }

    // -----------------------------------------------------------------
    // Immutability & Acceptance criteria (Blueprint §17)
    // -----------------------------------------------------------------

    public function test_earlier_snapshot_unaffected_by_later_price_change(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        // 1. Take snapshot A at initial price ($50.00)
        $snapshotA = $this->service()->snapshot($item, $location);
        $this->assertSame(5000, (int) $snapshotA->price_minor_at_snapshot);

        // 2. Change catalog item price to $80.00
        $this->items()->update($business, $item, [
            'price_minor' => 8000,
            'currency_code' => 'USD',
        ]);

        // 3. Take snapshot B at new price ($80.00)
        $snapshotB = $this->service()->snapshot($item, $location);
        $this->assertSame(8000, (int) $snapshotB->price_minor_at_snapshot);

        // 4. Assert snapshot A is provably unaffected in persistence
        $freshA = PackageSnapshot::query()->findOrFail($snapshotA->id);
        $this->assertSame(5000, (int) $freshA->price_minor_at_snapshot);
        $this->assertSame('USD', $freshA->currency_code_at_snapshot);
        $this->assertSame($snapshotA->name_at_snapshot, $freshA->name_at_snapshot);

        // 5. Assert snapshot B reflects the new price
        $freshB = PackageSnapshot::query()->findOrFail($snapshotB->id);
        $this->assertSame(8000, (int) $freshB->price_minor_at_snapshot);
        $this->assertNotSame($freshA->id, $freshB->id);
    }

    public function test_earlier_snapshot_unaffected_by_later_location_override_change(): void
    {
        $business = $this->business();
        $item = $this->pricedItem($business, 5000, 'USD');
        $location = $this->location($business);

        // 1. Take snapshot A at Business default price
        $snapshotA = $this->service()->snapshot($item, $location);
        $this->assertSame(5000, (int) $snapshotA->price_minor_at_snapshot);

        // 2. Add location override of $70.00
        $this->overrides()->setPriceOverride($business, $item, $location, 7000);

        // 3. Take snapshot B at location override price
        $snapshotB = $this->service()->snapshot($item, $location);
        $this->assertSame(7000, (int) $snapshotB->price_minor_at_snapshot);

        // 4. Disable item at this location
        $this->overrides()->setEnabled($business, $item, $location, false);

        // 5. Snapshot A is still intact in persistence
        $freshA = PackageSnapshot::query()->findOrFail($snapshotA->id);
        $this->assertSame(5000, (int) $freshA->price_minor_at_snapshot);

        // 6. New snapshot is refused because item is now disabled
        $this->expectException(CatalogRuleException::class);
        $this->service()->snapshot($item, $location);
    }
}
