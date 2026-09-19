<?php

namespace Tests\Feature\Catalog;

use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\PackageSnapshotService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\PackageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Implementation Contract 16 §5.3 / §12.D / §18.D — `PackageSnapshotBoundaryTest`:
 * Immutability source-boundary test and data-equivalence proof.
 *
 * Proves that production code contains NO path that updates or saves an existing
 * PackageSnapshot after insertion, mirroring the `WebsiteRevision` /
 * `BusinessLocationBoundaryTest` technique (§3.3, §12.D).
 */
class PackageSnapshotBoundaryTest extends TestCase
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

    // -----------------------------------------------------------------
    // Source boundary scanning (app/ directory)
    // -----------------------------------------------------------------

    /**
     * Proves that no production file in app/ contains any call to update,
     * modify, or delete an existing PackageSnapshot or package_snapshots row.
     */
    public function test_production_code_contains_no_update_or_delete_path_for_package_snapshots(): void
    {
        $forbiddenPatterns = [
            'model static update' => '/PackageSnapshot::(?:query\(\)\s*->\s*)?(update|increment|decrement|delete|forceDelete)\s*\(/',
            'model query mutation' => '/PackageSnapshot::(?:query\(\))?\s*->(?:[^;]*?)->\s*(update|increment|decrement|delete|forceDelete)\s*\(/s',
            'snapshot instance mutation' => '/\$snapshot\s*->\s*(update|save|delete|forceDelete|increment|decrement)\s*\(/',
            'query-builder update/delete' => "/DB::table\\(\\s*['\"]package_snapshots['\"]\\s*\\)\\s*->\\s*(update|increment|decrement|delete|truncate)\\s*\\(/",
            'query-builder chained mutation' => "/DB::table\\(\\s*['\"]package_snapshots['\"]\\s*\\)(?:[^;]*?)->\\s*(update|increment|decrement|delete|truncate)\\s*\\(/s",
            'raw sql update/delete' => "/(?:UPDATE|DELETE\\s+FROM|TRUNCATE)\\s+[`'\"]?package_snapshots[`'\"]?/i",
        ];

        $violations = [];

        foreach ((new Finder())->files()->in(base_path('app'))->name('*.php') as $file) {
            $relative = 'app/' . str_replace('\\', '/', $file->getRelativePathname());
            $content = $file->getContents();

            foreach ($forbiddenPatterns as $label => $pattern) {
                if (preg_match_all($pattern, $content, $matches) > 0) {
                    $violations[$relative][] = $label . ': ' . implode(', ', array_unique($matches[0]));
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "PackageSnapshot is immutable. Found unauthorized mutation path in production code:\n" . json_encode($violations, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Proves that PackageSnapshotService is the ONLY production file in app/
     * authorized to create/insert PackageSnapshot rows.
     */
    public function test_only_packages_snapshot_service_creates_package_snapshots_in_production(): void
    {
        $creationPatterns = [
            'model create' => '/PackageSnapshot::(?:query\(\)\s*->\s*)?(create|insert|firstOrCreate|updateOrCreate|forceCreate)\s*\(/',
            'model instantiate' => '/new\s+PackageSnapshot\s*\(/',
            'query-builder insert' => "/DB::table\\(\\s*['\"]package_snapshots['\"]\\s*\\)\\s*->\\s*(insert|insertGetId|insertOrIgnore|upsert)\\s*\\(/",
        ];

        $allowedFile = 'app/Library/Catalog/PackageSnapshotService.php';
        $found = [];

        foreach ((new Finder())->files()->in(base_path('app'))->name('*.php') as $file) {
            $relative = 'app/' . str_replace('\\', '/', $file->getRelativePathname());
            $content = $file->getContents();

            foreach ($creationPatterns as $label => $pattern) {
                if (preg_match_all($pattern, $content, $matches) > 0) {
                    $found[$relative][] = $label;
                }
            }
        }

        $this->assertSame(
            [$allowedFile],
            array_keys($found),
            'PackageSnapshotService must be the sole production creation seam for PackageSnapshot.'
        );
    }

    /**
     * Proves the PackageSnapshot model has UPDATED_AT = null and exposes
     * no custom mutation methods.
     */
    public function test_package_snapshot_model_has_no_updated_at_and_no_mutation_methods(): void
    {
        $this->assertNull(
            PackageSnapshot::UPDATED_AT,
            'PackageSnapshot must set const UPDATED_AT = null per Contract 16 §5.3.'
        );

        $reflector = new ReflectionClass(PackageSnapshot::class);
        $publicMethods = $reflector->getMethods(ReflectionMethod::IS_PUBLIC);

        $customMutatorNames = [
            'update', 'updatePrice', 'setPrice', 'modify', 'changePrice', 'archive',
            'deleteSnapshot', 'cancel', 'expire', 'renew', 'save', 'forceSave',
        ];

        $declaredMethods = [];
        foreach ($publicMethods as $method) {
            if ($method->getDeclaringClass()->getName() === PackageSnapshot::class) {
                $declaredMethods[] = $method->getName();
            }
        }

        foreach ($customMutatorNames as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $declaredMethods,
                "PackageSnapshot must not declare custom mutation method [{$forbidden}]."
            );
        }
    }

    // -----------------------------------------------------------------
    // Data-equivalence proof (take A, edit price, take B, compare)
    // -----------------------------------------------------------------

    /**
     * Blueprint §17 / Contract 16 §13 / §14 acceptance proof:
     * - take snapshot A
     * - change catalog price & location override
     * - take snapshot B
     * - snapshot A remains byte/data-equivalent to its original commercial state
     * - snapshot B reflects the new state
     */
    public function test_snapshot_a_remains_byte_data_equivalent_after_subsequent_catalog_mutations(): void
    {
        $business = $this->business('USD');
        $item = $this->items()->create($business, [
            'type' => 'package',
            'name' => 'Original Package Name',
            'description' => 'Original Package Description',
            'price_minor' => 10000,
            'currency_code' => 'USD',
        ]);
        $location = $this->location($business, 'Original Location');

        // 1. Capture snapshot A
        $snapshotA = $this->service()->snapshot($item, $location);

        // Record full baseline commercial state of Snapshot A
        $baselineA = DB::table('package_snapshots')->where('id', $snapshotA->id)->first();
        $this->assertNotNull($baselineA);
        $this->assertSame(10000, (int) $baselineA->price_minor_at_snapshot);
        $this->assertSame('USD', $baselineA->currency_code_at_snapshot);
        $this->assertSame('Original Package Name', $baselineA->name_at_snapshot);
        $this->assertSame('Original Package Description', $baselineA->description_at_snapshot);

        // 2. Perform extensive mutations on the CatalogItem and Location overrides:
        //    a) Update catalog item price, name, description
        $this->items()->update($business, $item, [
            'name' => 'Completely Renamed Package',
            'description' => 'New Description with higher features',
            'price_minor' => 25000,
            'currency_code' => 'USD',
        ]);

        //    b) Set location-specific price override
        $this->overrides()->setPriceOverride($business, $item, $location, 27500);

        // 3. Capture snapshot B under new state
        $snapshotB = $this->service()->snapshot($item, $location);

        // 4. Assert snapshot B captured the new commercial state
        $recordB = DB::table('package_snapshots')->where('id', $snapshotB->id)->first();
        $this->assertNotNull($recordB);
        $this->assertSame(27500, (int) $recordB->price_minor_at_snapshot);
        $this->assertSame('Completely Renamed Package', $recordB->name_at_snapshot);
        $this->assertSame('New Description with higher features', $recordB->description_at_snapshot);
        $this->assertNotSame($snapshotA->id, $snapshotB->id);
        $this->assertNotSame($snapshotA->uid, $snapshotB->uid);

        // 5. Assert snapshot A is EXACTLY byte/data-equivalent to its original baseline state
        $freshA = DB::table('package_snapshots')->where('id', $snapshotA->id)->first();
        $this->assertNotNull($freshA);

        $this->assertSame((int) $baselineA->id, (int) $freshA->id);
        $this->assertSame($baselineA->uid, $freshA->uid);
        $this->assertSame((int) $baselineA->business_id, (int) $freshA->business_id);
        $this->assertSame((int) $baselineA->catalog_item_id, (int) $freshA->catalog_item_id);
        $this->assertSame((int) $baselineA->business_location_id, (int) $freshA->business_location_id);
        $this->assertSame($baselineA->name_at_snapshot, $freshA->name_at_snapshot);
        $this->assertSame($baselineA->description_at_snapshot, $freshA->description_at_snapshot);
        $this->assertSame((int) $baselineA->price_minor_at_snapshot, (int) $freshA->price_minor_at_snapshot);
        $this->assertSame($baselineA->currency_code_at_snapshot, $freshA->currency_code_at_snapshot);
        $this->assertSame((int) $baselineA->schema_version, (int) $freshA->schema_version);
        $this->assertSame($baselineA->created_by_user_id, $freshA->created_by_user_id);
        $this->assertSame($baselineA->created_at, $freshA->created_at);

        // Further prove that archiving the item doesn't touch Snapshot A either
        $this->items()->archive($business, $item);

        $freshAAfterArchive = DB::table('package_snapshots')->where('id', $snapshotA->id)->first();
        $this->assertEquals($baselineA, $freshAAfterArchive, 'Snapshot A remains byte/data-equivalent even after item archive.');
    }
}
