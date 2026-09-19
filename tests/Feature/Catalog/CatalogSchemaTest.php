<?php

namespace Tests\Feature\Catalog;

use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 16 §5, §12.A — exact schema shape (columns,
 * nullability, unique constraints, restrictOnDelete/cascadeOnDelete FKs)
 * for catalog_items, catalog_item_location_overrides and
 * package_snapshots. No application-layer behavior is exercised here —
 * that belongs to Sub-slices B/C/D; this file proves only what the DDL
 * itself enforces.
 */
class CatalogSchemaTest extends TestCase
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

    private function insertCatalogItem(Business $business, array $overrides = []): int
    {
        return DB::table('catalog_items')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'type' => 'product',
            'name' => 'Test Item',
            'price_minor' => 5000,
            'currency_code' => 'USD',
            'position' => 0,
            'lifecycle_state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    // --- catalog_items ---

    public function test_catalog_items_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('catalog_items'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('catalog_items', [
            'id', 'uid', 'business_id', 'type', 'name', 'description',
            'price_minor', 'currency_code', 'position', 'lifecycle_state',
            'archived_at', 'created_by_user_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_catalog_items_uid_is_unique(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $uid = (string) Str::uuid();
        $this->insertCatalogItem($business, ['uid' => $uid]);

        $this->expectException(QueryException::class);
        $this->insertCatalogItem($business, ['uid' => $uid]);
    }

    public function test_catalog_items_business_id_restricts_deletion_of_referenced_business(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->insertCatalogItem($business);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_catalog_items_created_by_user_id_is_nullable_with_null_on_delete(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        // Nullable: no user required.
        $itemId = $this->insertCatalogItem($business, ['created_by_user_id' => null]);
        $this->assertDatabaseHas('catalog_items', ['id' => $itemId, 'created_by_user_id' => null]);

        // nullOnDelete: deleting the referenced user nulls the column
        // rather than blocking the delete or cascading the catalog item.
        // A SEPARATE actor from the business owner, deliberately: the
        // owner's own deletion would cascade through businesses (onDelete
        // cascade) into this very row (restrictOnDelete), which would prove
        // nothing about created_by_user_id's own FK behavior.
        $creator = $this->createCustomer();
        $itemWithCreator = $this->insertCatalogItem($business, ['created_by_user_id' => $creator->user_id]);
        DB::table('users')->where('id', $creator->user_id)->delete();

        $this->assertDatabaseHas('catalog_items', ['id' => $itemWithCreator, 'created_by_user_id' => null]);
    }

    /**
     * Column-type introspection, not a runtime negative-value rejection
     * test: this connection runs with `strict => false`
     * (config/database.php), so MySQL silently clamps an out-of-range
     * unsigned value rather than throwing — a pre-existing, repository-wide
     * connection setting this sub-slice does not change. What Contract 16
     * §9 actually requires is the DDL type itself, which this proves
     * directly and reliably regardless of session sql_mode.
     */
    private function assertColumnIsUnsigned(string $table, string $column): void
    {
        $row = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column]);

        $this->assertNotEmpty($row, "Column [{$column}] not found on [{$table}].");
        $this->assertStringContainsStringIgnoringCase('unsigned', $row[0]->Type, "Expected [{$table}.{$column}] to be unsigned, got [{$row[0]->Type}].");
    }

    public function test_catalog_items_price_minor_column_is_unsigned(): void
    {
        // No document authorizes a negative sale price (Contract 16 §9).
        $this->assertColumnIsUnsigned('catalog_items', 'price_minor');
    }

    public function test_catalog_items_price_minor_and_currency_code_are_both_nullable_at_the_db_layer(): void
    {
        // The co-nullable invariant (both null or both set) is an
        // application-layer rule for Sub-slice B, not enforced here.
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $itemId = $this->insertCatalogItem($business, ['price_minor' => null, 'currency_code' => null]);

        $this->assertDatabaseHas('catalog_items', ['id' => $itemId, 'price_minor' => null, 'currency_code' => null]);
    }

    // --- catalog_item_location_overrides ---

    public function test_catalog_item_location_overrides_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('catalog_item_location_overrides'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('catalog_item_location_overrides', [
            'id', 'catalog_item_id', 'business_location_id', 'is_enabled',
            'price_minor_override', 'created_at', 'updated_at',
        ]));
    }

    public function test_overrides_enforces_unique_catalog_item_and_location(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);

        DB::table('catalog_item_location_overrides')->insert([
            'catalog_item_id' => $itemId,
            'business_location_id' => $location->id,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('catalog_item_location_overrides')->insert([
            'catalog_item_id' => $itemId,
            'business_location_id' => $location->id,
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_overrides_cascade_deletes_when_catalog_item_is_deleted(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);

        $overrideId = DB::table('catalog_item_location_overrides')->insertGetId([
            'catalog_item_id' => $itemId,
            'business_location_id' => $location->id,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_items')->where('id', $itemId)->delete();

        $this->assertDatabaseMissing('catalog_item_location_overrides', ['id' => $overrideId]);
    }

    public function test_overrides_restricts_deletion_of_referenced_location(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);

        DB::table('catalog_item_location_overrides')->insert([
            'catalog_item_id' => $itemId,
            'business_location_id' => $location->id,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_locations')->where('id', $location->id)->delete();
    }

    public function test_overrides_price_minor_override_column_is_unsigned(): void
    {
        $this->assertColumnIsUnsigned('catalog_item_location_overrides', 'price_minor_override');
    }

    // --- package_snapshots ---

    public function test_package_snapshots_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('package_snapshots'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('package_snapshots', [
            'id', 'uid', 'business_id', 'catalog_item_id', 'business_location_id',
            'name_at_snapshot', 'description_at_snapshot', 'price_minor_at_snapshot',
            'currency_code_at_snapshot', 'schema_version', 'created_by_user_id', 'created_at',
        ]));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('package_snapshots', 'updated_at'));
    }

    private function insertSnapshot(Business $business, int $itemId, int $locationId, array $overrides = []): int
    {
        return DB::table('package_snapshots')->insertGetId(array_merge([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'catalog_item_id' => $itemId,
            'business_location_id' => $locationId,
            'name_at_snapshot' => 'Snapshot Item',
            'price_minor_at_snapshot' => 5000,
            'currency_code_at_snapshot' => 'USD',
            'schema_version' => 1,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_package_snapshots_business_location_id_is_not_nullable(): void
    {
        // Contract §5.3, corrected: the transaction's own Location, never
        // optional — even when the Business-wide default price applied.
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);

        $this->expectException(QueryException::class);
        $this->insertSnapshot($business, $itemId, 0, ['business_location_id' => null]);
    }

    public function test_package_snapshots_created_by_user_id_is_nullable_with_null_on_delete(): void
    {
        // Contract §5.3, corrected: a public/system snapshot has no
        // authenticated staff User — no fake system User is invented.
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);

        $publicSnapshotId = $this->insertSnapshot($business, $itemId, $location->id, ['created_by_user_id' => null]);
        $this->assertDatabaseHas('package_snapshots', ['id' => $publicSnapshotId, 'created_by_user_id' => null]);

        // A SEPARATE actor from the business owner — see the identical note
        // on the catalog_items version of this test above.
        $creator = $this->createCustomer();
        $staffSnapshotId = $this->insertSnapshot($business, $itemId, $location->id, ['created_by_user_id' => $creator->user_id]);
        DB::table('users')->where('id', $creator->user_id)->delete();

        $this->assertDatabaseHas('package_snapshots', ['id' => $staffSnapshotId, 'created_by_user_id' => null]);
    }

    public function test_package_snapshots_price_minor_at_snapshot_is_not_nullable(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);

        $this->expectException(QueryException::class);
        $this->insertSnapshot($business, $itemId, $location->id, ['price_minor_at_snapshot' => null]);
    }

    public function test_package_snapshots_currency_code_at_snapshot_is_not_nullable(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);

        $this->expectException(QueryException::class);
        $this->insertSnapshot($business, $itemId, $location->id, ['currency_code_at_snapshot' => null]);
    }

    public function test_package_snapshots_price_minor_at_snapshot_column_is_unsigned(): void
    {
        $this->assertColumnIsUnsigned('package_snapshots', 'price_minor_at_snapshot');
    }

    public function test_package_snapshots_restricts_deletion_of_referenced_catalog_item(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);
        $this->insertSnapshot($business, $itemId, $location->id);

        $this->expectException(QueryException::class);
        DB::table('catalog_items')->where('id', $itemId)->delete();
    }

    public function test_package_snapshots_restricts_deletion_of_referenced_business(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);
        $this->insertSnapshot($business, $itemId, $location->id);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_package_snapshots_restricts_deletion_of_referenced_location(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $itemId = $this->insertCatalogItem($business);
        $location = $this->location($business);
        $this->insertSnapshot($business, $itemId, $location->id);

        $this->expectException(QueryException::class);
        DB::table('business_locations')->where('id', $location->id)->delete();
    }

    public function test_package_snapshots_never_updates_after_insert_has_no_updated_at_column(): void
    {
        // The DDL-level half of the write-once discipline: no updated_at
        // column exists to be touched. The "no production code path calls
        // update()" half is Sub-slice D's own source-boundary test (§12.D).
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('package_snapshots', 'updated_at'));
    }
}
