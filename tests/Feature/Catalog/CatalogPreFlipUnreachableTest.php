<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Models\CatalogItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §11/§12.E — THE PRE-FLIP PROOF.
 *
 * Until `PlatformFeature::PackagesProducts` flips to `Available`, the catalog
 * must be customer-UNREACHABLE — for everyone, INCLUDING a fully authorized
 * account owner. That is the entire mechanism (RFC-004 §11/§12) that lets the
 * controllers, routes and views merge before the feature is genuinely usable.
 *
 * This file exists to be run BEFORE the flip and to be DELETED BY the flip
 * commit, which replaces it with the post-flip reachability tests. If it is
 * still present once the feature is Available, its first assertion fails
 * loudly, on purpose: a stale "unreachable" test must not survive the flip.
 */
class CatalogPreFlipUnreachableTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    public function test_the_feature_is_still_planned_which_is_the_premise_of_this_file(): void
    {
        $this->assertTrue(PlatformFeature::PackagesProducts->value === 'packages_products');
        $this->assertFalse(
            PlatformFeatureRegistry::isAvailable(PlatformFeature::PackagesProducts->value),
            'This is the PRE-flip proof. Once the feature is Available, delete this file.'
        );
    }

    public function test_every_route_is_unreachable_for_a_fully_authorized_owner(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant(WorkspacePlanTier::Core);
        $location = $this->catalogLocation($business, 'Downtown');

        // The owner satisfies gates 1 and 2 completely: they own the Workspace
        // and Business, and hold EVERY customer permission, including
        // packages_products. Only the entitlement gate stands in the way.
        $this->authenticateAs($owner);

        // Created directly: the catalog manager is domain code and is not
        // itself gated, so the pre-flip fixture can exist while HTTP cannot
        // reach it.
        $item = $this->catalogItem($business);

        foreach ($this->catalogEveryRoute($workspace, $business, $location, $item) as $label => [$method, $url, $payload]) {
            $response = $method === 'GET' ? $this->get($url) : $this->post($url, $payload);

            $response->assertNotFound('[' . $label . '] must be unreachable while the feature is Planned.');
        }
    }

    /**
     * The 404 above must be the ENTITLEMENT gate's doing — not a routing or
     * capability accident — so this asks the entitlement authority directly.
     */
    public function test_the_refusal_is_the_entitlement_gate_and_names_the_planned_feature(): void
    {
        [, $business, $workspace] = $this->catalogTenant(WorkspacePlanTier::Core);

        $decision = app(EntitlementManager::class)->decide(
            $workspace,
            $business,
            PlatformFeature::PackagesProducts->value,
            $this->platformAdminId()
        );

        $this->assertFalse($decision->allowed);
        $this->assertSame('platform_feature_unavailable', $decision->reason);
    }

    public function test_a_refused_pre_flip_write_creates_nothing(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $before = DB::table('catalog_items')->count();

        $this->post($this->catalogRoute('store', $workspace, $business), [
            'type' => 'package', 'name' => 'Should not exist', 'price' => '10.00', 'currency_code' => 'USD',
        ])->assertNotFound();

        $this->assertSame($before, DB::table('catalog_items')->count());
        $this->assertSame(0, CatalogItem::query()->where('name', 'Should not exist')->count());
    }

    public function test_the_nav_entry_is_hidden_pre_flip_even_for_a_fully_authorized_owner(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $html = $this->home()
            ->assertOk()
            ->getContent();

        $this->assertNotContains('packages_products', $this->menuKeys($html));
    }
}
