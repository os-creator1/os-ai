<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformStripePrice;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionTermSnapshot;
use App\Models\PlatformTrialClaim;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * A customer opens a private link, reviews the exact terms, accepts them,
 * pays on Stripe — and only the provider's confirmation starts anything.
 */
class PrivateOfferRedemptionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
    }

    private function assigned($workspace): bool
    {
        return app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId((int) $workspace->id) !== null;
    }

    private function subscriptionOf($workspace): ?PlatformSubscription
    {
        return PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();
    }

    /** Accept, pay on Stripe, and let the shared seams confirm it. @return array{0: PlatformPrivateOfferRedemption, 1: string} */
    private function redeem(array $customer, $offer): array
    {
        $session = $this->acceptAs($customer['user'], $offer);
        $providerSubscriptionId = $this->payOnStripe($session->sessionId);

        $redemption = PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail();
        $this->redemptions()->handleReturn($redemption, $customer['user']);

        return [$redemption->refresh(), $providerSubscriptionId];
    }

    // =================================================================
    // Opening the link
    // =================================================================

    public function test_opening_the_link_changes_nothing_at_all(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $before = ['subs' => PlatformSubscription::query()->count(), 'red' => PlatformPrivateOfferRedemption::query()->count()];

        $this->get(route('offers.show', ['token' => $created['token']]))->assertOk();
        $this->signInAs($customer['user']);
        $this->get(route('offers.show', ['token' => $created['token']]))->assertOk();

        $this->assertSame($before['red'], PlatformPrivateOfferRedemption::query()->count());
        $this->assertSame($before['subs'], PlatformSubscription::query()->count());
        $this->assertSame(0, PlatformTrialClaim::query()->count(), 'a trial is not claimed by looking at a page');
        $this->assertSame([], $this->stripe->calls, 'no provider call is made by opening a link');
        $this->assertFalse($this->assigned($customer['workspace']));
    }

    public function test_the_page_shows_every_term_the_customer_must_see(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test', 'trial_days' => 14,
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 3,
        ]);
        $this->signInAs($customer['user']);
        $terms = $this->termsFor($created['offer'], $customer['user']);
        $paid = $terms->paidPhases();

        $page = $this->get(route('offers.show', ['token' => $created['token']]));

        $page->assertOk()
            ->assertSee('Growth')                                         // plan name
            ->assertSee('data-role="terms-features"', false)              // included features
            ->assertSee('14 days')                                        // trial length
            ->assertSee('0.00 USD')                                       // today's charge
            ->assertSee('70.00 USD')                                      // recurring price
            ->assertSee('every 28 days')                                  // exact frequency
            ->assertSee($terms->firstPaymentAt()->toFormattedDateString()) // when the first payment occurs
            ->assertSee(\Carbon\CarbonImmutable::parse($paid[0]['ends_at'])->toFormattedDateString()) // when the promotion ends
            ->assertSee('100.00 USD every 28 days')                       // the recurring price afterwards
            ->assertSee('Temporary promotional price')                    // promotion is labelled temporary
            ->assertSee('Then the standard plan price')                   // …and what it reverts to
            ->assertSee('I accept these terms')                           // the explicit acceptance
            ->assertDontSee('monthly');
    }

    public function test_a_permanent_negotiated_price_is_distinguished_from_a_temporary_promotion(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $negotiated = $this->makeOffer(['customer_email' => 'buyer@example.test', 'custom_price' => '80.00']);
        $both = $this->makeOffer([
            'customer_email' => 'buyer@example.test', 'custom_price' => '80.00',
            'discount_type' => 'fixed', 'discount_value' => '20.00', 'discount_duration' => 'periods', 'discount_periods' => 2,
        ]);
        $this->signInAs($customer['user']);

        $this->get(route('offers.show', ['token' => $negotiated['token']]))
            ->assertOk()->assertSee('Permanent agreed price')->assertDontSee('Temporary promotional price');

        // A promotion on top of a negotiated price reverts to the NEGOTIATED price, not the catalog's.
        $this->get(route('offers.show', ['token' => $both['token']]))
            ->assertOk()
            ->assertSee('Temporary promotional price')
            ->assertSee('Then your agreed ongoing price')
            ->assertSee('60.00 USD')
            ->assertSee('80.00 USD every 28 days')
            ->assertDontSee('100.00 USD');
    }

    public function test_a_page_with_no_trial_charges_today_in_full(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $this->signInAs($customer['user']);

        $this->get(route('offers.show', ['token' => $created['token']]))
            ->assertOk()
            ->assertSee('100.00 USD')
            ->assertSee('Today')
            ->assertSee('None');
    }

    public function test_the_page_is_never_cached_or_leaked_through_the_referer(): void
    {
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $response = $this->get(route('offers.show', ['token' => $created['token']]));

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    // =================================================================
    // Accepting -> Checkout
    // =================================================================

    public function test_checkout_requires_an_explicit_acceptance_of_the_terms(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $this->signInAs($customer['user']);
        $hash = $this->termsFor($created['offer'], $customer['user'])->hash();

        // No tick-box.
        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => $hash])
            ->assertSessionHasErrors('accept_terms');
        // An unticked box.
        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => $hash, 'accept_terms' => '0'])
            ->assertSessionHasErrors('accept_terms');
        // A forged hash.
        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => str_repeat('a', 64), 'accept_terms' => '1'])
            ->assertSessionHas('status', 'error');

        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'), 'no Checkout without acceptance');
    }

    public function test_accepting_opens_a_checkout_that_collects_a_payment_method_and_pays_nothing_yet(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $this->signInAs($customer['user']);
        $hash = $this->termsFor($created['offer'], $customer['user'])->hash();

        $response = $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => $hash, 'accept_terms' => '1']);

        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $response->assertRedirect('https://checkout.stripe.test/' . $redemption->provider_checkout_session_id);

        $checkout = $this->stripe->callsOf('createSubscriptionCheckout')[0]['args'];
        $this->assertSame(14, $checkout['trial_days']);
        $this->assertSame('always', $checkout['payment_method_collection'], 'a payment method is required even for a free trial');
        $this->assertSame('price_fake_growth', $checkout['price']);
        $this->assertSame((string) $redemption->uid, $checkout['options']->subscriptionMetadata['app_offer_redemption']);
        $this->assertNotNull($checkout['options']->expiresAt);

        // Reserved — and NOTHING more.
        $this->assertSame('reserved', $redemption->state);
        $this->assertSame('pending', $this->subscriptionOf($customer['workspace'])->status->value);
        $this->assertFalse($this->assigned($customer['workspace']));
        $this->assertSame(0, PlatformTrialClaim::query()->where('state', 'granted')->count());
        $this->assertNull($this->subscriptionOf($customer['workspace'])->trial_ends_at, 'the trial has not started');

        // The terms the customer accepted are frozen on the redemption.
        $this->assertSame($hash, $redemption->terms_hash);
        $this->assertSame(14, $redemption->terms_snapshot['trial_days']);
        $this->assertSame('buyer@example.test', $redemption->accepted_email);
    }

    public function test_returning_from_checkout_without_the_provider_confirming_activates_nothing(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $this->acceptAs($customer['user'], $created['offer']);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->signInAs($customer['user']);

        // The browser comes back (even with a forged success flag) but Stripe never took the card.
        $this->get(route('offers.return', ['redemption' => $redemption->uid]) . '?success=true&redeemed=1')->assertRedirect();

        $this->assertSame('reserved', $redemption->refresh()->state);
        $this->assertFalse($this->assigned($customer['workspace']));
        $this->assertSame(PlatformSubscriptionStatus::Pending, $this->subscriptionOf($customer['workspace'])->status);
        $this->assertSame(0, PlatformTrialClaim::query()->where('state', 'granted')->count());
    }

    // =================================================================
    // Provider-confirmed redemption
    // =================================================================

    public function test_only_the_providers_confirmation_starts_the_trial_and_grants_the_plan(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $providerSubscriptionId = $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->signInAs($customer['user']);

        $this->get(route('offers.return', ['redemption' => $redemption->uid]))->assertRedirect(route('user.home'));

        $redemption->refresh();
        $subscription = $this->subscriptionOf($customer['workspace']);

        $this->assertSame('redeemed', $redemption->state);
        $this->assertTrue($redemption->trial_granted);
        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame(14, $subscription->trial_days_snapshot);
        $this->assertSame(
            $this->stripe->subscriptions[$providerSubscriptionId]['trial_end']->getTimestamp(),
            $subscription->trial_ends_at->getTimestamp(),
            'the trial end is the PROVIDER\'s',
        );
        $this->assertTrue($this->assigned($customer['workspace']), 'the plan is assigned from the confirmed subscription');
        $this->assertSame((int) $redemption->id, (int) $subscription->offer_redemption_id);
        $this->assertSame(1, PlatformTrialClaim::query()->where('state', 'granted')->count());
        $this->assertSame([], $this->stripe->charges, 'a trial charges nothing at Checkout');

        // The full price/trial/offer history is kept.
        $sources = PlatformSubscriptionTermSnapshot::query()->orderBy('id')->pluck('source')->all();
        $this->assertSame(['checkout_started', 'offer_redeemed'], $sources);
        $this->assertSame($redemption->terms_snapshot, PlatformSubscriptionTermSnapshot::query()->where('source', 'offer_redeemed')->first()->terms['offer_terms']);
    }

    public function test_the_webhook_alone_is_enough_and_replays_are_harmless(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 7]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $providerSubscriptionId = $this->payOnStripe($session->sessionId);
        $subscription = $this->subscriptionOf($customer['workspace']);

        // The customer paid and closed the tab: no browser ever returns.
        [$body, $headers] = $this->webhookBody(
            'customer.subscription.created', $providerSubscriptionId,
            $this->stripe->subscriptions[$providerSubscriptionId]['customer'], 'evt_offer_1', null, (string) $subscription->uid,
        );

        foreach (range(1, 3) as $_) {
            $this->postPlatformWebhook($body, $headers)->assertOk();
        }

        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->assertSame('redeemed', $redemption->state);
        $this->assertTrue($this->assigned($customer['workspace']));
        $this->assertSame(1, PlatformSubscriptionTermSnapshot::query()->where('source', 'offer_redeemed')->count(), 'one redemption, however many deliveries');
        $this->assertSame(1, PlatformTrialClaim::query()->count());
        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->count());
    }

    public function test_a_custom_recurring_price_gets_a_verified_immutable_stripe_price(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'custom_price' => '79.50']);

        [$redemption, $providerSubscriptionId] = $this->redeem($customer, $created['offer']);

        // MotionGrove made the Price itself: day x 28, 79.50, on the plan's product.
        $made = $this->stripe->callsOf('createRecurringPrice');
        $this->assertCount(1, $made);
        $this->assertSame(7950, $made[0]['args']['amount']);
        $this->assertSame('day', $made[0]['args']['interval']);
        $this->assertSame(28, $made[0]['args']['interval_count']);

        $priceId = (string) PlatformStripePrice::query()->sole()->provider_price_id;
        $this->assertSame($priceId, $this->stripe->callsOf('createSubscriptionCheckout')[0]['args']['price']);
        $this->assertSame($priceId, $redemption->initial_price_id);
        $this->assertSame($priceId, $redemption->standard_price_id);

        $subscription = $this->subscriptionOf($customer['workspace']);
        $this->assertSame('79.50', (string) $subscription->price_snapshot);
        $this->assertSame([7950], array_column($this->stripe->charges, 'amount'));
        $this->assertSame('redeemed', $redemption->state);
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);

        // …and it renews at the agreed price, every 28 days.
        $this->stripe->advancePeriod($providerSubscriptionId);
        $this->assertSame([7950, 7950], array_column($this->stripe->charges, 'amount'));
        $this->assertSame('none', $redemption->schedule_state, 'no promotion, so nothing to schedule');
        $this->assertSame([], $this->stripe->callsOf('applyPriceSchedule'));
    }

    public function test_the_same_agreed_amount_reuses_one_price(): void
    {
        $one = $this->unpaidCustomer('one@example.test');
        $two = $this->unpaidCustomer('two@example.test');

        $a = $this->makeOffer(['customer_email' => 'one@example.test', 'custom_price' => '79.50']);
        $b = $this->makeOffer(['customer_email' => 'two@example.test', 'custom_price' => '79.50']);

        $this->acceptAs($one['user'], $a['offer']);
        $this->acceptAs($two['user'], $b['offer']);

        $this->assertCount(1, $this->stripe->callsOf('createRecurringPrice'), 'a Stripe Price is immutable and shared by equal amounts');
        $this->assertSame(1, PlatformStripePrice::query()->count());
    }

    public function test_an_offer_at_the_plan_price_uses_the_plans_own_stripe_price(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 21]);

        $this->acceptAs($customer['user'], $created['offer']);

        $this->assertSame('price_fake_growth', $this->stripe->callsOf('createSubscriptionCheckout')[0]['args']['price']);
        $this->assertSame([], $this->stripe->callsOf('createRecurringPrice'));
    }

    public function test_a_price_that_does_not_match_the_terms_is_never_used(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'custom_price' => '79.50']);
        $this->stripe->priceKeys = [];

        // Stripe "creates" a Price whose amount differs from what was asked.
        $this->stripe->interleaveOnce('createRecurringPrice', function (array $args, $gateway): void {
            $gateway->priceKeys[$args['key']] = 'price_tampered';
            $gateway->definePrice('price_tampered', ['unit_amount' => 1, 'interval' => 'day', 'interval_count' => 28]);
        });

        $this->expectException(\App\Exceptions\PlatformBilling\PlatformBillingException::class);

        try {
            $this->acceptAs($customer['user'], $created['offer']);
        } finally {
            $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count(), 'nothing reserved on an unverified Price');
            $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'));
        }
    }

    // =================================================================
    // Temporary promotions, represented exactly in Stripe
    // =================================================================

    public function test_a_discount_for_n_periods_is_an_exact_price_schedule_then_reverts_automatically(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 3,
        ]);

        [$redemption, $providerSubscriptionId] = $this->redeem($customer, $created['offer']);
        $subscription = $this->subscriptionOf($customer['workspace']);
        $manager = app(PlatformSubscriptionManager::class);

        // Checkout charged the PROMOTIONAL price today.
        $this->assertSame(7000, $this->stripe->charges[0]['amount']);
        $this->assertSame('70.00', (string) $subscription->price_snapshot);
        $promoPriceId = (string) $redemption->initial_price_id;
        $this->assertNotSame('price_fake_growth', $promoPriceId);
        $this->assertSame('price_fake_growth', $redemption->standard_price_id, 'it reverts to the normal plan price');

        // No coupon anywhere: the promotion is a Price schedule with an exact end.
        $this->assertSame(0, count(array_filter(array_keys($this->stripe->calls[0]['args'] ?? []), fn ($k) => str_contains((string) $k, 'coupon'))));
        $scheduleCall = $this->stripe->callsOf('applyPriceSchedule');
        $this->assertCount(1, $scheduleCall);
        $phases = $scheduleCall[0]['args']['phases'];
        $this->assertSame($promoPriceId, $phases[0]['price']);
        $this->assertSame('price_fake_growth', $phases[1]['price']);
        $this->assertNotNull($phases[1]['ends_at'], 'the final phase also ends explicitly (no iterations)');

        $start = $this->stripe->subscriptions[$providerSubscriptionId]['period_start'];
        $this->assertSame($start->addDays(84)->toIso8601String(), $phases[0]['ends_at'], '3 periods x 28 days, not "3 months"');
        $this->assertSame('applied', $redemption->schedule_state);
        $this->assertNotNull($subscription->refresh()->provider_schedule_id);

        // Renewals: promo, promo, then the normal price — exactly at the boundary.
        foreach (range(1, 3) as $i) {
            $this->stripe->advancePeriod($providerSubscriptionId);
            $manager->applyProviderSubscription($subscription->refresh(), $providerSubscriptionId, 'evt_renew_' . $i, now());
        }

        $this->assertSame([7000, 7000, 7000, 10000], array_column($this->stripe->charges, 'amount'));

        $subscription->refresh();
        $this->assertSame('100.00', (string) $subscription->price_snapshot, 'the local record follows the provider back to the normal price');
        $this->assertNull($subscription->provider_schedule_id);
        $this->assertContains('promotion_ended', PlatformSubscriptionTermSnapshot::query()->pluck('source')->all());
        $this->assertSame('ok', $redemption->refresh()->reconcile_state);

        // …and it stays there.
        $this->stripe->advancePeriod($providerSubscriptionId);
        $this->assertSame(10000, end($this->stripe->charges)['amount']);
    }

    public function test_a_discount_for_one_billing_period_reverts_after_the_first_charge(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'percent', 'discount_value' => '25', 'discount_duration' => 'once',
        ]);

        [, $providerSubscriptionId] = $this->redeem($customer, $created['offer']);

        $this->assertSame(7500, $this->stripe->charges[0]['amount']);

        $this->stripe->advancePeriod($providerSubscriptionId);
        $this->stripe->advancePeriod($providerSubscriptionId);

        $this->assertSame([7500, 10000, 10000], array_column($this->stripe->charges, 'amount'));
    }

    public function test_a_forever_discount_needs_no_schedule(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'percent', 'discount_value' => '20', 'discount_duration' => 'forever',
        ]);

        [$redemption, $providerSubscriptionId] = $this->redeem($customer, $created['offer']);

        $this->stripe->advancePeriod($providerSubscriptionId);
        $this->stripe->advancePeriod($providerSubscriptionId);

        $this->assertSame([8000, 8000, 8000], array_column($this->stripe->charges, 'amount'));
        $this->assertSame([], $this->stripe->callsOf('applyPriceSchedule'));
        $this->assertSame('none', $redemption->schedule_state);
        $this->assertSame('80.00', (string) $this->subscriptionOf($customer['workspace'])->price_snapshot);
    }

    public function test_a_trial_followed_by_a_promotion_starts_the_promotion_at_the_first_payment(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test', 'trial_days' => 14,
            'discount_type' => 'fixed', 'discount_value' => '40.00', 'discount_duration' => 'periods', 'discount_periods' => 2,
        ]);

        [$redemption, $providerSubscriptionId] = $this->redeem($customer, $created['offer']);
        $subscription = $this->subscriptionOf($customer['workspace']);
        $manager = app(PlatformSubscriptionManager::class);

        $trialEnd = $subscription->trial_ends_at;
        $this->assertSame([], $this->stripe->charges, 'free until the trial ends');
        $this->assertSame($trialEnd->copy()->addDays(56)->getTimestamp(), $redemption->promo_ends_at->getTimestamp());

        foreach (range(1, 3) as $i) {
            $this->stripe->advancePeriod($providerSubscriptionId);
            $manager->applyProviderSubscription($subscription->refresh(), $providerSubscriptionId, 'evt_t_' . $i, now());
        }

        // First payment at the trial's end is already the promotional price.
        $this->assertSame([6000, 6000, 10000], array_column($this->stripe->charges, 'amount'));
    }

    public function test_the_return_and_the_webhook_racing_cannot_schedule_a_promotion_twice_or_flag_it_failed(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 2,
        ]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();

        // While the first worker is talking to Stripe, the second arrives.
        $this->stripe->interleaveOnce('applyPriceSchedule', function () use ($redemption): void {
            app(\App\Library\PlatformBilling\Offers\OfferReconciler::class)->driveSchedule($redemption->fresh());
        });

        $this->redemptions()->handleReturn($redemption, $customer['user']);

        $this->assertSame('applied', $redemption->refresh()->schedule_state);
        $this->assertCount(1, $this->stripe->callsOf('applyPriceSchedule'));
    }

    public function test_a_failure_to_schedule_the_end_of_a_promotion_is_retried_not_forgotten(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 2,
        ]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();

        $this->stripe->hooks['applyPriceSchedule'] = function (): void {
            throw new \RuntimeException('Stripe is down');
        };
        $this->redemptions()->handleReturn($redemption, $customer['user']);

        // Redeemed and paying the promotional price, with the revert still owed.
        $this->assertSame('redeemed', $redemption->refresh()->state);
        $this->assertSame('pending', $redemption->schedule_state);

        $this->stripe->hooks = [];
        $this->artisan('platform-offers:sweep')->assertExitCode(0);

        $this->assertSame('applied', $redemption->refresh()->schedule_state);
        // One attempt that failed, one that took.
        $this->assertCount(2, $this->stripe->callsOf('applyPriceSchedule'));
    }

    // =================================================================
    // Free trials happen once
    // =================================================================

    public function test_a_used_trial_is_not_offered_again_and_the_customer_is_told(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $first = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        [, $providerSubscriptionId] = $this->redeem($customer, $first['offer']);

        // They cancel; the subscription ends.
        $this->stripe->setSubscriptionStatus($providerSubscriptionId, PlatformSubscriptionStatus::Canceled);
        $subscription = $this->subscriptionOf($customer['workspace']);
        app(PlatformSubscriptionManager::class)->applyProviderSubscription($subscription, $providerSubscriptionId, 'evt_end', now());

        // A second offer with a trial arrives; this customer already had theirs.
        $second = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $this->signInAs($customer['user']);

        $page = $this->get(route('offers.show', ['token' => $second['token']]));
        $page->assertOk()->assertSee('already been used')->assertSee('100.00 USD');

        $terms = $this->termsFor($second['offer'], $customer['user']);
        $this->assertNull($terms->trialDays());
        $this->assertSame(10000, $terms->todayChargeMinor());

        // A form that still promises the trial is refused: they must see the true terms.
        $staleHash = hash('sha256', 'a-hash-from-when-the-trial-was-offered');
        try {
            $this->redemptions()->accept($second['offer'], $customer['user'], $staleHash, fn ($r) => ['https://a.test/ok', 'https://a.test/no']);
            $this->fail('A stale acceptance must be refused.');
        } catch (\App\Exceptions\PlatformBilling\PlatformBillingException $e) {
            $this->assertSame(\App\Exceptions\PlatformBilling\PlatformBillingException::OFFER_TERMS_CHANGED, $e->reason);
        }

        // Accepting the true terms charges today and gives no trial.
        $this->acceptAs($customer['user'], $second['offer'], $terms);
        $calls = $this->stripe->callsOf('createSubscriptionCheckout');
        $this->assertNull(end($calls)['args']['trial_days']);
    }

    public function test_a_second_account_on_the_same_mailbox_gets_no_second_trial(): void
    {
        $one = $this->unpaidCustomer('jane.doe@gmail.com');
        $a = $this->makeOffer(['customer_email' => 'jane.doe@gmail.com', 'trial_days' => 14]);
        $this->redeem($one, $a['offer']);

        $two = $this->unpaidCustomer('janedoe+again@gmail.com');
        $b = $this->makeOffer(['customer_email' => 'janedoe+again@gmail.com', 'trial_days' => 14]);

        $terms = $this->termsFor($b['offer'], $two['user']);

        $this->assertNull($terms->trialDays(), 'same inbox, same trial');
        $this->assertTrue($terms->get('trial_blocked'));
    }

    public function test_cancelling_checkout_releases_the_reservation_and_the_trial_is_still_available(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->signInAs($customer['user']);

        $this->get(route('offers.cancelled', ['redemption' => $redemption->uid]))->assertRedirect();

        $this->assertSame('released', $redemption->refresh()->state);
        $this->assertSame('cancelled', $redemption->release_reason);
        $this->assertNull($redemption->active_slot);
        $this->assertSame('expired', $this->stripe->sessions[$session->sessionId]['status'], 'the unpaid session can never be paid');
        $this->assertSame([], $this->stripe->payableSessionIds());
        $this->assertSame(0, PlatformTrialClaim::query()->count(), 'an abandoned Checkout does not use the trial up');

        // The same link works again, trial included.
        $again = $this->acceptAs($customer['user'], $created['offer']);
        $this->assertNotSame($session->sessionId, $again->sessionId);
        $calls = $this->stripe->callsOf('createSubscriptionCheckout');
        $this->assertSame(14, end($calls)['args']['trial_days']);
        $this->assertCount(1, $this->stripe->payableSessionIds());
    }

    public function test_a_paid_checkout_is_never_released_by_a_cancel_or_a_sweep(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();

        // The cancel click arrives AFTER the customer paid (the webhook is late).
        $this->redemptions()->handleCancel($redemption, $customer['user']);
        $redemption->forceFill(['reserved_until' => now()->subHour()])->save();
        $this->artisan('platform-offers:sweep')->assertExitCode(0);

        $this->assertSame('reserved', $redemption->refresh()->state, 'money moved: the webhook will confirm it');

        $this->redemptions()->handleReturn($redemption, $customer['user']);
        $this->assertSame('redeemed', $redemption->refresh()->state);
    }

    public function test_an_abandoned_reservation_is_released_by_the_sweep(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 7]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();

        $redemption->forceFill(['reserved_until' => now()->subMinute()])->save();
        $this->artisan('platform-offers:sweep')->assertExitCode(0);

        $this->assertSame('released', $redemption->refresh()->state);
        $this->assertSame('expired', $redemption->release_reason);
        $this->assertSame('expired', $this->stripe->sessions[$session->sessionId]['status']);
        $this->assertSame(0, PlatformTrialClaim::query()->count());
    }

    public function test_submitting_acceptance_twice_is_one_redemption_and_one_session(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $terms = $this->termsFor($created['offer'], $customer['user']);

        $first = $this->acceptAs($customer['user'], $created['offer'], $terms);
        $second = $this->acceptAs($customer['user'], $created['offer'], $terms);

        $this->assertSame($first->sessionId, $second->sessionId);
        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->count());
        $this->assertCount(1, $this->stripe->payableSessionIds());
    }

    public function test_a_double_click_during_the_stripe_call_still_makes_one_payable_session(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $terms = $this->termsFor($created['offer'], $customer['user']);

        // While the first request is waiting on Stripe, the second one arrives.
        $this->stripe->interleaveOnce('createSubscriptionCheckout', function () use ($customer, $created, $terms): void {
            $this->acceptAs($customer['user'], $created['offer'], $terms);
        });

        $this->acceptAs($customer['user'], $created['offer'], $terms);

        $this->assertSame(1, PlatformPrivateOfferRedemption::query()->count());
        $this->assertCount(1, $this->stripe->payableSessionIds());
    }

    public function test_an_ended_subscription_can_take_an_offer_but_gets_no_second_trial(): void
    {
        // Their first subscription used a 14-day trial; then it ended.
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth, 14);
        $subscription = $fixture['subscription'];
        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->status);
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Canceled);
        app(PlatformSubscriptionManager::class)->applyProviderSubscription($subscription, $fixture['provider_subscription_id'], 'evt_end', now());

        $user = $fixture['customer']->user;
        $user->forceFill(['email' => 'returning@example.test', 'email_verified_at' => now()])->save();
        $created = $this->makeOffer(['customer_email' => 'returning@example.test', 'custom_price' => '60.00', 'trial_days' => 14]);
        $terms = $this->termsFor($created['offer'], $user->refresh());
        $this->assertNull($terms->trialDays(), 'they have had their trial');

        $session = $this->acceptAs($user, $created['offer'], $terms);
        $providerSubscriptionId = $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->redemptions()->handleReturn($redemption, $user);

        $this->assertSame('redeemed', $redemption->refresh()->state);
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame('60.00', (string) $subscription->price_snapshot);
        $this->assertNotSame($fixture['provider_subscription_id'], $providerSubscriptionId, 'a NEW provider subscription');
        $this->assertContains($fixture['provider_subscription_id'], $subscription->retiredProviderSubscriptionIds());
    }
}
