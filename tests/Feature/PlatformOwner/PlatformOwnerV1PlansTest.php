<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\PlatformOwner\PlatformFeatureGroups;
use App\Models\PlatformAdminAction;
use App\Models\WorkspacePlanAssignment;
use App\Models\WorkspacePlanCatalog;
use App\Models\WorkspacePlanCatalogPricingChange;
use App\Repositories\Contracts\WorkspacePlanFeatureRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner V1 final — the one plan authority: edit, archive (never
 * delete), feature packaging, and who may do it.
 */
class PlatformOwnerV1PlansTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    /** @return array<string, mixed> */
    private function payload(WorkspacePlanTier $tier, array $overrides = []): array
    {
        $c = WorkspacePlanCatalog::query()->where('tier', $tier->value)->firstOrFail();
        $keys = app(WorkspacePlanFeatureRepository::class)->featureKeysForCatalog($c)->all();

        return array_merge([
            'display_name' => $c->display_name,
            'price' => '',
            'currency_id' => '',
            'billing_cycle' => $c->billing_cycle,
            'is_active' => '1',
            'available_for_signup' => '0',
            'trial_enabled' => '0',
            'business_slot_included' => $c->business_slot_included,
            'business_slot_max' => $c->business_slot_max,
            'unlimited_business_slots' => $c->unlimited_business_slots ? '1' : '0',
            'location_slot_included' => $c->location_slot_included,
            'location_slot_max' => $c->location_slot_max,
            'unlimited_location_slots' => $c->unlimited_location_slots ? '1' : '0',
            'feature_keys' => $keys,
            'reason' => 'Plan review.',
        ], $overrides);
    }

    private function savePlan(WorkspacePlanTier $tier, array $overrides = [])
    {
        return $this->from(route('admin.platform-plans.edit', $tier->value))
            ->put(route('admin.platform-plans.update', $tier->value), $this->payload($tier, $overrides));
    }

    // ---------------------------------------------------------------- authority

    public function test_only_a_platform_owner_with_the_permission_can_open_or_change_plans(): void
    {
        $this->get(route('admin.platform-plans.index'))->assertUnauthorized();

        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        $this->authenticateAs($customer);
        $this->get(route('admin.platform-plans.index'))->assertUnauthorized();
        $this->get(route('admin.platform-plans.edit', 'core'))->assertUnauthorized();
        $this->savePlan(WorkspacePlanTier::Core, ['display_name' => 'Hacked'])->assertUnauthorized();
        $this->assertSame('Core', WorkspacePlanCatalog::query()->where('tier', 'core')->value('display_name'));
    }

    public function test_an_administrator_without_the_manage_permission_can_view_but_not_change(): void
    {
        $this->actingAsPlatformOwner(['access backend', 'view workspace plans']);

        $this->get(route('admin.platform-plans.index'))->assertOk();
        $this->savePlan(WorkspacePlanTier::Core, ['display_name' => 'Nope'])->assertUnauthorized();
        $this->assertSame('Core', WorkspacePlanCatalog::query()->where('tier', 'core')->value('display_name'));
    }

    public function test_an_unknown_tier_is_not_found(): void
    {
        $this->actingAsPlatformOwner();
        $this->get(route('admin.platform-plans.edit', 'platinum'))->assertNotFound();
    }

    // ---------------------------------------------------------------- reading

    public function test_the_plans_page_lists_exactly_core_growth_agency_with_no_legacy_concepts(): void
    {
        $this->actingAsPlatformOwner();

        $html = $this->get(route('admin.platform-plans.index'))->assertOk()->getContent();
        $section = substr($html, strpos($html, 'admin-platform-plans-index'));

        foreach (['Core', 'Growth', 'Agency'] as $name) {
            $this->assertStringContainsString($name, $section);
        }

        foreach (['Sending Credit', 'DLT', 'SMS plan', 'Sender ID'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $section);
        }
    }

    public function test_the_edit_page_groups_features_by_product_area_not_raw_keys(): void
    {
        $this->actingAsPlatformOwner();
        $html = $this->get(route('admin.platform-plans.edit', 'growth'))->assertOk()->getContent();

        foreach (['CRM', 'Conversations', 'Calendar', 'Automations', 'Website', 'SEO', 'Ads', 'Forms', 'Packages', 'Payments &amp; Contracts', 'AI', 'Agency'] as $group) {
            $this->assertStringContainsString('<h6>' . $group . '</h6>', $html, "Missing group {$group}");
        }
    }

    public function test_every_platform_feature_is_in_a_group(): void
    {
        $grouped = array_merge(...array_values(PlatformFeatureGroups::all()));

        foreach (PlatformFeatureGroups::allKeys() as $key) {
            $this->assertArrayHasKey($key, $grouped);
        }
    }

    // ---------------------------------------------------------------- editing

    public function test_a_plan_edit_persists_and_is_audited(): void
    {
        $owner = $this->actingAsPlatformOwner();

        $this->savePlan(WorkspacePlanTier::Growth, [
            'display_name' => 'Growth Plus',
            'price' => '149.00',
            'currency_id' => $this->fixtureCurrencyId(),
            'billing_cycle' => 'yearly',
            'trial_enabled' => '1',
            'trial_days' => '21',
            'business_slot_included' => 1,
            'location_slot_included' => 4,
            'location_slot_max' => 8,
            'reason' => 'Repackaging.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $c = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertSame('Growth Plus', $c->display_name);
        $this->assertSame('149.00', (string) $c->price);
        $this->assertSame('yearly', $c->billing_cycle);
        $this->assertTrue($c->trial_enabled);
        $this->assertSame(21, $c->trial_days);
        $this->assertSame(4, $c->location_slot_included);
        $this->assertSame(8, $c->location_slot_max);

        // Price goes through the existing price-history authority.
        $this->assertSame(1, WorkspacePlanCatalogPricingChange::query()->count());

        $audit = PlatformAdminAction::query()->where('action', 'plan_catalog.updated')->sole();
        $this->assertSame($owner->id, $audit->actor_user_id);
        $this->assertSame('Repackaging.', $audit->reason);
        $this->assertArrayHasKey('display_name', $audit->payload['changes']);
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAsPlatformOwner();

        $this->savePlan(WorkspacePlanTier::Core, ['display_name' => 'Core X', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame('Core', WorkspacePlanCatalog::query()->where('tier', 'core')->value('display_name'));
    }

    public function test_invalid_slot_and_trial_combinations_are_refused_and_change_nothing(): void
    {
        $this->actingAsPlatformOwner();

        $this->savePlan(WorkspacePlanTier::Growth, ['business_slot_included' => 5, 'business_slot_max' => 2])->assertSessionHasErrors('business_slot_max');
        $this->savePlan(WorkspacePlanTier::Growth, ['trial_enabled' => '1', 'trial_days' => ''])->assertSessionHasErrors('trial_days');
        $this->savePlan(WorkspacePlanTier::Growth, ['price' => '10.00', 'currency_id' => ''])->assertSessionHasErrors('price');

        $c = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertSame(1, $c->business_slot_max);
        $this->assertFalse($c->trial_enabled);
        $this->assertSame(0, PlatformAdminAction::query()->count());
    }

    public function test_unlimited_slots_clear_the_maximum(): void
    {
        $this->actingAsPlatformOwner();

        $this->savePlan(WorkspacePlanTier::Agency, ['unlimited_business_slots' => '1', 'business_slot_max' => '9'])->assertSessionHasNoErrors();

        $c = WorkspacePlanCatalog::query()->where('tier', 'agency')->firstOrFail();
        $this->assertTrue($c->unlimited_business_slots);
        $this->assertNull($c->business_slot_max);
    }

    // ---------------------------------------------------------------- packaging

    public function test_feature_packaging_is_synced_and_audited(): void
    {
        $this->actingAsPlatformOwner();
        $repo = app(WorkspacePlanFeatureRepository::class);
        $core = WorkspacePlanCatalog::query()->where('tier', 'core')->firstOrFail();
        $this->assertNotContains('meta_ads_module', $repo->featureKeysForCatalog($core)->all());

        $keys = array_values(array_diff($repo->featureKeysForCatalog($core)->all(), ['forms']));
        $keys[] = 'meta_ads_module';

        $this->savePlan(WorkspacePlanTier::Core, ['feature_keys' => $keys])->assertSessionHasNoErrors();

        $now = app(WorkspacePlanFeatureRepository::class)->featureKeysForCatalog($core)->all();
        $this->assertContains('meta_ads_module', $now);
        $this->assertNotContains('forms', $now);

        $changes = PlatformAdminAction::query()->sole()->payload['changes']['features'];
        $this->assertContains('meta_ads_module', $changes['to']);
        $this->assertNotContains('forms', $changes['to']);
    }

    public function test_an_unknown_feature_key_is_refused(): void
    {
        $this->actingAsPlatformOwner();

        $this->savePlan(WorkspacePlanTier::Core, ['feature_keys' => ['crm', 'telepathy']])->assertSessionHasErrors('feature_keys');
        $core = WorkspacePlanCatalog::query()->where('tier', 'core')->firstOrFail();
        $this->assertNotContains('telepathy', app(WorkspacePlanFeatureRepository::class)->featureKeysForCatalog($core)->all());
    }

    // ---------------------------------------------------------------- archive

    public function test_archiving_a_plan_keeps_every_subscription_and_stops_signup(): void
    {
        $this->actingAsPlatformOwner();
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $assignmentsBefore = WorkspacePlanAssignment::query()->count();
        $this->assertGreaterThan(0, $assignmentsBefore);

        $this->savePlan(WorkspacePlanTier::Growth, ['is_active' => '0', 'available_for_signup' => '1'])->assertSessionHasNoErrors();

        $c = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertFalse($c->is_active);
        $this->assertFalse($c->available_for_signup, 'Archiving always withdraws the signup offer.');
        $this->assertSame($assignmentsBefore, WorkspacePlanAssignment::query()->count(), 'History is never destroyed.');
        $this->assertSame('plan_catalog.archived', PlatformAdminAction::query()->sole()->action);
        $this->assertNotNull($workspace->fresh());

        // Reactivation is possible.
        $this->savePlan(WorkspacePlanTier::Growth, ['is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail()->is_active);
    }

    public function test_there_is_no_delete_or_create_route_for_plans(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'admin.platform-plans.')) {
                $this->assertNotContains('DELETE', $route->methods());
                $this->assertNotContains('POST', $route->methods());
            }
        }
    }

    public function test_the_feature_management_matrix_renders_the_three_plans(): void
    {
        $this->actingAsPlatformOwner();

        $html = $this->get(route('admin.platform-features.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Feature Management', $html);
        $this->assertStringContainsString('Meta Ads', $html);
    }
}
