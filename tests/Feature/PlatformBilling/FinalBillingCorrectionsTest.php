<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\Offers\OfferReconciler;
use App\Library\PlatformBilling\Offers\TrialLedger;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\PlatformTrialClaim;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * The last review round on PR #440: finalization before completion, cadence,
 * currency, provider-customer trial identity and push/settlement races.
 */
class FinalBillingCorrectionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->sellableTier(WorkspacePlanTier::Core, null, '49.00');
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        $this->sellableTier(WorkspacePlanTier::Agency, null, '249.00');
    }

    private function promoRedemption(): array
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 2,
        ]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $providerSubscriptionId = $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail();
        $this->redemptions()->handleReturn($redemption, $customer['user']);

        return [$customer, $redemption->refresh(), $providerSubscriptionId, $created];
    }

    private function localSubscription(array $customer): PlatformSubscription
    {
        return PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->firstOrFail();
    }

    /** Makes the Nth retrieveSubscription call (counted from now) fail once. */
    private function failNthRetrieve(int $nth): void
    {
        $count = 0;
        $this->stripe->hooks['retrieveSubscription'] = function (array $args, $gateway) use (&$count, $nth): void {
            if (++$count === $nth) {
                unset($gateway->hooks['retrieveSubscription']);

                throw new \RuntimeException('connection reset');
            }
        };
    }

    // =================================================================
    // P1 — complete only after local finalization has succeeded
    // =================================================================

    public function test_a_proven_own_schedule_is_not_completed_until_local_finalization_succeeds(): void
    {
        [$customer, $redemption, $providerSubscriptionId] = $this->promoRedemption();
        $agency = WorkspacePlanCatalog::query()->where('tier', 'agency')->firstOrFail();

        // The provider moved the subscription (an upgrade whose response and local
        // write were lost) and our own schedule is attached but unrecorded.
        $ownSchedule = $this->stripe->subscriptions[$providerSubscriptionId]['schedule'];
        $redemption->forceFill(['schedule_state' => 'pending', 'provider_schedule_id' => null])->save();
        $this->localSubscription($customer)->forceFill(['provider_schedule_id' => null])->save();
        $this->stripe->subscriptions[$providerSubscriptionId]['price'] = $agency->provider_price_id;
        $this->assertSame($ownSchedule, $this->stripe->scheduleKeys['offer-schedule:' . $redemption->uid . ':create'] ?? $ownSchedule);

        // Finalization (the 2nd provider read) fails once.
        $this->failNthRetrieve(2);
        app(OfferReconciler::class)->driveSchedule($redemption->refresh());

        $redemption->refresh();
        $this->assertSame('pending', $redemption->schedule_state, 'not completed: the sweep can still recover it');
        $this->assertNotNull($this->localSubscription($customer)->offer_redemption_id, 'the agreement is still on file');
        $this->assertCount(0, $this->stripe->callsOf('releaseSchedule'));

        // The retry finalizes, then closes the agreement and releases OUR schedule.
        app(OfferReconciler::class)->driveSchedule($redemption->refresh());

        $subscription = $this->localSubscription($customer);
        $this->assertNull($subscription->offer_redemption_id);
        $this->assertSame((string) $agency->provider_price_id, (string) $subscription->provider_price_id, 'local Price caught up');
        $this->assertSame('none', $redemption->refresh()->schedule_state);
        $this->assertCount(1, $this->stripe->callsOf('releaseSchedule'), 'exactly once');
    }

    public function test_catch_up_pricing_keeps_its_retry_marker_until_the_finalizer_succeeds(): void
    {
        [$customer, $redemption, $providerSubscriptionId] = $this->promoRedemption();
        $manager = app(PlatformSubscriptionManager::class);
        $promoPrice = (string) $this->localSubscription($customer)->provider_price_id;

        $manager->requestCancellation($customer['workspace']);
        $redemption->forceFill(['promo_ends_at' => now()->subDay()])->save();

        // After the provider price change succeeds, the finalizer's read fails.
        $this->stripe->interleaveOnce('changeSubscriptionPrice', function (array $args, $gateway): void {
            $gateway->interleaveOnce('retrieveSubscription', function (): void {
                throw new \RuntimeException('connection reset');
            });
        });

        $manager->resumeSubscription($customer['workspace']);

        $this->assertSame('pending', $redemption->refresh()->schedule_state, 'retry marker kept');
        $this->assertSame($promoPrice, (string) $this->localSubscription($customer)->provider_price_id, 'local Price not yet caught up');
        $this->assertCount(1, $this->stripe->callsOf('changeSubscriptionPrice'));

        // The next sweep reconciles WITHOUT a second provider price change.
        $this->artisan('platform-offers:sweep')->assertExitCode(0);

        $this->assertSame('none', $redemption->refresh()->schedule_state);
        $this->assertSame('price_fake_growth', (string) $this->localSubscription($customer)->provider_price_id);
        $this->assertCount(1, $this->stripe->callsOf('changeSubscriptionPrice'), 'finished exactly once');
    }

    // =================================================================
    // P1 — billing cadence
    // =================================================================

    public function test_a_price_only_offer_never_changes_an_annual_subscription_to_28_days(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '100.00',
        ]);
        // A legacy annual subscriber on a catalog that has since moved to 28 days.
        PlatformSubscription::query()->whereKey($fixture['subscription']->id)->update(['billing_cycle_snapshot' => 'yearly']);

        $reason = null;
        try {
            $this->redemptions()->applyBillingChangeAsOwner($change['offer']->refresh(), $this->platformAdminId(), 'Agreed on a call.');
        } catch (PlatformBillingException $e) {
            $reason = $e->reason;
        }

        $this->assertSame(PlatformBillingException::CHANGE_NOT_PERMITTED, $reason);
        $this->assertCount(0, $this->stripe->callsOf('applyPriceSchedule'));
        $this->assertCount(0, $this->stripe->callsOf('changeSubscriptionPrice'));
        $this->assertSame('yearly', (string) $fixture['subscription']->refresh()->billing_cycle_snapshot, 'legacy terms untouched');

        $user = $fixture['customer']->user;
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->assertSame('unavailable', $this->redemptions()->present($change['offer']->refresh(), $user)['state']);
    }

    public function test_an_ordinary_28_day_price_change_still_applies(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '80.00',
        ]);

        $redemption = $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.');

        $this->assertSame('redeemed', $redemption->state);
        $this->assertCount(1, $this->stripe->callsOf('applyPriceSchedule'));
    }

    // =================================================================
    // P2 — checkout currency
    // =================================================================

    public function test_an_offer_made_in_another_currency_than_the_catalog_is_refused_before_checkout(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $eur = DB::table('currencies')->where('code', 'EUR')->value('id') ?? DB::table('currencies')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Euro', 'code' => 'EUR', 'format' => '€',
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        WorkspacePlanCatalog::query()->where('tier', 'growth')->update(['currency_id' => $eur]);

        $view = $this->redemptions()->present($created['offer']->refresh(), $customer['user']);
        $this->assertSame('unavailable', $view['state']);

        $reason = null;
        try {
            $this->acceptAs($customer['user'], $created['offer']->refresh(), null);
        } catch (PlatformBillingException $e) {
            $reason = $e->reason;
        }

        $this->assertSame(PlatformBillingException::OFFER_UNAVAILABLE, $reason);
        $this->assertCount(0, $this->stripe->callsOf('createSubscriptionCheckout'));
        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
    }

    // =================================================================
    // P2 — provider customer identity in trial reservations
    // =================================================================

    public function test_the_reservation_persists_the_provider_customer_and_blocks_a_second_workspace(): void
    {
        $ledger = app(TrialLedger::class);
        $first = $this->unpaidCustomer('first@example.test');
        $second = $this->unpaidCustomer('second@example.test');

        $this->assertTrue($ledger->reserve($first['workspace'], 'first@example.test', 'offer', null, $c1, $s1, 'cus_shared'));
        $this->assertSame('cus_shared', PlatformTrialClaim::query()->where('workspace_id', $first['workspace']->id)->value('provider_customer_id'));

        // Different mailbox, same provider customer, nothing granted yet.
        $this->assertFalse($ledger->reserve($second['workspace'], 'second@example.test', 'offer', null, $c2, $s2, 'cus_shared'));
        $this->assertFalse(PlatformTrialClaim::query()->where('workspace_id', $second['workspace']->id)->exists());

        // The same Workspace reusing its reservation keeps the identity.
        $this->assertTrue($ledger->reserve($first['workspace'], 'first@example.test', 'offer', null, $c3, $s3, 'cus_shared'));
        $this->assertFalse($c3);
        $this->assertSame(1, PlatformTrialClaim::query()->where('provider_customer_id', 'cus_shared')->count());
    }

    public function test_retiring_one_checkout_keeps_the_trial_another_live_attempt_relies_on(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $other = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);

        $this->acceptAs($customer['user'], $created['offer']);
        $first = PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail();
        $this->acceptAs($customer['user'], $other['offer']);
        $second = PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('reserved', $second->refresh()->state);

        // Make the FIRST one retirable while the second is still live.
        $first->refresh()->forceFill(['state' => 'reserved', 'reserved_until' => now()->addHour()])->save();
        $this->assertTrue($this->offers()->retireUnpaid($first, 'cancelled'));

        $this->assertTrue(
            PlatformTrialClaim::query()->where('workspace_id', $customer['workspace']->id)->where('state', 'reserved')->exists(),
            'the other live checkout keeps its trial',
        );

        // Retiring the last live attempt does give it back.
        $this->assertTrue($this->offers()->retireUnpaid($second->refresh(), 'cancelled'));
        $this->assertFalse(PlatformTrialClaim::query()->where('workspace_id', $customer['workspace']->id)->exists());
    }

    public function test_a_reservation_learns_the_provider_customer_when_it_becomes_known(): void
    {
        $ledger = app(TrialLedger::class);
        $first = $this->unpaidCustomer('first@example.test');

        $ledger->reserve($first['workspace'], 'first@example.test', 'offer');
        $this->assertNull(PlatformTrialClaim::query()->where('workspace_id', $first['workspace']->id)->value('provider_customer_id'));

        $ledger->reserve($first['workspace'], 'first@example.test', 'offer', null, $c, $s, 'cus_later');
        $this->assertSame('cus_later', PlatformTrialClaim::query()->where('workspace_id', $first['workspace']->id)->value('provider_customer_id'));
    }

    public function test_the_unique_provider_customer_key_backs_the_reservation_against_a_concurrent_insert(): void
    {
        $first = $this->unpaidCustomer('first@example.test');
        $second = $this->unpaidCustomer('second@example.test');

        PlatformTrialClaim::query()->forceCreate([
            'workspace_id' => $first['workspace']->id, 'email_key' => 'first@example.test', 'provider_customer_id' => 'cus_race',
            'state' => 'reserved', 'source' => 'offer', 'reserved_until' => now()->addHour(),
        ]);

        // What a racing request would do if its read had missed the first row.
        $this->expectException(QueryException::class);
        PlatformTrialClaim::query()->forceCreate([
            'workspace_id' => $second['workspace']->id, 'email_key' => 'second@example.test', 'provider_customer_id' => 'cus_race',
            'state' => 'reserved', 'source' => 'offer', 'reserved_until' => now()->addHour(),
        ]);
    }

    // =================================================================
    // P2 — provider push / settlement races
    // =================================================================

    public function test_a_settlement_that_arrives_while_the_push_is_on_the_wire_decides_nothing(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '60.00',
        ]);
        $seenInside = null;

        // While the push is in flight: the offer is revoked and a sweep runs.
        $this->stripe->interleaveOnce('applyPriceSchedule', function () use ($change, &$seenInside): void {
            $this->offers()->revoke($change['offer']->refresh(), $this->platformAdminId(), 'Withdrawn.');
            $this->redemptions()->sweepUnappliedBillingChanges();
            $seenInside = PlatformPrivateOfferRedemption::query()->sole()->state;
        });
        // ...and Stripe then creates the schedule but the response is lost.
        $this->stripe->loseNextScheduleResponse = true;

        try {
            $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.');
            $this->fail('The lost response must surface.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::PROVIDER_FAILED, $e->reason);
        }

        $this->assertSame('reserved', $seenInside, 'the in-flight reservation was not released by the sweep');
        $this->assertSame('reserved', PlatformPrivateOfferRedemption::query()->sole()->state);

        // The next sweep reconciles the schedule Stripe did create.
        $this->redemptions()->sweepUnappliedBillingChanges();

        $this->assertSame('redeemed', PlatformPrivateOfferRedemption::query()->sole()->state);
        $this->assertCount(0, $this->stripe->callsOf('releaseSchedule'));
    }

    public function test_a_push_that_loses_the_race_to_a_settlement_is_never_sent(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '60.00',
        ]);

        // The first push fails before anything is created; the offer is revoked.
        $this->stripe->interleaveOnce('applyPriceSchedule', function (): void {
            throw new \RuntimeException('connection reset');
        });
        try {
            $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.');
        } catch (\Throwable) {
            // expected
        }
        $this->offers()->revoke($change['offer']->refresh(), $this->platformAdminId(), 'Withdrawn.');
        $reservation = PlatformPrivateOfferRedemption::query()->sole();
        $stale = clone $reservation;

        // Settlement wins: nothing exists at the provider, so it is released.
        $this->redemptions()->sweepUnappliedBillingChanges();
        $this->assertSame('released', $reservation->refresh()->state);

        // A push that read the reservation BEFORE the settlement now runs.
        $push = new \ReflectionMethod($this->redemptions(), 'pushBillingChange');
        $push->setAccessible(true);
        $sub = PlatformSubscription::query()->findOrFail($fixture['subscription']->id);
        $callsBefore = count($this->stripe->callsOf('applyPriceSchedule'));

        try {
            $push->invoke($this->redemptions(), $stale, $sub, \App\Library\PlatformBilling\Offers\OfferTerms::fromArray($stale->terms_snapshot));
            $this->fail('A released reservation must not be pushed.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::OFFER_UNAVAILABLE, $e->reason);
        }

        $this->assertCount($callsBefore, $this->stripe->callsOf('applyPriceSchedule'), 'nothing was sent to the provider');
    }
}
