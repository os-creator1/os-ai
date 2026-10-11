<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\PlatformPlanPresenter;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\PlatformTrialClaim;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * The launch sequence, rehearsed against a catalog that looks like production
 * BEFORE this release: three plans on live monthly Prices with trials on, and a
 * paying monthly subscriber.
 */
class LaunchAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    private const MIGRATION = 'database/migrations/2026_11_08_100001_move_platform_plans_to_28_day_billing.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        config(['platform_billing.allow_public_trials' => false]);
    }

    /** Puts the catalog back in its PRE-RELEASE state: monthly Prices, trials on. */
    private function legacyCatalog(): void
    {
        foreach (['core' => '49.00', 'growth' => '99.00', 'agency' => '249.00'] as $tier => $price) {
            $id = 'price_old_month_' . $tier;
            WorkspacePlanCatalog::query()->where('tier', $tier)->firstOrFail()->forceFill([
                'price' => $price, 'currency_id' => $this->fixtureCurrencyId(), 'billing_cycle' => 'monthly', 'is_active' => true,
                'available_for_signup' => true, 'trial_enabled' => true, 'trial_days' => 14,
                'provider_price_id' => $id, 'provider_product_id' => null, 'legacy_provider_price_id' => null,
            ])->save();

            $this->stripe->definePrice($id, [
                'unit_amount' => (int) round(((float) $price) * 100), 'interval' => 'month', 'interval_count' => 1,
            ]);
        }
    }

    private function runMigrationData(): void
    {
        (require base_path(self::MIGRATION))->convertCatalogData();
    }

    /** A real paying subscriber on the legacy monthly Price. */
    private function monthlySubscriber(): array
    {
        $fixture = $this->unassignedWorkspace();
        $manager = app(PlatformSubscriptionManager::class);
        $catalog = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();

        // The pre-release behaviour included the trial; the subscriber has been paying since it ended.
        config(['platform_billing.allow_public_trials' => true]);
        $session = $manager->startCheckout($fixture['workspace'], $catalog, 'owner@example.test', 'https://a.test/ok', 'https://a.test/no');
        config(['platform_billing.allow_public_trials' => false]);

        $providerSubscriptionId = $this->stripe->completeCheckout($session->sessionId, PlatformSubscriptionStatus::Active);
        $subscription = $manager->confirmCheckoutSession($session->sessionId);
        $manager->assignPlanFromConfirmedSubscription($fixture['workspace'], $subscription, WorkspacePlanTier::Growth, (int) $fixture['workspace']->owner_user_id);

        return $fixture + ['subscription' => $subscription->refresh(), 'provider_subscription_id' => $providerSubscriptionId];
    }

    // =================================================================
    // Existing monthly subscribers
    // =================================================================

    public function test_a_monthly_subscriber_keeps_their_price_cycle_and_billing_periods_through_the_migration(): void
    {
        $this->legacyCatalog();
        $fixture = $this->monthlySubscriber();
        $subscription = $fixture['subscription'];
        $columns = ['status', 'price_snapshot', 'currency_code', 'billing_cycle_snapshot', 'trial_days_snapshot', 'provider_price_id',
            'provider_subscription_id', 'provider_customer_id', 'workspace_plan_catalog_id', 'cancel_at_period_end', 'offer_redemption_id'];
        $before = $subscription->only($columns);
        $periodBefore = [$subscription->current_period_start->getTimestamp(), $subscription->current_period_end->getTimestamp()];

        $this->assertSame('monthly', $before['billing_cycle_snapshot']);
        $this->assertSame('price_old_month_growth', $before['provider_price_id']);

        $this->runMigrationData();

        // The subscription row is bit-for-bit what it was…
        $subscription->refresh();
        $this->assertEquals($before, $subscription->only($columns));
        $this->assertSame($periodBefore, [$subscription->current_period_start->getTimestamp(), $subscription->current_period_end->getTimestamp()]);

        // …and so is Stripe's view of it: the provider subscription is still on the monthly Price.
        $this->assertSame('price_old_month_growth', $this->stripe->subscriptions[$fixture['provider_subscription_id']]['price']);

        // A renewal after the migration is still a MONTHLY renewal at the SAME price.
        $this->stripe->advancePeriod($fixture['provider_subscription_id']);
        app(PlatformSubscriptionManager::class)->applyProviderSubscription($subscription, $fixture['provider_subscription_id'], 'evt_after', now());
        $subscription->refresh();

        $this->assertSame('price_old_month_growth', $subscription->provider_price_id);
        $this->assertSame('99.00', (string) $subscription->price_snapshot);
        $this->assertSame('monthly', (string) $subscription->billing_cycle_snapshot);
        $this->assertSame(
            $subscription->current_period_start->copy()->addMonth()->getTimestamp(),
            $subscription->current_period_end->getTimestamp(),
            'still a calendar-month period, not 28 days',
        );
        $this->assertSame(9900, end($this->stripe->charges)['amount']);
        $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'), 'the migration never touches Stripe');
        $this->assertSame([], $this->stripe->callsOf('applyPriceSchedule'));
    }

    public function test_a_monthly_subscriber_is_never_silently_moved_to_a_28_day_plan(): void
    {
        $this->legacyCatalog();
        $fixture = $this->monthlySubscriber();
        $this->runMigrationData();
        $this->sellableTier(WorkspacePlanTier::Agency, null, '249.00');

        $this->expectException(PlatformBillingException::class);
        try {
            app(PlatformSubscriptionManager::class)->requestPlanChange(
                $fixture['workspace'], WorkspacePlanCatalog::query()->where('tier', 'agency')->first(), (int) $fixture['workspace']->owner_user_id,
            );
        } finally {
            $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'));
        }
    }

    // =================================================================
    // New customers
    // =================================================================

    public function test_after_the_migration_no_plan_can_be_bought_until_a_28_day_price_exists(): void
    {
        $this->legacyCatalog();
        $this->runMigrationData();

        foreach (WorkspacePlanTier::cases() as $tier) {
            $catalog = WorkspacePlanCatalog::query()->where('tier', $tier->value)->first();

            $this->assertSame('every_28_days', (string) $catalog->billing_cycle);
            $this->assertNull($catalog->provider_price_id, 'the monthly Price is detached');
            $this->assertSame('price_old_month_' . $tier->value, $catalog->legacy_provider_price_id, 'and remembered for rollback');
            $this->assertFalse((bool) $catalog->available_for_signup);
            $this->assertFalse((bool) $catalog->trial_enabled);
            $this->assertFalse($catalog->isSellable());
        }

        $this->assertSame([], app(PlatformPlanPresenter::class)->sellablePlans());

        try {
            app(V1SignupManager::class)->startSubscription(
                $this->createCustomer(),
                ['business_name' => 'X', 'industry' => 'photo_booth_service', 'timezone' => 'UTC', 'country_code' => 'US'],
                WorkspacePlanCatalog::query()->where('tier', 'growth')->first(),
                'https://a.test/ok', 'https://a.test/no',
            );
            $this->fail('A plan with no 28-day Price must not be purchasable.');
        } catch (PlatformBillingException) {
        }

        $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'));
        config(['account.can_register' => true]);
        $this->get(route('register.plan'))->assertOk()->assertDontSee('Start with');
    }

    public function test_the_stale_monthly_price_cannot_be_put_back_on_a_28_day_plan(): void
    {
        $this->legacyCatalog();
        $this->runMigrationData();
        $this->signInAsOwner();

        $this->post(route('admin.platform-billing.update', ['growth']), [
            'price' => '99.00', 'currency_id' => $this->fixtureCurrencyId(), 'billing_cycle' => 'every_28_days',
            'available_for_signup' => '1', 'provider_price_id' => 'price_old_month_growth', 'reason' => 'Oops.',
        ])->assertSessionHasErrors('provider_price_id');

        $this->assertNull(WorkspacePlanCatalog::query()->where('tier', 'growth')->value('provider_price_id'));
    }

    public function test_a_private_offer_on_a_detached_plan_sells_on_a_freshly_created_28_day_price_never_the_monthly_one(): void
    {
        $this->legacyCatalog();
        $this->runMigrationData();
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $this->acceptAs($customer['user'], $created['offer']);

        $price = $this->stripe->callsOf('createSubscriptionCheckout')[0]['args']['price'];
        $this->assertNotSame('price_old_month_growth', $price);
        $this->assertSame('day', $this->stripe->prices[$price]['interval']);
        $this->assertSame(28, $this->stripe->prices[$price]['interval_count']);
    }

    public function test_nothing_in_the_launch_sequence_grants_a_public_trial(): void
    {
        $this->legacyCatalog();
        $this->runMigrationData();
        $this->signInAsOwner();

        foreach (['core', 'growth', 'agency'] as $tier) {
            $this->post(route('admin.platform-billing.provision-price', [$tier]), ['reason' => 'Launch'])->assertRedirect();
        }

        $this->assertSame(0, WorkspacePlanCatalog::query()->where('trial_enabled', true)->count());
        $this->assertSame(0, PlatformTrialClaim::query()->count());
    }

    // =================================================================
    // The launch sequence, end to end
    // =================================================================

    public function test_the_launch_sequence_ends_in_a_successful_28_day_signup(): void
    {
        // 0. Production before the release.
        $this->legacyCatalog();
        $subscriber = $this->monthlySubscriber();

        // 1. Deploy: migrations. (convertCatalogData is the data half of the migration.)
        $this->runMigrationData();
        $this->assertSame([], app(PlatformPlanPresenter::class)->sellablePlans());

        // 2. Create the three 28-day Prices — from the owner screen, no Stripe dashboard.
        $this->signInAsOwner();
        foreach (['core', 'growth', 'agency'] as $tier) {
            $this->post(route('admin.platform-billing.provision-price', [$tier]), ['reason' => 'Create the 28-day Price'])
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        $created = $this->stripe->callsOf('createRecurringPrice');
        $this->assertCount(3, $created);
        $this->assertEqualsCanonicalizing([4900, 9900, 24900], array_column(array_column($created, 'args'), 'amount'));
        foreach ($created as $call) {
            $this->assertSame('day', $call['args']['interval']);
            $this->assertSame(28, $call['args']['interval_count']);
        }

        // 3. Enable the plans for sale.
        foreach (WorkspacePlanCatalog::query()->get() as $catalog) {
            $this->post(route('admin.platform-billing.update', [$catalog->tier->value]), [
                'price' => (string) $catalog->price, 'currency_id' => $catalog->currency_id, 'billing_cycle' => 'every_28_days',
                'available_for_signup' => '1', 'provider_price_id' => $catalog->provider_price_id, 'reason' => 'Enable for sale',
            ])->assertSessionHasNoErrors();
        }

        $this->assertCount(3, app(PlatformPlanPresenter::class)->sellablePlans());

        // 4. A new customer signs up and pays.
        $growth = WorkspacePlanCatalog::query()->where('tier', 'growth')->first();
        $session = app(V1SignupManager::class)->startSubscription(
            $this->createCustomer(),
            ['business_name' => 'New Co', 'industry' => 'photo_booth_service', 'timezone' => 'UTC', 'country_code' => 'US'],
            $growth, 'https://a.test/ok', 'https://a.test/no',
        );
        $checkout = $this->stripe->callsOf('createSubscriptionCheckout');
        $args = end($checkout)['args'];

        $this->assertNull($args['trial_days'], 'no trial on ordinary signup');
        $this->assertSame($growth->provider_price_id, $args['price']);
        $this->assertSame(28, $this->stripe->prices[$args['price']]['interval_count']);

        $provider = $this->stripe->completeCheckout($session->sessionId);
        $new = app(PlatformSubscriptionManager::class)->confirmCheckoutSession($session->sessionId);

        $this->assertSame(PlatformSubscriptionStatus::Active, $new->status);
        $this->assertSame('every_28_days', (string) $new->billing_cycle_snapshot);
        $this->assertSame(9900, end($this->stripe->charges)['amount']);
        $this->assertSame(28, (int) $new->current_period_start->diffInDays($new->current_period_end));

        // 5. The old subscriber is still exactly where they were.
        $old = $subscriber['subscription']->refresh();
        $this->assertSame('monthly', (string) $old->billing_cycle_snapshot);
        $this->assertSame('price_old_month_growth', $old->provider_price_id);

        // 6. ROLLBACK rehearsal: the down() restores the monthly Prices on the catalog.
        (require base_path(self::MIGRATION))->down();
        $growth->refresh();
        $this->assertSame('price_old_month_growth', $growth->provider_price_id);
        $this->assertSame('monthly', (string) $growth->billing_cycle);
        $this->assertTrue((bool) $growth->available_for_signup, 'back on sale, as before the release');
        $this->assertTrue((bool) $growth->trial_enabled, 'and the trial setting is back');
        $this->assertSame(14, (int) $growth->trial_days);
        $this->assertSame($provider === '' ? null : 'every_28_days', (string) $new->refresh()->billing_cycle_snapshot, 'rollback never rewrites a subscription');
    }

    // =================================================================
    // Email verification switched OFF (ACCOUNT_VERIFICATION=false)
    // =================================================================

    public function test_a_rollback_never_leaves_a_28_day_row_the_previous_release_cannot_represent(): void
    {
        $this->legacyCatalog();
        $this->runMigrationData();

        // The owner then created a 28-day Price on the converted core plan, and a
        // plan row appeared that the migration never saw.
        $core = WorkspacePlanCatalog::query()->where('tier', 'core')->firstOrFail();
        $core->forceFill(['provider_price_id' => 'price_new_28_core', 'available_for_signup' => true])->save();
        $extra = WorkspacePlanCatalog::query()->where('tier', 'agency')->firstOrFail();
        \Illuminate\Support\Facades\DB::table('workspace_plan_catalog')->where('id', $extra->id)->update(['pre_28_day_snapshot' => null]);

        (require base_path(self::MIGRATION))->down();

        $core->refresh();
        $this->assertSame('monthly', (string) $core->billing_cycle);
        $this->assertSame('price_old_month_core', $core->provider_price_id, 'the legacy monthly Price, not the 28-day one');

        $extra->refresh();
        $this->assertContains((string) $extra->billing_cycle, ['monthly', 'yearly']);
        $this->assertNull($extra->provider_price_id, 'no snapshot: taken off sale rather than sold on a wrong cadence');
        $this->assertFalse((bool) $extra->available_for_signup);
    }

    public function test_the_trial_backfill_records_every_historical_workspace_even_when_mailboxes_collide(): void
    {
        $a = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $b = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $a['customer']->user->forceFill(['email' => 'same.owner+one@example.test'])->save();
        $b['customer']->user->forceFill(['email' => 'same.owner+two@example.test'])->save();

        foreach ([$a, $b] as $fixture) {
            PlatformSubscription::query()->whereKey($fixture['subscription']->id)->update(['trial_days_snapshot' => 14, 'trial_ends_at' => now()->subDays(60)]);
        }

        \Illuminate\Support\Facades\DB::table('platform_trial_claims')->delete();

        (require base_path('database/migrations/2026_11_08_100004_backfill_platform_trial_claims.php'))->up();

        foreach ([$a, $b] as $fixture) {
            $this->assertTrue(
                \Illuminate\Support\Facades\DB::table('platform_trial_claims')
                    ->where('workspace_id', $fixture['workspace']->id)->where('state', 'granted')->exists(),
                'every workspace that had a trial is recorded as having used it',
            );
        }
    }

    public function test_the_trial_backfill_keeps_the_mailbox_when_only_the_provider_customer_collides(): void
    {
        $a = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $b = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $a['customer']->user->forceFill(['email' => 'first.owner@example.test'])->save();
        $b['customer']->user->forceFill(['email' => 'second.owner@example.test'])->save();

        foreach ([$a, $b] as $fixture) {
            PlatformSubscription::query()->whereKey($fixture['subscription']->id)->update([
                'trial_days_snapshot' => 14, 'trial_ends_at' => now()->subDays(60), 'provider_customer_id' => 'cus_shared_history',
            ]);
        }

        \Illuminate\Support\Facades\DB::table('platform_trial_claims')->delete();
        (require base_path('database/migrations/2026_11_08_100004_backfill_platform_trial_claims.php'))->up();

        $second = \Illuminate\Support\Facades\DB::table('platform_trial_claims')->where('workspace_id', $b['workspace']->id)->first();
        $this->assertNotNull($second);
        $this->assertNull($second->provider_customer_id, 'only the colliding customer is dropped');
        $this->assertNotNull($second->email_key, 'the mailbox is retained');
    }

    public function test_provisioning_after_the_migration_reuses_the_plans_existing_product(): void
    {
        $this->legacyCatalog();
        $this->stripe->definePrice('price_old_month_growth', ['product' => 'prod_existing_growth', 'unit_amount' => 9900, 'interval' => 'month', 'interval_count' => 1]);
        $this->runMigrationData();

        $growth = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $this->assertNull($growth->provider_price_id);
        $this->assertSame('price_old_month_growth', $growth->legacy_provider_price_id);

        app(\App\Library\PlatformBilling\Offers\PlatformPriceProvisioner::class)->provisionCatalogPrice($growth, 'USD');

        $products = $this->stripe->callsOf('ensureProduct');
        $this->assertNotEmpty($products);
        $this->assertSame('prod_existing_growth', $products[0]['args']['existing'], 'no duplicate Product is created');
        $this->assertSame('prod_existing_growth', $growth->refresh()->provider_product_id);
    }

    public function test_with_verification_off_an_email_bound_offer_cannot_be_created(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        config(['account.verify_account' => false]);

        try {
            $this->makeOffer(['customer_email' => 'buyer@example.test']);
            $this->fail('An email-bound offer is meaningless when mailbox ownership cannot be proven.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('customer_email', $e->errors());
        }
    }

    public function test_with_verification_off_anyone_registering_a_targeted_email_still_cannot_redeem_its_offer(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        $created = $this->makeOffer(['customer_email' => 'victim@example.test']);

        // Later the installation switches verification off: accounts are now auto-"verified".
        config(['account.verify_account' => false]);
        $attacker = $this->unpaidCustomer('victim@example.test', verified: true);

        $this->assertSame('not_for_you', $this->redemptions()->present($created['offer'], $attacker['user'])['state']);

        try {
            $this->acceptAs($attacker['user'], $created['offer']);
            $this->fail('Must fail closed.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::OFFER_VERIFICATION_DISABLED, $e->reason);
        }

        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_with_verification_off_a_workspace_bound_offer_still_works_for_that_workspace_owner(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        config(['account.verify_account' => false]);
        $owner = $this->unpaidCustomer(null, verified: true);
        $stranger = $this->unpaidCustomer(null, verified: true);
        $created = $this->makeOffer(['customer_email' => null, 'workspace_uid' => $owner['workspace']->uid, 'custom_price' => '80.00']);

        $this->assertSame('ready', $this->redemptions()->present($created['offer'], $owner['user'])['state']);
        $this->assertSame('not_for_you', $this->redemptions()->present($created['offer'], $stranger['user'])['state']);

        $this->acceptAs($owner['user'], $created['offer']);
        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->count());
    }

    public function test_with_verification_off_the_offer_page_does_not_offer_account_creation(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        config(['account.verify_account' => false, 'account.can_register' => true]);

        $this->get(route('offers.show', ['token' => $created['token']]))->assertOk()->assertDontSee('Create an account');
        $this->get(route('offers.signup', ['token' => $created['token']]))->assertRedirect(route('offers.show', ['token' => $created['token']]));
    }
}
