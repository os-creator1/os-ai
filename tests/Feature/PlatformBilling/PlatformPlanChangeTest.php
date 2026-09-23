<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\PlatformSubscription;
use App\Models\WorkspacePlanCatalog;
use App\Models\WorkspacePlanCatalogPricingChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §10 — upgrades, downgrades and what a pricing
 * change does and does not do.
 */
class PlatformPlanChangeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
    }

    private function manager(): PlatformSubscriptionManager
    {
        return app(PlatformSubscriptionManager::class);
    }

    private function tierOf($workspace): WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    // =================================================================
    // §10.2 — upgrade is immediate
    // =================================================================

    public function test_an_upgrade_takes_effect_immediately(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        $direction = $this->manager()->requestPlanChange(
            $fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id,
        );

        $this->assertSame(PlatformSubscriptionManager::CHANGE_UPGRADED, $direction);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']),
            'The entitlement moves now, because the money moved now.');

        $subscription = $fixture['subscription']->refresh();
        $this->assertSame((int) $growth->id, (int) $subscription->workspace_plan_catalog_id);
        $this->assertSame('199.00', (string) $subscription->price_snapshot);
        $this->assertNull($subscription->pending_plan_catalog_id);

        $call = $this->stripe->callsOf('changeSubscriptionPrice')[0];
        $this->assertTrue($call['args']['prorate'], 'An upgrade bills the difference now.');
    }

    // =================================================================
    // §10.2 — downgrade is at period end
    // =================================================================

    public function test_a_downgrade_is_scheduled_for_the_period_end_and_changes_nothing_today(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '49.00');

        $direction = $this->manager()->requestPlanChange(
            $fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id,
        );

        $this->assertSame(PlatformSubscriptionManager::CHANGE_DOWNGRADE_SCHEDULED, $direction);
        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']),
            'The customer keeps the tier they have already paid for.');

        $subscription = $fixture['subscription']->refresh();
        $this->assertSame((int) $core->id, (int) $subscription->pending_plan_catalog_id);
        $this->assertEquals($subscription->current_period_end, $subscription->pending_effective_at);
        $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'),
            'A scheduled downgrade touches the provider on the boundary, not today.');
    }

    public function test_a_scheduled_downgrade_is_applied_when_the_period_ends(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '49.00');
        $this->manager()->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id);

        $this->travelTo($fixture['subscription']->refresh()->current_period_end->copy()->addMinute());
        $this->artisan('platform-subscriptions:apply-due-plan-changes')
            ->expectsOutput('Applied 1 scheduled plan change(s).')
            ->assertExitCode(0);

        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['workspace']));

        $subscription = $fixture['subscription']->refresh();
        $this->assertNull($subscription->pending_plan_catalog_id);
        $this->assertNull($subscription->pending_effective_at);
        $this->assertSame('49.00', (string) $subscription->price_snapshot);

        $call = $this->stripe->callsOf('changeSubscriptionPrice')[0];
        $this->assertFalse($call['args']['prorate'],
            'The ended period was already paid for; the cheaper tier simply starts.');

        $this->travelBack();
    }

    public function test_the_downgrade_sweep_is_idempotent_and_bounded(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '49.00');
        $this->manager()->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id);

        $this->travelTo($fixture['subscription']->refresh()->current_period_end->copy()->addMinute());

        $this->artisan('platform-subscriptions:apply-due-plan-changes')->assertExitCode(0);
        $this->artisan('platform-subscriptions:apply-due-plan-changes')
            ->expectsOutput('Applied 0 scheduled plan change(s).')
            ->assertExitCode(0);

        $this->travelBack();
    }

    public function test_the_command_refuses_every_invalid_limit(): void
    {
        foreach (['0', '-5', '5.5', 'abc', ''] as $limit) {
            $this->artisan('platform-subscriptions:apply-due-plan-changes', ['--limit' => $limit])
                ->expectsOutput('The --limit option must be a positive integer.')
                ->assertExitCode(2);
        }
    }

    public function test_a_downgrade_never_deletes_customer_data(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '49.00');
        $businessesBefore = \App\Models\Business::query()->count();

        $this->manager()->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id);
        $this->travelTo($fixture['subscription']->refresh()->current_period_end->copy()->addMinute());
        $this->artisan('platform-subscriptions:apply-due-plan-changes')->assertExitCode(0);

        $this->assertSame($businessesBefore, \App\Models\Business::query()->count(),
            'Blueprint §16/§21 — losing entitlement never destroys data.');
        $this->travelBack();
    }

    // =================================================================
    // §10.1 — pricing changes
    // =================================================================

    public function test_a_catalog_price_change_does_not_rewrite_an_existing_subscription(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->assertSame('99.00', (string) $fixture['subscription']->price_snapshot);

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        app(EntitlementManager::class)->updateCatalogPricing(
            $catalog, '149.00', $this->fixtureCurrencyId(), null,
            $this->platformAdminId(), 'Commercial repricing.',
        );

        $this->assertSame('99.00', (string) $fixture['subscription']->refresh()->price_snapshot,
            'An existing subscriber keeps the terms they agreed to.');
        $this->assertSame(1, WorkspacePlanCatalogPricingChange::query()->count(),
            'The existing catalog pricing-change history remains the only price-history authority.');
    }

    public function test_a_new_signup_uses_the_currently_published_price(): void
    {
        $existing = $this->subscribedWorkspace(WorkspacePlanTier::Growth);

        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        app(EntitlementManager::class)->updateCatalogPricing(
            $catalog, '149.00', $this->fixtureCurrencyId(), null,
            $this->platformAdminId(), 'Commercial repricing.',
        );

        $newcomer = $this->subscribedWorkspace(WorkspacePlanTier::Growth);

        $this->assertSame('99.00', (string) $existing['subscription']->refresh()->price_snapshot);
        $this->assertSame('149.00', (string) $newcomer['subscription']->price_snapshot,
            'A new customer buys at the published price.');
    }

    public function test_an_upgrade_uses_the_price_published_at_the_moment_of_the_change(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        // The Platform Owner reprices Growth before the customer upgrades.
        $growth = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        app(EntitlementManager::class)->updateCatalogPricing(
            $growth, '249.00', $this->fixtureCurrencyId(), null,
            $this->platformAdminId(), 'Commercial repricing.',
        );

        $this->manager()->requestPlanChange(
            $fixture['workspace'], $growth->refresh(), (int) $fixture['workspace']->owner_user_id,
        );

        $this->assertSame('249.00', (string) $fixture['subscription']->refresh()->price_snapshot);
    }

    // =================================================================
    // Refusals
    // =================================================================

    public function test_changing_to_the_same_tier_is_refused(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $growth = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
            $this->fail('A no-op plan change must be refused.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::CHANGE_NOT_PERMITTED, $e->reason);
        }
    }

    public function test_a_workspace_with_no_subscription_cannot_change_plan(): void
    {
        $fixture = $this->unassignedWorkspace();
        $growth = $this->sellableTier(WorkspacePlanTier::Growth);

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
            $this->fail('There is nothing to change.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::NO_SUBSCRIPTION, $e->reason);
        }

        $this->assertSame(0, PlatformSubscription::query()->count());
    }
}
