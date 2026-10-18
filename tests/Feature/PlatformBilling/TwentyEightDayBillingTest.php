<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\PlatformBilling\PlatformPriceVerifier;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\PlatformStripePrice;
use App\Models\PlatformSubscription;
use App\Models\PlatformTrialClaim;
use App\Models\User;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * Core, Growth and Agency bill every EXACTLY 28 days, a new customer pays
 * immediately, and nothing about an existing subscriber changes.
 */
class TwentyEightDayBillingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        // Production default: ordinary signup has no trial.
        config(['platform_billing.allow_public_trials' => false]);
    }

    public function test_ordinary_signup_has_no_trial_even_if_a_catalog_trial_is_configured(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth, 14);
        config(['account.can_register' => true]);

        $this->assertSame(14, $catalog->configuredTrialDays(), 'the catalog field exists but is inert');

        $this->get(route('register.plan'))->assertOk()->assertDontSee('day free trial');

        app(V1SignupManager::class)->startSubscription(
            $this->createCustomer(), $this->draft(), $catalog, 'https://app.test/done', 'https://app.test/cancel',
        );

        $this->assertNull($this->stripe->callsOf('createSubscriptionCheckout')[0]['args']['trial_days']);
        $this->assertSame(0, PlatformTrialClaim::query()->count());
    }

    public function test_a_plan_change_never_moves_a_subscriber_onto_a_different_billing_cycle(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $core = $this->sellableTier(WorkspacePlanTier::Core, null, '49.00');

        // A subscriber whose agreement is the legacy monthly cadence.
        $fixture['subscription']->forceFill(['billing_cycle_snapshot' => 'monthly'])->save();

        try {
            app(PlatformSubscriptionManager::class)->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id);
            $this->fail('A change that would re-anchor billing must be refused.');
        } catch (\App\Exceptions\PlatformBilling\PlatformBillingException $e) {
            $this->assertSame(\App\Exceptions\PlatformBilling\PlatformBillingException::CHANGE_NOT_PERMITTED, $e->reason);
        }

        $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'));
        $this->assertSame('monthly', (string) $fixture['subscription']->refresh()->billing_cycle_snapshot);
    }

    private function draft(string $name = 'Harbor Lane'): array
    {
        return ['business_name' => $name, 'industry' => 'photo_booth_service', 'timezone' => 'UTC', 'country_code' => 'US'];
    }

    // =================================================================
    // The catalog
    // =================================================================

    public function test_every_plan_bills_every_28_days_and_has_no_free_trial_by_default(): void
    {
        foreach (WorkspacePlanTier::cases() as $tier) {
            $catalog = $this->catalog($tier);

            $this->assertSame('every_28_days', (string) $catalog->billing_cycle, $tier->value . ' must bill every 28 days');
            $this->assertFalse((bool) $catalog->trial_enabled, $tier->value . ' must have no trial by default');
            $this->assertNull($catalog->trial_days);
            $this->assertNull($catalog->configuredTrialDays());
        }
    }

    public function test_the_stripe_price_for_the_28_day_cycle_must_be_exactly_day_by_28(): void
    {
        $verifier = app(PlatformPriceVerifier::class);

        $this->stripe->definePrice('price_exact28', ['unit_amount' => 9900, 'interval' => 'day', 'interval_count' => 28]);
        $this->assertSame([], $verifier->mismatches('price_exact28', '99.00', 'USD', 'every_28_days'));

        // A calendar month is 28 to 31 days: NOT an exact 28-day cadence.
        $this->stripe->definePrice('price_month', ['unit_amount' => 9900, 'interval' => 'month', 'interval_count' => 1]);
        $this->assertNotSame([], $verifier->mismatches('price_month', '99.00', 'USD', 'every_28_days'));

        foreach ([[30, 'day'], [7, 'day'], [4, 'week']] as $i => [$count, $interval]) {
            $this->stripe->definePrice("price_other{$i}", ['unit_amount' => 9900, 'interval' => $interval, 'interval_count' => $count]);
            $this->assertNotSame([], $verifier->mismatches("price_other{$i}", '99.00', 'USD', 'every_28_days'), "{$count} {$interval}(s) is not 28 days");
        }
    }

    public function test_yearly_billing_is_preserved_where_supported(): void
    {
        $verifier = app(PlatformPriceVerifier::class);

        $this->stripe->definePrice('price_year', ['unit_amount' => 99000, 'interval' => 'year', 'interval_count' => 1]);
        $this->assertSame([], $verifier->mismatches('price_year', '990.00', 'USD', 'yearly'));

        // …and a 28-day Price cannot pass as a yearly one.
        $this->stripe->definePrice('price_28', ['unit_amount' => 99000, 'interval' => 'day', 'interval_count' => 28]);
        $this->assertNotSame([], $verifier->mismatches('price_28', '990.00', 'USD', 'yearly'));
    }

    public function test_the_owner_can_create_the_28_day_stripe_price_without_opening_stripe(): void
    {
        $this->signInAsOwner();

        $catalog = $this->catalog(WorkspacePlanTier::Growth);
        $catalog->forceFill([
            'price' => '99.00', 'currency_id' => $this->fixtureCurrencyId(), 'billing_cycle' => 'every_28_days',
            'provider_price_id' => null, 'is_active' => true,
        ])->save();

        $this->post(route('admin.platform-billing.provision-price', ['growth']), ['reason' => 'Create standard Price'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $created = $this->stripe->callsOf('createRecurringPrice');
        $this->assertCount(1, $created);
        $this->assertSame(9900, $created[0]['args']['amount']);
        $this->assertSame('USD', $created[0]['args']['currency']);
        $this->assertSame('day', $created[0]['args']['interval']);
        $this->assertSame(28, $created[0]['args']['interval_count']);

        $catalog->refresh();
        $this->assertNotNull($catalog->provider_price_id);
        $this->assertNotNull($catalog->provider_product_id);
        $this->assertSame(1, PlatformStripePrice::query()->count());

        // Running it again converges on the SAME Price: no duplicate is made.
        $catalog->forceFill(['provider_price_id' => null])->save();
        $firstId = (string) PlatformStripePrice::query()->value('provider_price_id');
        $this->post(route('admin.platform-billing.provision-price', ['growth']), ['reason' => 'Again'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->stripe->callsOf('createRecurringPrice'));
        $this->assertSame($firstId, (string) $catalog->refresh()->provider_price_id);
    }

    // =================================================================
    // Public signup
    // =================================================================

    public function test_a_new_customer_pays_immediately_and_is_billed_every_28_days(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Core, null, '79.00');
        $customer = $this->createCustomer();

        $session = app(V1SignupManager::class)->startSubscription(
            $customer, $this->draft(), $catalog, 'https://app.test/done', 'https://app.test/cancel',
        );

        $checkout = $this->stripe->callsOf('createSubscriptionCheckout')[0]['args'];
        $this->assertNull($checkout['trial_days'], 'No trial by default: the customer pays at Checkout.');
        $this->assertSame('always', $checkout['payment_method_collection']);

        $providerSubscriptionId = $this->stripe->completeCheckout($session->sessionId);
        $subscription = app(PlatformSubscriptionManager::class)->confirmCheckoutSession($session->sessionId);

        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);
        $this->assertSame('every_28_days', (string) $subscription->billing_cycle_snapshot);
        $this->assertNull($subscription->trial_days_snapshot);
        $this->assertSame('79.00', (string) $subscription->price_snapshot);

        // Charged today, in full.
        $this->assertCount(1, $this->stripe->charges);
        $this->assertSame(7900, $this->stripe->charges[0]['amount']);

        // The period is exactly 28 days, and the next one another 28.
        $this->assertSame(28, (int) $subscription->current_period_start->diffInDays($subscription->current_period_end));

        $this->stripe->advancePeriod($providerSubscriptionId);
        app(PlatformSubscriptionManager::class)->applyProviderSubscription($subscription, $providerSubscriptionId, 'evt_renew', now());
        $subscription->refresh();

        $this->assertSame(28, (int) $subscription->current_period_start->diffInDays($subscription->current_period_end));
        $this->assertCount(2, $this->stripe->charges);
    }

    public function test_public_pages_say_every_28_days_and_never_monthly(): void
    {
        $this->sellableTier(WorkspacePlanTier::Core, null, '79.00');
        config(['account.can_register' => true]);

        $page = $this->get(route('register.plan'));

        $page->assertOk()->assertSee('every 28 days');
        $this->assertStringNotContainsStringIgnoringCase('/ monthly', $page->getContent());
        $this->assertStringNotContainsStringIgnoringCase('per month', $page->getContent());
    }

    public function test_existing_subscribers_keep_their_commercial_terms_when_the_catalog_changes(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $subscription = $fixture['subscription'];

        $before = $subscription->only(['price_snapshot', 'billing_cycle_snapshot', 'trial_days_snapshot', 'provider_price_id', 'currency_code']);

        // The owner moves the plan to yearly at a new price and a trial.
        $this->catalog(WorkspacePlanTier::Growth)->forceFill([
            'price' => '999.00', 'billing_cycle' => 'yearly', 'trial_enabled' => true, 'trial_days' => 30,
            'provider_price_id' => 'price_new_year',
        ])->save();

        $this->assertSame($before, $subscription->refresh()->only(array_keys($before)));
    }

    // =================================================================
    // One free trial, ever
    // =================================================================

    public function test_a_cancelled_checkout_does_not_use_up_the_trial_but_a_started_one_does(): void
    {
        config(['platform_billing.allow_public_trials' => true]);
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth, 14);
        $customer = $this->createCustomer();
        $manager = app(PlatformSubscriptionManager::class);
        $signup = app(V1SignupManager::class);

        // First attempt is abandoned at Stripe: nothing was granted.
        $first = $signup->startSubscription($customer, $this->draft(), $catalog, 'https://app.test/done', 'https://app.test/cancel');
        $this->assertSame(14, $this->stripe->callsOf('createSubscriptionCheckout')[0]['args']['trial_days']);
        $this->assertSame(0, PlatformTrialClaim::query()->where('state', 'granted')->count());

        // Trying again still offers the trial — and does not open a second payable session.
        $second = $signup->restartCheckout($customer, $catalog, 'https://app.test/done', 'https://app.test/cancel');
        $this->assertSame($first->sessionId, $second->sessionId);

        // The customer finishes: the PROVIDER confirms the trial.
        $providerSubscriptionId = $this->stripe->completeCheckout($second->sessionId);
        $subscription = $manager->confirmCheckoutSession($second->sessionId);
        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame(1, PlatformTrialClaim::query()->where('state', 'granted')->count());

        // They cancel, the subscription ends, and they come back.
        $this->stripe->setSubscriptionStatus($providerSubscriptionId, PlatformSubscriptionStatus::Canceled);
        $manager->applyProviderSubscription($subscription, $providerSubscriptionId, 'evt_cancel', now());
        $this->assertTrue(PlatformSubscriptionManager::hasEnded($subscription->refresh()));

        $workspace = $subscription->workspace;
        $manager->startResubscribeCheckout($workspace, $catalog, (string) $customer->user->email, 'https://app.test/done', 'https://app.test/cancel');

        $calls = $this->stripe->callsOf('createSubscriptionCheckout');
        $this->assertNull(end($calls)['args']['trial_days'], 'A resubscription never gets a second free trial.');
    }

    public function test_a_second_account_on_the_same_mailbox_does_not_get_a_second_trial(): void
    {
        config(['platform_billing.allow_public_trials' => true]);
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth, 14);
        $signup = app(V1SignupManager::class);
        $manager = app(PlatformSubscriptionManager::class);

        $one = $this->createCustomer();
        $one->user->forceFill(['email' => 'jane.doe@gmail.com'])->save();
        $session = $signup->startSubscription($one->refresh(), $this->draft('One'), $catalog, 'https://app.test/done', 'https://app.test/cancel');
        $this->stripe->completeCheckout($session->sessionId);
        $manager->confirmCheckoutSession($session->sessionId);

        // A "different" account that is the same inbox: dots and +tags are ignored by Gmail.
        $two = $this->createCustomer();
        $two->user->forceFill(['email' => 'janedoe+again@gmail.com'])->save();
        $signup->startSubscription($two->refresh(), $this->draft('Two'), $catalog, 'https://app.test/done', 'https://app.test/cancel');

        $calls = $this->stripe->callsOf('createSubscriptionCheckout');
        $this->assertNull(end($calls)['args']['trial_days']);
    }

    public function test_trials_used_before_the_ledger_existed_still_count(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth, null);
        $customer = $this->createCustomer();
        $customer->user->forceFill(['email' => 'old.timer@example.test'])->save();
        $workspace = $this->createWorkspace($customer->user, ['name' => 'Old']);

        // A subscriber from before this release who had a 14-day trial.
        $subscription = new PlatformSubscription(['workspace_id' => $workspace->id]);
        $subscription->generateUid();
        $subscription->local_idempotency_key = PlatformSubscription::idempotencyKeyFor((string) $subscription->uid);
        $subscription->status = PlatformSubscriptionStatus::Canceled->value;
        $subscription->billing_cycle_snapshot = 'monthly';
        $subscription->workspace_plan_catalog_id = $catalog->id;
        $subscription->trial_days_snapshot = 14;
        $subscription->save();

        (require base_path('database/migrations/2026_11_08_100004_backfill_platform_trial_claims.php'))->up();
        (require base_path('database/migrations/2026_11_08_100004_backfill_platform_trial_claims.php'))->up(); // idempotent

        $this->assertSame(1, PlatformTrialClaim::query()->where('workspace_id', $workspace->id)->where('state', 'granted')->count());
        $this->assertFalse(app(\App\Library\PlatformBilling\Offers\TrialLedger::class)->eligible($workspace, 'old.timer@example.test'));
        $this->assertFalse(app(\App\Library\PlatformBilling\Offers\TrialLedger::class)->eligible($this->createWorkspace($this->createCustomer()->user), 'old.timer+x@example.test'));
    }
}
