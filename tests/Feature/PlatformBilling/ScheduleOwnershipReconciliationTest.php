<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\Offers\OfferReconciler;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * Schedule OWNERSHIP is proven, never assumed (PR #440 P1s):
 *  - a provider success whose response was lost is reconciled, even after a revoke;
 *  - a transient failure while proving or replaying stays retryable;
 *  - a genuinely foreign schedule is surfaced and never closed over or released.
 */
class ScheduleOwnershipReconciliationTest extends TestCase
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

    private function driver(): OfferReconciler
    {
        return app(OfferReconciler::class);
    }

    /** A billing change whose schedule WAS created at the provider but whose response was lost. @return array{0: array, 1: PlatformPrivateOfferRedemption} */
    private function ambiguousBillingChange(): array
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '60.00',
        ]);

        $this->stripe->loseNextScheduleResponse = true;

        try {
            $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.');
            $this->fail('The lost response must surface.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::PROVIDER_FAILED, $e->reason);
        }

        $reservation = PlatformPrivateOfferRedemption::query()->sole();
        $this->assertSame('reserved', $reservation->state);
        $this->assertNotNull($this->stripe->subscriptions[$fixture['provider_subscription_id']]['schedule'], 'the schedule exists at the provider');

        return [$fixture + ['change' => $change], $reservation];
    }

    // ---- P1: ambiguous provider success, even after a revoke --------------------------------

    public function test_a_lost_response_is_reconciled_even_when_the_offer_is_revoked_afterwards(): void
    {
        [$fixture, $reservation] = $this->ambiguousBillingChange();

        $this->offers()->revoke($fixture['change']['offer']->refresh(), $this->platformAdminId(), 'Withdrawn.');
        $this->assertSame('reserved', $reservation->refresh()->state, 'revoke does not release a reservation the provider may hold');

        $this->redemptions()->sweepUnappliedBillingChanges();

        $reservation->refresh();
        $this->assertSame('redeemed', $reservation->state, 'the accepted change the provider already holds is adopted');
        $this->assertNotNull($reservation->provider_schedule_id);
        $this->assertSame(
            $reservation->provider_schedule_id,
            PlatformSubscription::query()->findOrFail($fixture['subscription']->id)->provider_schedule_id,
        );
        $this->assertCount(0, $this->stripe->callsOf('releaseSchedule'), 'no schedule was released');
    }

    public function test_a_lost_response_is_completed_by_the_normal_retry_without_a_second_schedule(): void
    {
        [$fixture, $reservation] = $this->ambiguousBillingChange();

        $this->redemptions()->sweepUnappliedBillingChanges();

        $this->assertSame('redeemed', $reservation->refresh()->state);
        $this->assertCount(1, array_unique(array_values($this->stripe->scheduleKeys)), 'exactly one schedule ever existed');
    }

    public function test_an_expired_reservation_whose_schedule_was_created_is_adopted_not_released(): void
    {
        [$fixture, $reservation] = $this->ambiguousBillingChange();
        $reservation->forceFill(['reserved_until' => now()->subDay()])->save();

        $this->assertSame(0, $this->offers()->sweepExpiredReservations());
        $this->redemptions()->sweepUnappliedBillingChanges();

        $this->assertSame('redeemed', $reservation->refresh()->state);
    }

    // ---- P1: transient failures stay retryable -----------------------------------------------

    public function test_an_inconclusive_proof_leaves_the_reservation_reserved_and_it_recovers_on_retry(): void
    {
        [$fixture, $reservation] = $this->ambiguousBillingChange();
        $this->offers()->revoke($fixture['change']['offer']->refresh(), $this->platformAdminId(), 'Withdrawn.');

        $this->stripe->proofFailure = PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        $this->redemptions()->sweepUnappliedBillingChanges();
        $this->assertSame('reserved', $reservation->refresh()->state, 'a network failure is not an answer');

        $this->stripe->proofFailure = null;
        $this->redemptions()->sweepUnappliedBillingChanges();
        $this->assertSame('redeemed', $reservation->refresh()->state);
    }

    public function test_a_lost_initial_promotion_push_stays_pending_through_transient_failures_and_then_applies(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 2,
        ]);

        // The first push creates the schedule but the response is lost.
        $this->stripe->loseNextScheduleResponse = true;
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail();
        $this->redemptions()->handleReturn($redemption, $customer['user']);

        $redemption->refresh();
        $this->assertSame('pending', $redemption->schedule_state, 'ambiguous, not failed');
        $this->assertNull($redemption->provider_schedule_id);

        // A transient failure on the next attempt must not make it terminal either.
        $this->stripe->interleaveOnce('applyPriceSchedule', function (): void {
            throw new \RuntimeException('connection reset');
        });
        $this->driver()->driveSchedule($redemption->refresh());
        $this->assertSame('pending', $redemption->refresh()->schedule_state, 'a transient failure is retried');

        // And the retry replays the push, proves the schedule is ours and records it.
        $this->driver()->driveSchedule($redemption->refresh());
        $this->assertSame('applied', $redemption->refresh()->schedule_state);
        $this->assertCount(1, array_unique(array_values($this->stripe->scheduleKeys)));
    }

    // ---- P1: genuine foreign schedules --------------------------------------------------------

    public function test_a_genuinely_foreign_schedule_is_surfaced_and_never_released_or_overwritten(): void
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

        // Our own schedule is gone; a Dashboard user attached THEIR schedule and
        // moved the subscription onto a Price outside the agreement.
        $redemption->refresh()->forceFill(['schedule_state' => 'pending', 'provider_schedule_id' => null])->save();
        PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->update(['provider_schedule_id' => null]);
        $this->stripe->scheduleKeys = [];
        $this->stripe->subscriptions[$providerSubscriptionId]['schedule'] = 'sub_sched_dashboard';
        $this->stripe->subscriptions[$providerSubscriptionId]['price'] = WorkspacePlanCatalog::query()->where('tier', 'agency')->firstOrFail()->provider_price_id;
        $callsBefore = count($this->stripe->callsOf('releaseSchedule'));

        $this->driver()->driveSchedule($redemption->refresh());

        $redemption->refresh();
        $this->assertSame('failed', $redemption->schedule_state);
        $this->assertSame($redemption->state, 'redeemed');
        $this->assertCount($callsBefore, $this->stripe->callsOf('releaseSchedule'), 'the foreign schedule was never released');
        $this->assertSame('sub_sched_dashboard', $this->stripe->subscriptions[$providerSubscriptionId]['schedule']);
        $this->assertNotNull(
            PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->firstOrFail()->offer_redemption_id,
            'the agreement was not closed over a schedule that is not ours',
        );
    }

    public function test_an_inconclusive_proof_on_a_moved_subscription_does_not_close_the_agreement(): void
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

        $redemption->refresh()->forceFill(['schedule_state' => 'pending', 'provider_schedule_id' => null])->save();
        PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->update(['provider_schedule_id' => null]);
        $this->stripe->subscriptions[$providerSubscriptionId]['price'] = WorkspacePlanCatalog::query()->where('tier', 'agency')->firstOrFail()->provider_price_id;
        $this->stripe->proofFailure = PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);

        $this->driver()->driveSchedule($redemption->refresh());

        $this->assertSame('pending', $redemption->refresh()->schedule_state, 'retryable, not failed and not closed');
        $this->assertNotNull(PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->firstOrFail()->offer_redemption_id);
    }

    public function test_a_billing_change_over_a_foreign_schedule_is_refused_and_leaves_the_reservation_for_attention(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->stripe->subscriptions[$fixture['provider_subscription_id']]['schedule'] = 'sub_sched_dashboard';
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '60.00',
        ]);

        try {
            $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.');
            $this->fail('A foreign schedule must refuse the change.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::SCHEDULE_NOT_OURS, $e->reason);
        }

        $this->assertSame('sub_sched_dashboard', $this->stripe->subscriptions[$fixture['provider_subscription_id']]['schedule']);
        $this->assertCount(0, $this->stripe->callsOf('releaseSchedule'));

        // Within the proof window a proven-foreign schedule means nothing of ours was applied.
        $this->redemptions()->sweepUnappliedBillingChanges();
        $this->redemptions()->sweepUnappliedBillingChanges();
        $this->assertSame('sub_sched_dashboard', $this->stripe->subscriptions[$fixture['provider_subscription_id']]['schedule']);
    }

    public function test_past_the_proof_window_an_unprovable_schedule_is_never_released_from_under_us(): void
    {
        [$fixture, $reservation] = $this->ambiguousBillingChange();
        // The idempotency key has aged out, so ownership can no longer be proven.
        $this->stripe->scheduleKeys = [];
        $reservation->forceFill(['reserved_until' => now()->subDays(2), 'created_at' => now()->subDays(2)])->save();

        $this->redemptions()->sweepUnappliedBillingChanges();

        $this->assertSame('reserved', $reservation->refresh()->state, 'left for an operator, never orphaned');
    }
}
