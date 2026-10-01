<?php

namespace Tests\Feature\Forms;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Models\Customer;
use App\Models\PlatformFeatureUsageClassification;
use App\Models\WorkspacePlanCatalog;
use App\Models\WorkspacePlanFeature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Forms V1 — the entitlement identity: Forms authorizes itself.
 *
 * Proves, in one place, the facts the flip depends on: the feature is Available
 * and Business-scoped, it was ALREADY packaged for every tier (so no packaging
 * migration was needed or added), its usage classification is the inert,
 * unmetered row every feature has, and the customer capability is its own key.
 */
class FormsEntitlementIdentityTest extends TestCase
{
    use CreatesFormsFixtures;
    use RefreshDatabase;

    public function test_forms_is_available_and_business_scoped_after_the_standalone_module_landed(): void
    {
        $this->assertSame('forms', PlatformFeature::Forms->value);
        $this->assertTrue(PlatformFeatureRegistry::isAvailable(PlatformFeature::Forms->value));
        $this->assertFalse(PlatformFeatureRegistry::isWorkspaceScoped(PlatformFeature::Forms->value));
        $this->assertContains('forms', CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
    }

    public function test_forms_was_already_packaged_for_core_growth_and_agency_without_any_new_packaging(): void
    {
        foreach (['core', 'growth', 'agency'] as $tier) {
            $catalog = WorkspacePlanCatalog::where('tier', $tier)->firstOrFail();

            $this->assertTrue(
                WorkspacePlanFeature::where('workspace_plan_catalog_id', $catalog->id)->where('feature_key', 'forms')->exists(),
                "{$tier} must package forms (Blueprint §21)"
            );
        }

        $this->assertSame([], $this->migrationsMentioning('workspace_plan_features'), 'this slice adds no packaging migration');
    }

    public function test_the_usage_classification_row_is_inert_unmetered_and_unpriced(): void
    {
        $row = PlatformFeatureUsageClassification::where('feature_key', 'forms')->firstOrFail();

        $this->assertFalse((bool) $row->is_metered);
        $this->assertNull($row->active_rate_id);
    }

    public function test_every_plan_tier_reaches_forms_through_the_real_entitlement_authority(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$owner, $business, $workspace] = $this->formsTenant($tier, 'Tier '.$tier->value.' Studio');
            $this->authenticateAs($owner);

            $this->get($this->formsRoute('index', $workspace, $business))->assertOk();
        }
    }

    public function test_the_forms_capability_is_its_own_default_on_key_and_new_customers_receive_it(): void
    {
        $permission = config('customer-permissions.forms');

        $this->assertNotNull($permission);
        $this->assertSame('forms', $permission['display_name']);
        $this->assertTrue($permission['default']);
        $this->assertArrayHasKey('website', config('customer-permissions'), 'the website key stays its own, separate key');

        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        // customerPermissions() hands back the JSON list a new customer is stored with.
        $this->assertContains('forms', json_decode((string) Customer::customerPermissions(), true));
        $this->assertNotNull($customer);
    }

    /**
     * Migration files (other than the standalone Forms ones) that touch a table.
     *
     * @return list<string>
     */
    private function migrationsMentioning(string $table): array
    {
        $found = [];
        foreach (glob(database_path('migrations/2026_10_14_1200*.php')) as $path) {
            if (str_contains((string) file_get_contents($path), $table)) {
                $found[] = basename($path);
            }
        }

        return $found;
    }
}
