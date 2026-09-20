<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 16 §11/§12.E — the FINAL ACTIVATION of Packages &
 * Products, and the prerequisites that had to hold before it was flipped.
 *
 * Each prerequisite is asserted directly, so the flip is not an act of faith:
 * the capability exists and defaults on, the entitlement is packaged in every
 * tier, the registry is Available, and the customer surface actually opens for
 * a correctly authorized actor.
 */
class CatalogActivationTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    private const FEATURE = 'packages_products';

    // --------------------------------------------------------- the capability

    public function test_the_packages_products_capability_exists_with_the_contracted_shape(): void
    {
        $entry = config('customer-permissions.' . self::FEATURE);

        $this->assertIsArray($entry, 'config/customer-permissions.php must declare packages_products.');
        $this->assertSame(['display_name', 'category', 'default'], array_keys($entry), 'The simple single-key shape automations/website use.');
        $this->assertTrue($entry['default'], 'Default true, following the website precedent.');
        $this->assertSame('Packages & Products', $entry['category']);
    }

    /** One key, not a CRUD matrix (§15). */
    public function test_there_is_exactly_one_catalog_capability_key(): void
    {
        $catalogKeys = array_filter(
            array_keys(config('customer-permissions')),
            fn (string $key) => str_contains($key, 'package') || str_contains($key, 'catalog')
        );

        $this->assertSame([self::FEATURE], array_values($catalogKeys));
    }

    public function test_the_capability_is_registered_as_a_gate_and_reaches_new_customers(): void
    {
        $this->assertTrue(Gate::has(self::FEATURE));

        // The list a NEW customer is created with (Customer::customerPermissions
        // — the operator-editable default, else the config defaults).
        $this->ensureRequiredAppConfigRowsExist();

        $this->assertContains(
            self::FEATURE,
            json_decode((string) \App\Models\Customer::customerPermissions(), true),
            'A newly created customer must inherit the capability.'
        );
    }

    // ----------------------------------------------------- the backfill migration

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_09_26_100001_backfill_packages_products_customer_permission.php');
        $migration->up();
    }

    public function test_the_backfill_grants_the_capability_to_existing_customers_and_is_idempotent(): void
    {
        [$owner] = $this->catalogTenant();

        // An "existing" customer created before this key existed. (Fixture
        // customers carry null permissions and rely on the session, so a real
        // persisted list is set explicitly here.)
        $withoutKey = ['view_contact', 'automations', 'website'];
        DB::table('customers')->where('id', $owner->id)->update(['permissions' => json_encode($withoutKey)]);

        $this->runBackfill();

        $granted = json_decode((string) DB::table('customers')->where('id', $owner->id)->value('permissions'), true);
        $this->assertContains(self::FEATURE, $granted);
        $this->assertSame(array_merge($withoutKey, [self::FEATURE]), $granted, 'Existing permissions are preserved, in order, with the key appended.');

        // Idempotent: a second run changes nothing and does not duplicate.
        $this->runBackfill();
        $again = json_decode((string) DB::table('customers')->where('id', $owner->id)->value('permissions'), true);
        $this->assertSame($granted, $again);
        $this->assertSame(1, count(array_keys($again, self::FEATURE, true)));
    }

    public function test_the_backfill_never_invents_a_permission_list_where_none_exists(): void
    {
        [$owner] = $this->catalogTenant();

        foreach ([null, '', '[]', 'not json', '{"a":1}'] as $value) {
            DB::table('customers')->where('id', $owner->id)->update(['permissions' => $value]);

            $this->runBackfill();

            $this->assertSame(
                $value,
                DB::table('customers')->where('id', $owner->id)->value('permissions'),
                'A null/empty/non-list permissions value must be left untouched: ' . var_export($value, true)
            );
        }
    }

    public function test_the_backfill_also_updates_the_operator_editable_default_list(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $default = DB::table('app_config')->where('setting', 'customer_permissions')->first();
        $this->assertNotNull($default, 'The fixture ensures the app_config default row exists.');

        $without = array_values(array_diff(json_decode((string) $default->value, true), [self::FEATURE]));
        DB::table('app_config')->where('setting', 'customer_permissions')->update(['value' => json_encode($without)]);

        $this->runBackfill();

        $this->assertContains(
            self::FEATURE,
            json_decode((string) DB::table('app_config')->where('setting', 'customer_permissions')->value('value'), true)
        );
    }

    // ------------------------------------------------- the entitlement + the flip

    public function test_the_feature_is_known_business_scoped_and_now_available(): void
    {
        $this->assertTrue(PlatformFeatureRegistry::isKnown(self::FEATURE));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped(self::FEATURE));
        $this->assertTrue(
            PlatformFeatureRegistry::isAvailable(self::FEATURE),
            'The final flip: Planned -> Available.'
        );
    }

    public function test_the_feature_is_packaged_in_every_tier_before_the_flip_could_matter(): void
    {
        $catalogIds = DB::table('workspace_plan_catalog')->pluck('id', 'tier');

        foreach (['core', 'growth', 'agency'] as $tier) {
            $this->assertDatabaseHas('workspace_plan_features', [
                'workspace_plan_catalog_id' => $catalogIds[$tier],
                'feature_key' => self::FEATURE,
            ]);
        }
    }

    public function test_the_entitlement_authority_now_allows_every_tier(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [, $business, $workspace] = $this->catalogTenant($tier, 'Tier ' . $tier->value . ' Studio');

            $decision = app(EntitlementManager::class)->decide($workspace, $business, self::FEATURE, $this->platformAdminId());

            $this->assertTrue($decision->allowed, $tier->value . ' must be entitled; reason: ' . ($decision->reason ?? 'none'));
        }
    }

    /**
     * The flip changes availability ONLY. Plan mapping, overrides and status
     * still decide: an override-denied or unassigned Workspace is refused with
     * the SAME reasons as any other feature.
     */
    public function test_the_flip_does_not_bypass_the_rest_of_the_entitlement_chain(): void
    {
        [, $business, $workspace] = $this->catalogTenant();
        $this->denyCatalogEntitlement($workspace);

        $decision = app(EntitlementManager::class)->decide($workspace, $business, self::FEATURE, $this->platformAdminId());

        $this->assertFalse($decision->allowed);
        $this->assertSame('denied_by_workspace_override', $decision->reason);
    }

    // ------------------------------------------------------ reachable after flip

    public function test_a_correctly_authorized_actor_can_now_reach_and_use_the_feature(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant(WorkspacePlanTier::Core);
        $this->authenticateAs($owner);

        $this->get($this->catalogRoute('index', $workspace, $business))->assertOk()->assertSee('Packages');

        $this->post($this->catalogRoute('store', $workspace, $business), [
            'type' => 'package', 'name' => 'First Package', 'price' => '99.00', 'currency_code' => 'USD',
        ])->assertRedirect();

        $this->get($this->catalogRoute('index', $workspace, $business))->assertOk()->assertSee('First Package')->assertSee('USD 99.00');
    }
}
