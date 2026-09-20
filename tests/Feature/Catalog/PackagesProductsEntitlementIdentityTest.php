<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Implementation Contract 16 §8, §11, §12.A — the inert entitlement
 * identity this sub-slice introduces. Proves three independent facts:
 * PackagesProducts is packaged for all three tiers, the registry keeps it
 * Planned (so nothing built in later sub-slices can pass entitlement yet),
 * and — the hard boundary this sub-slice must not cross — no customer
 * route or controller for it exists in this codebase at all.
 */
class PackagesProductsEntitlementIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const FEATURE_KEY = 'packages_products';

    public function test_packages_products_is_packaged_for_core_growth_and_agency(): void
    {
        $catalogIds = DB::table('workspace_plan_catalog')->pluck('id', 'tier');

        foreach (['core', 'growth', 'agency'] as $tier) {
            $catalogId = $catalogIds[$tier] ?? null;
            $this->assertNotNull($catalogId, "Expected a workspace_plan_catalog row for tier [{$tier}].");

            $this->assertDatabaseHas('workspace_plan_features', [
                'workspace_plan_catalog_id' => $catalogId,
                'feature_key' => self::FEATURE_KEY,
            ]);
        }
    }

    public function test_packages_products_usage_classification_row_exists_and_is_unmetered(): void
    {
        $this->assertDatabaseHas('platform_feature_usage_classifications', [
            'feature_key' => self::FEATURE_KEY,
            'is_metered' => false,
            'active_rate_id' => null,
        ]);
    }

    public function test_every_platform_feature_case_has_exactly_one_usage_classification_row(): void
    {
        // The merged backfill migration's own completeness invariant must
        // still hold after this sub-slice's additive migration runs.
        $this->assertSame(
            count(PlatformFeature::cases()),
            DB::table('platform_feature_usage_classifications')->count(),
        );
    }

    public function test_packages_products_is_available_after_the_final_flip(): void
    {
        // Sub-slice E's flip. The full customer surface and its authorization
        // matrix are proven in CatalogAuthorizationMatrixTest and
        // CatalogActivationTest; this asserts only the registry fact this
        // file's Sub-slice A identity checks are about.
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::PackagesProducts->value));
    }

    public function test_no_alternate_ungated_customer_route_exists_for_packages_products(): void
    {
        // The customer catalog now exists (Sub-slice E), but only as the one
        // Business-scoped surface under `customer.workspaces.businesses.
        // catalog.*` (see CatalogRouteInventoryTest), every route of which
        // runs the full authorization chain. This needle-scan remains a
        // guard against any OTHER, differently-named customer route for the
        // feature appearing outside that gated surface. Scoped to customer.*
        // routes and specific catalog-item/packages-products name fragments,
        // deliberately excluding the pre-existing, unrelated
        // admin.workspace-plan-catalog.* routes (the platform's own SaaS
        // subscription-tier catalog — a different bounded context, §4 of the
        // contract).
        $needles = ['catalog-item', 'catalog_item', 'packages-products', 'packages_products'];

        foreach (Route::getRoutes() as $route) {
            $name = strtolower((string) $route->getName());
            $uri = strtolower($route->uri());

            if (! str_starts_with($name, 'customer.')) {
                continue;
            }

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $name, "Unexpected catalog route registered: {$name}");
                $this->assertStringNotContainsString($needle, $uri, "Unexpected catalog URI registered: {$uri}");
            }
        }
    }

    public function test_every_catalog_sub_slice_deliverable_now_exists(): void
    {
        // This replaces `test_no_catalog_controller_or_later_sub_slice_class_
        // exists_yet`, which asserted that Sub-slice D's PackageSnapshotService
        // and Sub-slice E's controllers did NOT exist. It was already failing
        // on `main` once Sub-slice D merged (D added the service without
        // updating it), and Sub-slice E's controllers retire the rest of it —
        // so the original could not survive this sub-slice and is inverted
        // rather than deleted: every deliverable of Sub-slices B, C, D and E
        // is asserted PRESENT, which is what is now true.
        foreach ([
            'App\\Library\\Catalog\\CatalogItemManager',                              // B
            'App\\Library\\Catalog\\CatalogItemLocationOverrideManager',             // C
            'App\\Library\\Catalog\\CatalogItemPricingResolver',                     // C
            'App\\Library\\Catalog\\PackageSnapshotService',                         // D
            'App\\Http\\Controllers\\Customer\\Business\\CatalogItemsController',           // E
            'App\\Http\\Controllers\\Customer\\Business\\CatalogLocationOffersController',  // E
        ] as $class) {
            $this->assertTrue(class_exists($class), $class . ' must exist.');
        }
    }
}
