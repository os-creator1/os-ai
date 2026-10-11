<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\PlatformBilling\V1SignupManager;
use App\Models\PlatformAdminAction;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionTermSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * Applying a negotiated price to an EXISTING customer: explicit, audited, and
 * with the customer's acceptance wherever it could cost them more.
 */
class PrivateOfferBillingChangeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->sellableTier(WorkspacePlanTier::Core, null, '49.00');
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        $this->sellableTier(WorkspacePlanTier::Agency, null, '249.00');

        // A paying Growth customer at 100.00 every 28 days.
        $this->fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->fixture['customer']->user->forceFill(['email' => 'owner@example.test', 'email_verified_at' => now()])->save();
    }

    private function change(array $data): array
    {
        return $this->makeOffer(array_merge([
            'kind' => 'billing_change',
            'customer_email' => null,
            'workspace_uid' => $this->fixture['workspace']->uid,
        ], $data));
    }

    private function subscription(): PlatformSubscription
    {
        return $this->fixture['subscription']->refresh();
    }

    // =================================================================
    // Creating a change
    // =================================================================

    public function test_a_change_applies_to_an_existing_workspace_and_keeps_its_plan(): void
    {
        $created = $this->change(['custom_price' => '80.00']);

        $this->assertTrue($created['offer']->isBillingChange());
        $this->assertSame('growth', $created['offer']->catalog->tier->value, 'the plan (and so every entitlement) is untouched');
    }

    /** @return array<string, array{0: callable, 1: string}> */
    public static function refusedChanges(): array
    {
        return [
            'a trial on an existing subscription' => [fn ($t) => ['custom_price' => '80.00', 'trial_days' => 7], 'trial_days'],
            'nothing to change' => [fn ($t) => [], 'custom_price'],
            'a workspace with no subscription' => [fn ($t) => ['custom_price' => '80.00', 'workspace_uid' => $t->unpaidCustomer()['workspace']->uid], 'workspace_uid'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedChanges')]
    public function test_changes_that_make_no_sense_are_refused(callable $data, string $field): void
    {
        try {
            $this->change($data($this));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }

        $this->assertSame(0, PlatformPrivateOffer::query()->count());
    }

    public function test_a_subscription_set_to_cancel_cannot_be_given_new_terms(): void
    {
        app(PlatformSubscriptionManager::class)->requestCancellation($this->fixture['workspace']);

        $this->expectException(ValidationException::class);
        $this->change(['custom_price' => '80.00']);
    }

    public function test_only_one_open_change_per_workspace(): void
    {
        $this->change(['custom_price' => '80.00']);

        $this->expectException(ValidationException::class);
        $this->change(['custom_price' => '70.00']);
    }

    // =================================================================
    // The owner applies a price-neutral-or-better change directly
    // =================================================================

    public function test_the_owner_can_apply_a_cheaper_price_without_waiting_and_nothing_is_charged_today(): void
    {
        $created = $this->change(['custom_price' => '80.00']);
        $subscription = $this->subscription();
        $periodEnd = $subscription->current_period_end;
        $chargesBefore = count($this->stripe->charges);
        $this->signInAsOwner();

        $this->get(route('admin.platform-billing.offers.show', [$created['offer']->uid]))
            ->assertOk()
            ->assertSee('Apply to the subscription now');

        $this->post(route('admin.platform-billing.offers.apply', [$created['offer']->uid]), ['reason' => 'Loyalty price agreed by phone.'])
            ->assertSessionHas('status', 'success');

        // Stripe is told: today's price until the period ends, then the agreed one. No proration, no charge.
        $call = $this->stripe->callsOf('applyPriceSchedule')[0]['args'];
        $this->assertSame('price_fake_growth', $call['phases'][0]['price']);
        $this->assertSame($periodEnd->toIso8601String(), $call['phases'][0]['ends_at']);
        $this->assertNotNull($call['phases'][1]['ends_at'], 'the final phase also ends explicitly (no iterations)');
        $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'));
        $this->assertCount($chargesBefore, $this->stripe->charges);

        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->assertSame('redeemed', $redemption->state);
        $this->assertTrue($redemption->terms_snapshot['applied_by_owner']);
        $this->assertSame((int) $redemption->id, (int) $subscription->refresh()->offer_redemption_id);
        $this->assertSame('100.00', (string) $subscription->price_snapshot, 'until the renewal, the customer still pays what they agreed to before');

        // Audited, with the reason.
        $this->assertSame(
            'Loyalty price agreed by phone.',
            PlatformAdminAction::query()->where('action', 'private_offer.billing_change_applied')->sole()->reason,
        );
        $this->assertContains('billing_change', PlatformSubscriptionTermSnapshot::query()->pluck('source')->all());

        // …and at the renewal the agreed price is charged and recorded.
        $this->stripe->advancePeriod($this->fixture['provider_subscription_id']);
        app(PlatformSubscriptionManager::class)->applyProviderSubscription($subscription, $this->fixture['provider_subscription_id'], 'evt_change', now());

        $this->assertSame(8000, end($this->stripe->charges)['amount']);
        $this->assertSame('80.00', (string) $subscription->refresh()->price_snapshot);
        $this->assertSame('ok', $redemption->refresh()->reconcile_state);
    }

    public function test_the_owner_cannot_force_through_anything_that_could_cost_the_customer_more(): void
    {
        $created = $this->change(['custom_price' => '120.00']);
        $this->signInAsOwner();

        $this->get(route('admin.platform-billing.offers.show', [$created['offer']->uid]))
            ->assertOk()
            ->assertDontSee('Apply to the subscription now');

        $this->post(route('admin.platform-billing.offers.apply', [$created['offer']->uid]), ['reason' => 'Just do it.'])
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $this->stripe->callsOf('applyPriceSchedule'));
        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
    }

    public function test_a_cheaper_promotion_that_reverts_to_a_higher_price_still_needs_acceptance(): void
    {
        $created = $this->change([
            'custom_price' => '120.00', 'discount_type' => 'fixed', 'discount_value' => '60.00', 'discount_duration' => 'once',
        ]);

        $this->assertFalse($this->redemptions()->ownerCanApplyWithoutAcceptance($created['offer']));
    }

    // =================================================================
    // The customer accepts
    // =================================================================

    public function test_the_customer_reviews_and_accepts_a_higher_price_and_it_starts_at_the_renewal(): void
    {
        $created = $this->change(['custom_price' => '120.00', 'discount_type' => 'percent', 'discount_value' => '25', 'discount_duration' => 'periods', 'discount_periods' => 2]);
        $owner = $this->fixture['customer']->user->refresh();
        $this->signInAs($owner);
        $subscription = $this->subscription();

        $page = $this->get(route('offers.show', ['token' => $created['token']]));
        $page->assertOk()
            ->assertSee('Your current price')
            ->assertSee('100.00 USD')
            ->assertSee('90.00 USD')       // the promotional price
            ->assertSee('120.00 USD every 28 days') // the price afterwards
            ->assertSee('Starts at your next renewal')
            ->assertSee($subscription->current_period_end->toFormattedDateString())
            ->assertSee('Accept new pricing');

        $chargesBefore = count($this->stripe->charges);
        $hash = $this->termsFor($created['offer'], $owner)->hash();

        // Not without the tick.
        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => $hash])->assertSessionHasErrors('accept_terms');
        $this->assertSame([], $this->stripe->callsOf('applyPriceSchedule'));

        $this->post(route('offers.accept', ['token' => $created['token']]), ['terms_hash' => $hash, 'accept_terms' => '1'])
            ->assertSessionHas('status', 'success');

        $phases = $this->stripe->callsOf('applyPriceSchedule')[0]['args']['phases'];
        $this->assertCount(3, $phases, 'today\'s price, the promotion, then the agreed price');
        $this->assertSame('price_fake_growth', $phases[0]['price']);
        $this->assertSame($subscription->current_period_end->toIso8601String(), $phases[0]['ends_at']);
        $this->assertSame($subscription->current_period_end->copy()->addDays(56)->toIso8601String(), $phases[1]['ends_at']);
        $this->assertNotNull($phases[2]['ends_at'], 'the final phase also ends explicitly (no iterations)');
        $this->assertCount($chargesBefore, $this->stripe->charges, 'nothing is charged today');

        // Renewals: 90, 90, 120.
        $manager = app(PlatformSubscriptionManager::class);
        foreach (range(1, 3) as $i) {
            $this->stripe->advancePeriod($this->fixture['provider_subscription_id']);
            $manager->applyProviderSubscription($subscription->refresh(), $this->fixture['provider_subscription_id'], 'evt_c' . $i, now());
        }
        $this->assertSame([9000, 9000, 12000], array_slice(array_column($this->stripe->charges, 'amount'), -3));
        $this->assertSame('120.00', (string) $subscription->refresh()->price_snapshot);
        $this->assertNull($subscription->provider_schedule_id);
    }

    public function test_a_stale_acceptance_is_refused_and_nothing_changes(): void
    {
        $created = $this->change(['custom_price' => '120.00']);
        $owner = $this->fixture['customer']->user->refresh();

        $this->expectException(PlatformBillingException::class);
        try {
            $this->redemptions()->acceptBillingChange($created['offer'], $owner, str_repeat('b', 64));
        } finally {
            $this->assertSame([], $this->stripe->callsOf('applyPriceSchedule'));
            $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        }
    }

    public function test_another_tenant_cannot_see_or_accept_the_change(): void
    {
        $created = $this->change(['custom_price' => '120.00']);
        $intruder = $this->unpaidCustomer('intruder@example.test');
        $this->signInAs($intruder['user']);

        $this->get(route('offers.show', ['token' => $created['token']]))
            ->assertOk()
            ->assertDontSee('120.00')
            ->assertDontSee('Accept new pricing');

        $this->assertSame('not_for_you', $this->redemptions()->present($created['offer'], $intruder['user'])['state']);

        try {
            $this->redemptions()->acceptBillingChange($created['offer'], $intruder['user'], str_repeat('a', 64));
            $this->fail('A different tenant must not accept a billing change.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::OFFER_NOT_FOR_YOU, $e->reason);
        }

        $this->assertSame([], $this->stripe->callsOf('applyPriceSchedule'));
    }

    public function test_a_provider_failure_leaves_the_change_retryable_and_the_subscription_untouched(): void
    {
        $created = $this->change(['custom_price' => '80.00']);
        $owner = $this->fixture['customer']->user->refresh();
        $hash = $this->termsFor($created['offer'], $owner)->hash();

        $this->stripe->hooks['applyPriceSchedule'] = function (): void {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        };

        try {
            $this->redemptions()->acceptBillingChange($created['offer'], $owner, $hash);
            $this->fail('The provider failure must surface.');
        } catch (PlatformBillingException) {
        }

        $redemption = PlatformPrivateOfferRedemption::query()->sole();
        $this->assertSame('reserved', $redemption->state);
        $this->assertNull($this->subscription()->offer_redemption_id);
        $this->assertSame('100.00', (string) $this->subscription()->price_snapshot);

        // The sweep re-drives it with the same idempotency key.
        $this->stripe->hooks = [];
        $this->artisan('platform-offers:sweep')->assertExitCode(0);

        $this->assertSame('redeemed', $redemption->refresh()->state);
        $keys = array_column(array_column($this->stripe->callsOf('applyPriceSchedule'), 'args'), 'key');
        $this->assertCount(1, array_unique($keys), 'a retry reuses the same idempotency key');
    }

    // =================================================================
    // Subscription changes and cancellation on private pricing
    // =================================================================

    private function redeemedCustomPricing(): array
    {
        $created = $this->change(['custom_price' => '80.00', 'discount_type' => 'fixed', 'discount_value' => '20.00', 'discount_duration' => 'periods', 'discount_periods' => 2]);
        $owner = $this->fixture['customer']->user->refresh();
        $this->redemptions()->acceptBillingChange($created['offer'], $owner, $this->termsFor($created['offer'], $owner)->hash());

        return [$created, $owner];
    }

    public function test_changing_plan_while_on_private_pricing_needs_an_explicit_acknowledgement(): void
    {
        $this->redeemedCustomPricing();
        $workspace = $this->fixture['workspace'];
        $agency = $this->catalog(WorkspacePlanTier::Agency);
        $manager = app(PlatformSubscriptionManager::class);
        $schedule = $this->subscription()->provider_schedule_id;
        $this->assertNotNull($schedule);

        try {
            $manager->requestPlanChange($workspace, $agency, (int) $workspace->owner_user_id);
            $this->fail('A plan change that ends private pricing must be acknowledged.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::CHANGE_ENDS_PRIVATE_PRICING, $e->reason);
        }

        // Nothing moved.
        $this->assertSame([], $this->stripe->callsOf('releaseSchedule'));
        $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'));
        $this->assertSame($schedule, $this->subscription()->provider_schedule_id);

        // Acknowledged: the schedule is released first, then the plan moves to ITS standard price.
        $manager->requestPlanChange($workspace, $agency, (int) $workspace->owner_user_id, acknowledgeEndsPrivatePricing: true);

        $subscription = $this->subscription();
        $this->assertCount(1, $this->stripe->callsOf('releaseSchedule'));
        $this->assertNull($subscription->offer_redemption_id);
        $this->assertNull($subscription->provider_schedule_id);
        $this->assertSame('price_fake_agency', $subscription->provider_price_id);
        $this->assertSame('249.00', (string) $subscription->price_snapshot);
        $this->assertSame('ended', PlatformPrivateOfferRedemption::query()->sole()->reconcile_state);
    }

    public function test_the_plan_page_tells_the_customer_their_private_pricing_and_warns_before_a_change(): void
    {
        $this->redeemedCustomPricing();
        $owner = $this->fixture['customer']->user->refresh();
        $this->signInAs($owner);

        $this->get(route('customer.workspaces.plan.show', [$this->fixture['workspace']->uid]))
            ->assertOk()
            ->assertSee('Private pricing')
            ->assertSee('60.00 USD every 28 days until')
            ->assertSee('then 80.00 USD every 28 days')
            ->assertSee('this ends my private pricing');
    }

    public function test_cancelling_keeps_the_agreement_until_the_period_ends(): void
    {
        $this->redeemedCustomPricing();

        $subscription = app(PlatformSubscriptionManager::class)->requestCancellation($this->fixture['workspace']);

        $this->assertTrue((bool) $subscription->cancel_at_period_end);
        $this->assertNotNull($subscription->offer_redemption_id);
        $this->assertSame([], $this->stripe->callsOf('releaseSchedule'));
    }

    public function test_resubscribing_after_the_end_does_not_carry_private_pricing_back(): void
    {
        $this->redeemedCustomPricing();
        $this->stripe->setSubscriptionStatus($this->fixture['provider_subscription_id'], PlatformSubscriptionStatus::Canceled);
        $manager = app(PlatformSubscriptionManager::class);
        $manager->applyProviderSubscription($this->subscription(), $this->fixture['provider_subscription_id'], 'evt_end', now());

        $manager->startResubscribeCheckout(
            $this->fixture['workspace']->refresh(), $this->catalog(WorkspacePlanTier::Growth), 'owner@example.test', 'https://a.test/ok', 'https://a.test/no',
        );

        $subscription = $this->subscription();
        $this->assertNull($subscription->offer_redemption_id);
        $this->assertNull($subscription->provider_schedule_id);
        $this->assertSame('100.00', (string) $subscription->price_snapshot, 'the public price');
        $this->assertSame('redeemed', PlatformPrivateOfferRedemption::query()->sole()->state, 'the history is kept');
    }

    public function test_the_provider_moving_a_customer_off_their_agreed_prices_is_flagged_not_silently_accepted(): void
    {
        $this->redeemedCustomPricing();
        $this->stripe->definePrice('price_someone_else');
        $this->stripe->subscriptions[$this->fixture['provider_subscription_id']]['price'] = 'price_someone_else';

        app(PlatformSubscriptionManager::class)->applyProviderSubscription($this->subscription(), $this->fixture['provider_subscription_id'], 'evt_drift', now());

        $this->assertSame('drift', PlatformPrivateOfferRedemption::query()->sole()->reconcile_state);
        $this->assertSame('100.00', (string) $this->subscription()->price_snapshot, 'the local record is not rewritten from an unknown Price');
    }
}
