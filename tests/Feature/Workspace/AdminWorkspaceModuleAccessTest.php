<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Entitlement\WorkspaceModuleCatalog;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Platform-admin Module Access: per-Workspace module switches layered on the
 * existing WorkspaceEntitlementOverride rows via EntitlementManager.
 */
class AdminWorkspaceModuleAccessTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;
    use \Tests\Feature\GoogleAds\Reporting\Concerns\SeedsGoogleAdsReportingData;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function actAsPlatformAdmin(array $permissions = ['access backend', 'view workspace', 'view workspace plans', 'manage workspace plans']): void
    {
        $this->admin = User::create([
            'first_name' => 'Module', 'last_name' => 'Admin',
            'email' => 'moduleadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($this->admin);
    }

    private function saveModules(Workspace $workspace, array $modules, string $reason = 'Customer does not use this module.')
    {
        return $this->put(route('admin.workspaces.module-access.update', $workspace), ['modules' => $modules, 'reason' => $reason]);
    }

    private function navKeys(): array
    {
        preg_match_all('/data-nav-key="([^"]+)"/', $this->sidebarHtml($this->home()->assertOk()->getContent()), $matches);

        return $matches[1];
    }

    private function adsUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.ads.index', [$workspace->uid, $business->uid]);
    }

    private function seoUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.seo.index', [$workspace->uid, $business->uid]);
    }

    private function crmUrl(Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.crm.board', [$workspace->uid, $business->uid]);
    }

    /** @return array<string, string> feature key => override state */
    private function overrideRows(Workspace $workspace): array
    {
        return DB::table('workspace_entitlement_overrides')->where('workspace_id', $workspace->id)->pluck('state', 'feature_key')->all();
    }

    public function test_disabling_ads_for_one_workspace_hides_and_blocks_ads_only_for_it(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Booths To The Max', 'Booths A');
        [$customerB, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Other Studio', 'Other B');

        $this->authenticateAs($customerA);
        $this->assertContains('ads', $this->navKeys());
        $this->get($this->adsUrl($workspaceA, $businessA))->assertOk();

        $this->actAsPlatformAdmin();
        $this->saveModules($workspaceA, ['ads' => 'disabled'])->assertRedirect(route('admin.workspaces.show', $workspaceA));

        // Every Ads access key is denied for A, nothing else is.
        $this->assertSame(
            array_fill_keys(AdsFeatureAccess::anyAdsKeys(), 'deny'),
            $this->overrideRows($workspaceA),
        );
        $this->assertSame([], $this->overrideRows($workspaceB));

        // A: navigation and direct URL are gone; the rest of the product still works.
        $this->authenticateAs($customerA);
        $keys = $this->navKeys();
        $this->assertNotContains('ads', $keys);
        $this->assertContains('seo', $keys);
        $this->get($this->adsUrl($workspaceA, $businessA))->assertNotFound();
        $this->assertNotSame(404, $this->get($this->seoUrl($workspaceA, $businessA))->getStatusCode());
        $this->assertNotSame(404, $this->get($this->crmUrl($workspaceA, $businessA))->getStatusCode());

        // B is unaffected.
        $this->authenticateAs($customerB);
        $this->assertContains('ads', $this->navKeys());
        $this->get($this->adsUrl($workspaceB, $businessB))->assertOk();
    }

    public function test_plan_default_restores_access_and_existing_data_is_untouched(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $businessBefore = DB::table('businesses')->where('id', $business->id)->first();
        $assignmentBefore = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();

        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['ads' => 'disabled']);

        $this->authenticateAs($customer);
        $this->get($this->adsUrl($workspace, $business))->assertNotFound();

        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['ads' => 'default'])->assertRedirect();
        $this->assertSame([], $this->overrideRows($workspace));

        $this->authenticateAs($customer);
        $this->get($this->adsUrl($workspace, $business))->assertOk();
        $this->assertContains('ads', $this->navKeys());

        // Only gating rows ever changed: the Business and the plan/billing row are byte-identical.
        $this->assertEquals($businessBefore, DB::table('businesses')->where('id', $business->id)->first());
        $this->assertEquals($assignmentBefore, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first());
    }

    public function test_changes_are_audited_with_actor_workspace_feature_and_states(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['ads' => 'disabled'], 'Booths To The Max does not run ads.');
        $this->saveModules($workspace, ['ads' => 'default']);

        foreach (AdsFeatureAccess::anyAdsKeys() as $key) {
            $this->assertDatabaseHas('workspace_entitlement_transitions', [
                'workspace_id' => $workspace->id,
                'actor_user_id' => $this->admin->id,
                'feature_key' => $key,
                'from_override_state' => null,
                'to_override_state' => 'deny',
                'reason' => 'Booths To The Max does not run ads.',
            ]);
            $this->assertDatabaseHas('workspace_entitlement_transitions', [
                'workspace_id' => $workspace->id,
                'actor_user_id' => $this->admin->id,
                'feature_key' => $key,
                'from_override_state' => 'deny',
                'to_override_state' => null,
            ]);
        }
    }

    public function test_only_platform_administrators_with_the_manage_permission_can_change_modules(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        // Guest.
        $this->saveModules($workspace, ['ads' => 'disabled'])->assertUnauthorized();

        // Customer user.
        $this->authenticateAs($customer);
        $this->saveModules($workspace, ['ads' => 'disabled'])->assertUnauthorized();

        // Admin who can only view plans.
        $this->actAsPlatformAdmin(['access backend', 'view workspace plans']);
        $this->saveModules($workspace, ['ads' => 'disabled'])->assertUnauthorized();

        $this->assertSame([], $this->overrideRows($workspace));
    }

    public function test_the_manager_rejects_a_non_admin_actor_directly(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(\App\Library\Entitlement\EntitlementManager::class)
            ->applyWorkspaceModuleAccess($workspace, ['ads' => 'disabled'], $customer->user->id, 'Attempt');
    }

    public function test_the_section_is_rendered_for_managers_only_with_effective_status(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['crm' => 'disabled']);

        $html = $this->get(route('admin.workspaces.show', $workspace))->assertOk()->getContent();

        $this->assertStringContainsString('id="admin-workspace-module-access"', $html);
        $this->assertStringContainsString('Google / Meta Ads', $html);
        $this->assertMatchesRegularExpression('/data-module="crm".*?data-effective="off"/s', $html);
        $this->assertMatchesRegularExpression('/data-module="conversations".*?data-effective="on"/s', $html);

        $this->actAsPlatformAdmin(['access backend', 'view workspace', 'view workspace plans']);
        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertDontSee('id="admin-workspace-module-access"', false);
    }

    public function test_enabled_is_only_offered_and_accepted_for_available_features(): void
    {
        foreach (WorkspaceModuleCatalog::modules() as $moduleId => $module) {
            $this->assertTrue(WorkspaceModuleCatalog::canEnable($moduleId), "{$moduleId} should be enable-able today.");
        }

        // A module whose enable keys are all Planned must never be enable-able.
        $this->assertFalse(PlatformFeatureRegistry::isAvailable(PlatformFeature::AgencyPackageCapabilities->value));
        $this->assertContains(PlatformFeature::AgencyPackageCapabilities->value, WorkspaceModuleCatalog::unmappedFeatureKeys());

        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['not_a_module' => 'enabled', 'ads' => 'bogus'])->assertSessionHasErrors('modules.ads');
        $this->assertSame([], $this->overrideRows($workspace));
    }

    public function test_every_feature_key_is_mapped_to_exactly_one_module_or_deliberately_unmapped(): void
    {
        $mapped = array_merge(...array_column(WorkspaceModuleCatalog::modules(), 'keys'));

        $this->assertSame($mapped, array_values(array_unique($mapped)), 'A key must belong to one module only.');
        $this->assertEqualsCanonicalizing(
            ['white_label', 'agency_package_capabilities', 'messaging_transport'],
            WorkspaceModuleCatalog::unmappedFeatureKeys(),
        );

        // Disabling Ads must cover every key Ads gates consult.
        $this->assertEqualsCanonicalizing(AdsFeatureAccess::anyAdsKeys(), WorkspaceModuleCatalog::modules()['ads']['keys']);
    }

    public function test_enabled_allows_the_canonical_keys_and_returns_legacy_keys_to_plan_default(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['ads' => 'disabled']);
        $this->saveModules($workspace, ['ads' => 'enabled']);

        $this->assertSame(
            ['ads_basic_visibility' => 'allow', 'ads_module' => 'allow'],
            $this->overrideRows($workspace),
        );
        $this->assertSame(
            WorkspaceEntitlementOverrideState::Allow,
            \App\Models\WorkspaceEntitlementOverride::query()->where('workspace_id', $workspace->id)->where('feature_key', 'ads_module')->firstOrFail()->state,
        );
    }

    public function test_a_failure_midway_rolls_back_every_module_change_in_the_request(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->actAsPlatformAdmin();

        $transitionsBefore = DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->count();

        // Ads (4 rows) and CRM are written before the third entry fails.
        try {
            app(\App\Library\Entitlement\EntitlementManager::class)->applyWorkspaceModuleAccess(
                $workspace,
                ['ads' => 'disabled', 'crm' => 'disabled', 'forms' => 'bogus'],
                $this->admin->id,
                'Atomicity check.',
            );
            $this->fail('The invalid state must abort the whole request.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame([], $this->overrideRows($workspace));
        $this->assertSame($transitionsBefore, DB::table('workspace_entitlement_transitions')->where('workspace_id', $workspace->id)->count());

        // A key that is not a customer module (and a Planned one) is refused the same way.
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Library\Entitlement\EntitlementManager::class)->applyWorkspaceModuleAccess(
            $workspace, ['agency_package_capabilities' => 'enabled'], $this->admin->id, 'Unavailable.',
        );
    }

    public function test_disabling_and_restoring_ads_preserves_ads_data_and_integrations(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $account = $this->adsAccountFor($business);
        $this->seedCampaign($account, '111', 'Wedding Photo Booth');

        $tables = ['businesses', 'workspace_plan_assignments', 'business_google_connections', 'google_ads_accounts', 'google_ads_campaigns'];
        $snapshot = fn (): array => array_map(
            fn (string $t): array => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            array_combine($tables, $tables),
        );
        $before = $snapshot();
        $this->assertNotEmpty($before['google_ads_campaigns']);

        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['ads' => 'disabled']);

        $this->authenticateAs($customer);
        $this->get($this->adsUrl($workspace, $business))->assertNotFound();

        $this->assertEquals($before, $snapshot(), 'Disabling Ads must not modify or delete any stored data or integration.');

        $this->actAsPlatformAdmin();
        $this->saveModules($workspace, ['ads' => 'default']);
        $this->authenticateAs($customer);
        $this->get($this->adsUrl($workspace, $business))->assertOk();

        $this->assertEquals($before, $snapshot());
    }

    public function test_the_admin_display_shows_business_level_restrictions(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->actAsPlatformAdmin();

        app(\App\Library\Entitlement\EntitlementManager::class)
            ->disableBusinessFeature($business, PlatformFeature::Forms, (int) $workspace->owner_user_id, 'Customer choice.');

        $html = $this->get(route('admin.workspaces.show', $workspace))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-module="forms".*?data-effective="off".*?Turned off by the business/s', $html);
        $this->assertMatchesRegularExpression('/data-module="crm".*?data-effective="on"/s', $html);
    }
}
