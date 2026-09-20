<?php

namespace Tests\Feature\Catalog;

use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogItemManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §12.E — the HTTP/domain-service boundary.
 *
 * "Do NOT directly write `catalog_items`, `catalog_item_location_overrides` or
 * `package_snapshots` from controllers" is proven in TWO independent ways,
 * because either alone is weaker than the pair:
 *
 *  1. RUNTIME. Every write to those tables during a full run of the customer
 *     write flows is observed as it executes, with its call stack; each must
 *     have `CatalogItemManager` or `CatalogItemLocationOverrideManager` on
 *     that stack. A controller that wrote a row itself would appear with
 *     neither. The test also asserts writes DID happen, so it cannot pass
 *     vacuously.
 *  2. STRUCTURAL. The HTTP-layer source contains no persisting call against
 *     these models or tables, and does reference the managers/resolver — a
 *     tripwire for a future edit that adds a direct write on a code path the
 *     runtime flows do not exercise.
 *
 * `PackageSnapshotService` is deliberately never referenced from the HTTP
 * layer: snapshots are Slice 17's to take, and this slice must not change or
 * even touch that service.
 */
class CatalogControllerBoundaryTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    private const HTTP_LAYER_FILES = [
        'app/Http/Controllers/Customer/Business/CatalogItemsController.php',
        'app/Http/Controllers/Customer/Business/CatalogLocationOffersController.php',
        'app/Http/Controllers/Customer/Business/Concerns/AuthorizesCatalogRequests.php',
        'app/Library/Catalog/CatalogLocationOfferReader.php',
        'app/Library/Catalog/CatalogMoney.php',
    ];

    private const GUARDED_TABLES = ['catalog_items', 'catalog_item_location_overrides', 'package_snapshots'];

    private const GUARDED_MODELS = ['CatalogItem', 'CatalogItemLocationOverride', 'PackageSnapshot'];

    // ----------------------------------------------------------------- runtime

    public function test_every_write_to_the_catalog_tables_comes_through_a_domain_manager(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $a = $this->catalogItem($business, 'A');
        $b = $this->catalogItem($business, 'B');
        $this->authenticateAs($owner);

        $writes = [];
        $unmanaged = [];

        DB::listen(function (QueryExecuted $query) use (&$writes, &$unmanaged): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) !== 1) {
                return;
            }

            $touched = false;

            foreach (self::GUARDED_TABLES as $table) {
                if (str_contains($query->sql, '`' . $table . '`')) {
                    $touched = true;
                }
            }

            if (! $touched) {
                return;
            }

            $writes[] = $query->sql;

            $classes = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'class');

            if (! in_array(CatalogItemManager::class, $classes, true)
                && ! in_array(CatalogItemLocationOverrideManager::class, $classes, true)) {
                $unmanaged[] = $query->sql;
            }
        });

        // Every customer write flow, end to end.
        $this->post($this->catalogRoute('store', $workspace, $business), ['type' => 'package', 'name' => 'New', 'price' => '10.00', 'currency_code' => 'USD']);
        $this->post($this->catalogRoute('update', $workspace, $business, [$a->uid]), ['type' => 'package', 'name' => 'A2', 'price' => '11.00', 'currency_code' => 'USD']);
        $this->post($this->catalogRoute('archive', $workspace, $business, [$b->uid]));
        $this->post($this->catalogRoute('reactivate', $workspace, $business, [$b->uid]));
        $this->post($this->catalogRoute('reorder', $workspace, $business), ['order' => [$b->uid, $a->uid, (string) DB::table('catalog_items')->orderByDesc('id')->value('uid')]]);
        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$location->uid, $a->uid]), ['is_enabled' => 0]);
        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$location->uid, $a->uid]), ['price' => '5.00']);
        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$location->uid, $a->uid]), ['is_enabled' => 1]);
        $this->post($this->catalogRoute('locations.price', $workspace, $business, [$location->uid, $a->uid]), ['price' => '']);

        $this->assertGreaterThanOrEqual(
            8,
            count($writes),
            'The flows above must actually write; a boundary test that observed no writes proves nothing.'
        );

        $this->assertSame(
            [],
            $unmanaged,
            "These writes to catalog tables did NOT come through a domain manager:\n" . implode("\n", $unmanaged)
        );
    }

    public function test_no_http_flow_ever_touches_package_snapshots(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $location = $this->catalogLocation($business, 'Downtown');
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        $touched = [];

        DB::listen(function (QueryExecuted $query) use (&$touched): void {
            if (str_contains($query->sql, '`package_snapshots`')) {
                $touched[] = $query->sql;
            }
        });

        foreach ($this->catalogEveryRoute($workspace, $business, $location, $item) as [$method, $url, $payload]) {
            $method === 'GET' ? $this->get($url) : $this->post($url, $payload);
        }

        $this->assertSame([], $touched, 'The catalog UI must never read or write package_snapshots (Slice 17 owns them).');
        $this->assertSame(0, DB::table('package_snapshots')->count());
    }

    // -------------------------------------------------------------- structural

    public function test_the_http_layer_contains_no_direct_write_against_the_catalog_models_or_tables(): void
    {
        $offenders = [];

        foreach (self::HTTP_LAYER_FILES as $relative) {
            $source = $this->source($relative);

            foreach (self::GUARDED_TABLES as $table) {
                if (preg_match('/DB::table\(\s*[\'"]' . $table . '[\'"]/', $source)) {
                    $offenders[] = "{$relative} touches {$table} through the query builder";
                }
            }

            foreach (self::GUARDED_MODELS as $model) {
                if (preg_match('/\b' . $model . '::\s*(create|forceCreate|insert|insertGetId|updateOrCreate|firstOrCreate|update|delete|forceDelete|truncate|upsert|increment|decrement)\s*\(/', $source)) {
                    $offenders[] = "{$relative} writes {$model} statically";
                }

                if (preg_match('/new\s+' . $model . '\s*\(/', $source)) {
                    $offenders[] = "{$relative} instantiates {$model}";
                }
            }

            // Instance-shaped persistence. `->update(` is only flagged when the
            // receiver is not one of the two injected managers.
            if (preg_match('/->\s*(save|forceFill|fill|delete|forceDelete|increment|decrement|insert)\s*\(/', $source)) {
                $offenders[] = "{$relative} calls a persisting method directly";
            }

            if (preg_match('/(?<!\$this->items)(?<!\$this->overrides)->\s*update\s*\(/', $source)) {
                $offenders[] = "{$relative} calls ->update() on something other than a domain manager";
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_the_controllers_use_the_domain_managers_and_the_resolver(): void
    {
        $items = $this->source('app/Http/Controllers/Customer/Business/CatalogItemsController.php');
        $locations = $this->source('app/Http/Controllers/Customer/Business/CatalogLocationOffersController.php');
        $reader = $this->source('app/Library/Catalog/CatalogLocationOfferReader.php');

        $this->assertStringContainsString('CatalogItemManager', $items);

        foreach (['create', 'update', 'archive', 'reactivate', 'reorder'] as $method) {
            $this->assertMatchesRegularExpression(
                '/\$this->items->' . $method . '\(/',
                $items,
                "CatalogItemsController must call CatalogItemManager::{$method}()."
            );
        }

        $this->assertStringContainsString('CatalogItemLocationOverrideManager', $locations);
        $this->assertMatchesRegularExpression('/\$this->overrides->setEnabled\(/', $locations);
        $this->assertMatchesRegularExpression('/\$this->overrides->setPriceOverride\(/', $locations);

        // Effective price: ONLY the resolver.
        $this->assertStringContainsString('CatalogItemPricingResolver', $reader);
        $this->assertMatchesRegularExpression('/\$this->resolver->resolve\(/', $reader);
    }

    public function test_the_http_layer_never_references_the_snapshot_service(): void
    {
        foreach (self::HTTP_LAYER_FILES as $relative) {
            $source = $this->source($relative);

            $this->assertStringNotContainsString('PackageSnapshotService', $source, $relative);
            $this->assertStringNotContainsString('PackageSnapshot', $source, $relative);
        }
    }

    /**
     * The views are presentation only: no query, no write, no price rule.
     * The effective price a page shows comes from CatalogLocationOfferReader.
     */
    public function test_the_views_run_no_queries_and_do_not_resolve_prices_themselves(): void
    {
        $directory = base_path('resources/views/customer/business/catalog');
        $offenders = [];

        foreach (glob($directory . '/*.blade.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);

            foreach (['DB::', 'CatalogItem::', 'CatalogItemLocationOverride::', '->save(', '->forceFill(', 'PricingResolver', 'PackageSnapshot'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = "{$name} contains [{$needle}]";
                }
            }
        }

        $this->assertNotEmpty(glob($directory . '/*.blade.php'), 'The catalog views must exist.');
        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    private function source(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, $relative);

        return (string) file_get_contents($path);
    }
}
