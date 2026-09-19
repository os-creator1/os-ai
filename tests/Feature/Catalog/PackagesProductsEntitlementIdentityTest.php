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

    public function test_packages_products_stays_planned_not_available(): void
    {
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::PackagesProducts->value));
    }

    public function test_no_customer_route_exists_for_packages_products(): void
    {
        // The hard boundary this sub-slice must not cross: no controller,
        // route, or customer-facing surface for THIS feature — that is
        // Sub-slice E's job, gated behind this sub-slice's still-Planned
        // entitlement. Scoped to customer.* routes and specific catalog-item/
        // packages-products name fragments, deliberately excluding the
        // pre-existing, unrelated admin.workspace-plan-catalog.* routes
        // (the platform's own SaaS subscription-tier catalog — a different
        // bounded context, §4 of the contract).
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

    public function test_no_catalog_controller_or_later_sub_slice_class_exists_yet(): void
    {
        // Corrected for Sub-slice C (§12.C): CatalogItemLocationOverrideManager
        // and CatalogItemPricingResolver are exactly that sub-slice's own
        // deliverables — domain services with no customer HTTP surface of
        // their own — so their existence is now expected, alongside
        // Sub-slice B's CatalogItemManager. Only Sub-slice D/E's classes
        // remain absent.
        $this->assertTrue(class_exists('App\\Library\\Catalog\\CatalogItemManager'));
        $this->assertTrue(class_exists('App\\Library\\Catalog\\CatalogItemLocationOverrideManager'));
        $this->assertTrue(class_exists('App\\Library\\Catalog\\CatalogItemPricingResolver'));
        $this->assertFalse(class_exists('App\\Http\\Controllers\\Customer\\Business\\CatalogController'));
        $this->assertFalse(class_exists('App\\Http\\Controllers\\Customer\\Business\\CatalogItemController'));
        $this->assertFalse(class_exists('App\\Library\\Catalog\\PackageSnapshotService'));
    }
}
