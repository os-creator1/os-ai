<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\PlatformFeatureAvailability;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §2 / §32.1 — the binding packaging decision:
 * GROWTH and AGENCY only, CORE excluded, no add-on.
 */
class GoogleBusinessProfileEntitlementTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /**
     * T-PKG-1 — packaging, asserted against the migrated schema.
     */
    public function test_growth_and_agency_are_packaged_and_core_is_not(): void
    {
        foreach (['growth', 'agency'] as $tier) {
            $catalogId = DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id');

            $this->assertNotNull($catalogId, "The {$tier} catalog tier must exist.");
            $this->assertDatabaseHas('workspace_plan_features', [
                'workspace_plan_catalog_id' => $catalogId,
                'feature_key' => PlatformFeature::GoogleBusinessProfileModule->value,
            ]);
        }

        $coreId = DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id');

        $this->assertDatabaseMissing('workspace_plan_features', [
            'workspace_plan_catalog_id' => $coreId,
            'feature_key' => PlatformFeature::GoogleBusinessProfileModule->value,
        ]);
    }

    /**
     * T-PKG-2 — the classification row exists, which is exactly what stops
     * 2026_08_16_120008 throwing
     * PlatformFeatureUsageClassificationBackfillIncompleteException on a
     * fresh migrate. RefreshDatabase has already run the full chain to get
     * here, so reaching this assertion at all is the proof.
     */
    public function test_the_new_feature_is_usage_classified_and_not_metered(): void
    {
        $this->assertDatabaseHas('platform_feature_usage_classifications', [
            'feature_key' => PlatformFeature::GoogleBusinessProfileModule->value,
            'is_metered' => false,
        ]);
    }

    /**
     * Contract §2.2 — Available, and Business-scoped by DEFAULT (no SCOPE
     * entry). T-ENT-5's guard: without the AVAILABILITY entry, decide()
     * would deny with platform_feature_unavailable.
     */
    public function test_the_feature_is_available_and_business_scoped(): void
    {
        $key = PlatformFeature::GoogleBusinessProfileModule->value;

        $this->assertSame('google_business_profile_module', $key);
        $this->assertTrue(PlatformFeatureRegistry::isKnown($key));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable($key));
        $this->assertTrue(PlatformFeatureRegistry::isBusinessScoped($key));
        $this->assertFalse(PlatformFeatureRegistry::isWorkspaceScoped($key));
    }

    /**
     * No regression to the registry: every pre-existing case keeps its
     * availability, and ProspectOutreach stays the sole Workspace-scoped
     * exception.
     */
    public function test_existing_registry_entries_are_unchanged(): void
    {
        foreach ([
            PlatformFeature::Crm,
            PlatformFeature::Conversations,
            PlatformFeature::Automations,
            PlatformFeature::ProspectOutreach,
            PlatformFeature::WebsiteGeneration,
        ] as $available) {
            $this->assertTrue(
                PlatformFeatureRegistry::isAvailable($available->value),
                $available->value . ' must remain Available.',
            );
        }

        foreach ([
            PlatformFeature::SeoModule,
            PlatformFeature::GoogleAdsModule,
            PlatformFeature::MetaAdsModule,
            PlatformFeature::Calendar,
            PlatformFeature::Forms,
        ] as $planned) {
            $this->assertFalse(
                PlatformFeatureRegistry::isAvailable($planned->value),
                $planned->value . ' must remain Planned.',
            );
        }

        $this->assertTrue(PlatformFeatureRegistry::isWorkspaceScoped(PlatformFeature::ProspectOutreach->value));
        $this->assertNotSame(PlatformFeatureAvailability::Planned, PlatformFeatureAvailability::Available);
    }

    /** T-ENT-1 — Growth reaches the surface. */
    public function test_growth_business_reaches_the_gbp_surface(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    /** T-ENT-2 — Agency reaches the surface. */
    public function test_agency_business_reaches_the_gbp_surface(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Agency);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertOk();
    }

    /**
     * T-ENT-3 — Core is excluded from EVERY route, GET and POST alike, and
     * the failure is 404 (never 403).
     */
    public function test_core_business_is_denied_on_every_route(): void
    {
        [$customer, $business, $workspace] = $this->coreTenant();
        $this->authenticateAsCustomer($customer);

        $args = [$workspace->uid, $business->uid];

        foreach (['index', 'comparison', 'settings', 'connect', 'callback', 'locations'] as $name) {
            $this->get(route('customer.workspaces.businesses.gbp.' . $name, $args))
                ->assertNotFound();
        }

        $this->post(route('customer.workspaces.businesses.gbp.bind', $args), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => 'whatever',
        ])->assertNotFound();

        $this->post(route('customer.workspaces.businesses.gbp.refresh', $args))->assertNotFound();
    }

    /**
     * T-ENT-4 — a forged direct request is refused SERVER-SIDE. Navigation
     * is irrelevant: the request never renders a link.
     */
    public function test_core_business_cannot_force_a_connection_by_posting_directly(): void
    {
        [$customer, $business, $workspace] = $this->coreTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertNotFound();

        $this->assertDatabaseCount('business_google_connections', 0);
        $this->assertSame(0, $this->fakeGoogle->callCount('authorizationUrl'));
    }

    /**
     * T-ENT-5 — decide() itself denies for Core with the plan reason, so
     * the gate is the entitlement engine and not merely the controller.
     */
    public function test_entitlement_manager_denies_core_and_allows_growth(): void
    {
        [, $coreBusiness, $coreWorkspace] = $this->coreTenant();
        [, $growthBusiness, $growthWorkspace] = $this->entitledTenant(WorkspacePlanTier::Growth);

        $manager = app(EntitlementManager::class);
        $key = PlatformFeature::GoogleBusinessProfileModule->value;

        $core = $manager->decide($coreWorkspace, $coreBusiness, $key, $this->platformAdminId());
        $growth = $manager->decide($growthWorkspace, $growthBusiness, $key, $this->platformAdminId());

        $this->assertFalse($core->allowed);
        $this->assertSame('not_entitled_by_plan', $core->reason);
        $this->assertTrue($growth->allowed);
    }

    /**
     * T-TEN-1 — the 0/1/many chooser. A Core Business is not "accessible"
     * for this purpose, so it never appears (contract §17.1).
     */
    public function test_bare_entry_route_handles_zero_one_and_many_businesses(): void
    {
        // Zero eligible: a Core-only customer.
        [$coreCustomer] = $this->coreTenant();
        $this->authenticateAsCustomer($coreCustomer);
        $this->get(route('customer.gbp.index'))
            ->assertOk()
            ->assertSee('No Business available yet');

        // Exactly one eligible: redirect straight through.
        [$oneCustomer, $oneBusiness, $oneWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($oneCustomer);
        $this->get(route('customer.gbp.index'))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$oneWorkspace->uid, $oneBusiness->uid]));

        // Many eligible: a chooser, never a guess.
        $secondBusiness = $this->createBusinessWithWorkspace($oneCustomer, $this->businessAttributes(['name' => 'Second Co']));
        DB::table('businesses')->where('id', $secondBusiness->id)->update(['status' => \App\Enums\Business\BusinessStatus::Active->value]);
        app(EntitlementManager::class)->assignFirstPlan(
            \App\Models\Workspace::query()->findOrFail($secondBusiness->workspace_id),
            WorkspacePlanTier::Agency,
            $this->platformAdminId(),
            'Second tenant.',
            true,
            0,
        );

        $this->authenticateAsCustomer($oneCustomer);
        $this->get(route('customer.gbp.index'))
            ->assertOk()
            ->assertSee('Choose a Business to continue');
    }
}
