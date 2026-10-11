<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\Offers\OfferTerms;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\PlatformTrialClaim;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Str;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * One regression per confirmed Codex finding on PR #440 (failure paths and
 * authorization boundaries included).
 */
class PrivateOfferReviewFindingsTest extends TestCase
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

    /** Accept, pay on Stripe, and let the shared seams confirm it. @return array{0: PlatformPrivateOfferRedemption, 1: string} */
    private function redeem(array $customer, $offer): array
    {
        $session = $this->acceptAs($customer['user'], $offer);
        $providerSubscriptionId = $this->payOnStripe($session->sessionId);

        $redemption = PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail();
        $this->redemptions()->handleReturn($redemption, $customer['user']);

        return [$redemption->refresh(), $providerSubscriptionId];
    }

    private function accept(array $customer, $offer, ?string $workspaceUid = null, ?string $hash = null)
    {
        $hash ??= $this->redemptions()->present($offer->refresh(), $customer['user'], $workspaceUid)['terms']?->hash() ?? str_repeat('0', 64);

        return $this->redemptions()->accept(
            $offer->refresh(),
            $customer['user'],
            $hash,
            fn (PlatformPrivateOfferRedemption $r): array => ['https://app.test/ok/' . $r->uid, 'https://app.test/no/' . $r->uid],
            $workspaceUid,
        );
    }

    private function reasonOf(callable $call): ?string
    {
        try {
            $call();
        } catch (PlatformBillingException $e) {
            return $e->reason;
        }

        return null;
    }

    // =================================================================
    // P1 — a failed upgrade must not destroy the negotiated schedule
    // =================================================================

    public function test_a_failed_upgrade_keeps_the_negotiated_agreement_and_puts_its_schedule_back(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 3,
        ]);
        [$redemption] = $this->redeem($customer, $created['offer']);
        $manager = app(PlatformSubscriptionManager::class);
        $agency = WorkspacePlanCatalog::query()->where('tier', 'agency')->firstOrFail();
        $ownerId = (int) $customer['workspace']->owner_user_id;

        $this->assertCount(1, $this->stripe->callsOf('applyPriceSchedule'));

        $this->stripe->interleaveOnce('changeSubscriptionPrice', function (): void {
            throw new \RuntimeException('Stripe is down');
        });

        try {
            $manager->requestPlanChange($customer['workspace'], $agency, $ownerId, true);
            $this->fail('The upgrade should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Stripe is down', $e->getMessage());
        }

        $subscription = PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->firstOrFail();
        $this->assertSame((int) $redemption->id, (int) $subscription->offer_redemption_id, 'the agreement is still on file');
        $this->assertSame('redeemed', $redemption->refresh()->state);
        $this->assertNotSame('ended', (string) $redemption->reconcile_state);
        $this->assertSame('applied', $redemption->schedule_state, 'the negotiated schedule was put back');
        $this->assertCount(2, $this->stripe->callsOf('applyPriceSchedule'), 'initial push + restore');

        // The retry then succeeds and ONLY now ends the agreement.
        $this->assertSame(PlatformSubscriptionManager::CHANGE_UPGRADED, $manager->requestPlanChange($customer['workspace'], $agency, $ownerId, true));
        $this->assertNull($subscription->refresh()->offer_redemption_id);
        $this->assertSame('ended', (string) $redemption->refresh()->reconcile_state);
    }

    public function test_a_failed_billing_change_push_keeps_the_previous_agreement(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 3,
        ]);
        [$old] = $this->redeem($customer, $created['offer']);

        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $customer['workspace']->uid, 'custom_price' => '60.00',
        ]);

        $this->stripe->interleaveOnce('applyPriceSchedule', function (): void {
            throw new \RuntimeException('Stripe is down');
        });

        try {
            $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.');
            $this->fail('The change should have failed.');
        } catch (\RuntimeException) {
            // expected
        }

        $subscription = PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->firstOrFail();
        $this->assertSame((int) $old->id, (int) $subscription->offer_redemption_id, 'the earlier agreement is untouched');
        $this->assertNotSame('ended', (string) $old->refresh()->reconcile_state);
    }

    public function test_an_upgrade_that_applied_but_lost_its_response_is_not_undone_by_restoring_the_old_schedule(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 3,
        ]);
        [$redemption] = $this->redeem($customer, $created['offer']);
        $agency = WorkspacePlanCatalog::query()->where('tier', 'agency')->firstOrFail();

        // Stripe moved the subscription, but the response never arrived.
        $this->stripe->interleaveOnce('changeSubscriptionPrice', function (array $args, $gateway): void {
            $gateway->subscriptions[$args['subscription']]['price'] = $args['price'];

            throw new \RuntimeException('connection reset');
        });

        $result = app(PlatformSubscriptionManager::class)
            ->requestPlanChange($customer['workspace'], $agency, (int) $customer['workspace']->owner_user_id, true);

        $this->assertSame(PlatformSubscriptionManager::CHANGE_UPGRADED, $result);
        $this->assertCount(1, $this->stripe->callsOf('applyPriceSchedule'), 'the old schedule was NOT put back over the upgrade');
        $this->assertNull(PlatformSubscription::query()->where('workspace_id', $customer['workspace']->id)->firstOrFail()->offer_redemption_id);
        $this->assertSame('ended', (string) $redemption->refresh()->reconcile_state);
    }

    public function test_a_billing_change_is_refused_when_only_the_providers_period_has_moved(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '120.00',
        ]);
        $user = $fixture['customer']->user;
        $user->forceFill(['email_verified_at' => now()])->save();
        $shown = $this->redemptions()->present($change['offer']->refresh(), $user)['terms'];

        // The provider renewed; our copy of the period has NOT caught up (no webhook yet).
        $this->stripe->advancePeriod($fixture['provider_subscription_id']);

        $reason = $this->reasonOf(fn () => $this->redemptions()->acceptBillingChange($change['offer']->refresh(), $user, $shown->hash()));

        $this->assertSame(PlatformBillingException::OFFER_TERMS_CHANGED, $reason);
        $this->assertCount(0, $this->stripe->callsOf('applyPriceSchedule'));
    }

    public function test_the_trial_ledger_reports_only_the_reservation_it_created(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $ledger = app(\App\Library\PlatformBilling\Offers\TrialLedger::class);

        $this->assertTrue($ledger->reserve($customer['workspace'], 'buyer@example.test', 'offer', null, $first));
        $this->assertTrue($first);
        $this->assertTrue($ledger->reserve($customer['workspace'], 'buyer@example.test', 'offer', null, $second), 'reused');
        $this->assertFalse($second, 'a reused reservation is not the second caller\'s to release');
    }

    // =================================================================
    // P1 — a pending downgrade is never silently bound to a newer agreement
    // =================================================================

    public function test_new_private_pricing_cannot_be_applied_while_a_plan_change_is_pending(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $manager = app(PlatformSubscriptionManager::class);
        $core = WorkspacePlanCatalog::query()->where('tier', 'core')->firstOrFail();

        $this->assertSame(
            PlatformSubscriptionManager::CHANGE_DOWNGRADE_SCHEDULED,
            $manager->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id),
        );

        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '60.00',
        ]);

        $reason = $this->reasonOf(fn () => $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.'));

        $this->assertSame(PlatformBillingException::CHANGE_IN_PROGRESS, $reason);
        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        $this->assertCount(0, $this->stripe->callsOf('applyPriceSchedule'));
    }

    // =================================================================
    // P1 — email-bound offers need an explicit, owned Workspace
    // =================================================================

    public function test_an_email_offer_with_several_workspaces_asks_which_and_never_guesses(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $second = Workspace::query()->create(['name' => 'Second Co', 'owner_user_id' => $customer['user']->id, 'is_active' => true]);
        $stranger = $this->unpaidCustomer('stranger@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $offer = $created['offer'];

        $view = $this->redemptions()->present($offer, $customer['user']);
        $this->assertSame('needs_workspace', $view['state']);
        $this->assertNull($view['terms']);
        $this->assertCount(2, $view['workspaces']);

        // Not guessed on accept...
        $this->assertSame(PlatformBillingException::OFFER_WORKSPACE_REQUIRED, $this->reasonOf(fn () => $this->accept($customer, $offer)));
        // ...and only ever one they own.
        $this->assertSame(
            PlatformBillingException::OFFER_NOT_FOR_YOU,
            $this->reasonOf(fn () => $this->accept($customer, $offer, (string) $stranger['workspace']->uid, str_repeat('0', 64))),
        );
        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        $this->assertCount(0, $this->stripe->callsOf('createSubscriptionCheckout'));

        // The explicit choice goes through, for exactly that Workspace.
        $this->accept($customer, $offer, (string) $second->uid);
        $this->assertSame((int) $second->id, (int) PlatformPrivateOfferRedemption::query()->sole()->workspace_id);
    }

    public function test_a_single_workspace_is_still_used_without_a_choice(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $this->accept($customer, $created['offer']);

        $this->assertSame((int) $customer['workspace']->id, (int) PlatformPrivateOfferRedemption::query()->sole()->workspace_id);
    }

    public function test_the_offer_page_lists_workspaces_to_choose_from(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        Workspace::query()->create(['name' => 'Second Co', 'owner_user_id' => $customer['user']->id, 'is_active' => true]);
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        $this->signInAs($customer['user']);
        $this->get(route('offers.show', ['token' => $created['token']]))
            ->assertOk()
            ->assertSee('data-role="offer-needs-workspace"', false)
            ->assertDontSee('data-role="offer-accept-form"', false);
    }

    // =================================================================
    // P1 — billing-change reservations are never released by the Checkout cleanup
    // =================================================================

    public function test_an_old_billing_change_reservation_is_not_released_while_the_provider_holds_its_schedule(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '60.00',
        ]);

        // The provider applied the schedule, but the response / local write was lost.
        $this->stripe->interleaveOnce('applyPriceSchedule', function (): void {
            throw new \RuntimeException('connection reset');
        });
        try {
            $this->redemptions()->applyBillingChangeAsOwner($change['offer'], $this->platformAdminId(), 'Agreed on a call.');
        } catch (\Throwable) {
            // expected
        }

        $reservation = PlatformPrivateOfferRedemption::query()->sole();
        $this->assertSame('reserved', $reservation->state);
        $reservation->forceFill(['reserved_until' => now()->subDay()])->save();

        // The provider DOES hold a schedule on this subscription now.
        $this->stripe->subscriptions[$fixture['provider_subscription_id']]['schedule'] = 'sub_sched_applied_but_unrecorded';

        $this->assertSame(0, $this->offers()->sweepExpiredReservations(), 'billing changes are not Checkout reservations');
        $this->redemptions()->sweepUnappliedBillingChanges();
        $this->assertSame('reserved', $reservation->refresh()->state, 'left for an operator, never orphaned');

        // Had the provider applied nothing, the stale reservation is simply released.
        $this->stripe->subscriptions[$fixture['provider_subscription_id']]['schedule'] = null;
        $this->redemptions()->sweepUnappliedBillingChanges();
        $this->assertSame('released', $reservation->refresh()->state);
        $this->assertSame('expired_unapplied', $reservation->release_reason);
    }

    // =================================================================
    // P2 — consent date, trial reservation isolation, renewal cadence
    // =================================================================

    public function test_a_billing_change_acceptance_is_refused_when_its_effective_date_moved(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $change = $this->makeOffer([
            'kind' => 'billing_change', 'customer_email' => null,
            'workspace_uid' => $fixture['workspace']->uid, 'custom_price' => '120.00',
        ]);
        $user = $fixture['customer']->user;
        $user->forceFill(['email_verified_at' => now()])->save();

        $shown = $this->redemptions()->present($change['offer']->refresh(), $user)['terms'];
        $this->assertNotNull($shown);

        // The form is left open across a renewal.
        $this->stripe->advancePeriod($fixture['provider_subscription_id']);
        app(PlatformSubscriptionManager::class)->applyProviderSubscription(
            PlatformSubscription::query()->findOrFail($fixture['subscription']->id),
            $fixture['provider_subscription_id'],
            'evt_renewed_' . Str::random(6),
            now(),
        );

        $reason = $this->reasonOf(fn () => $this->redemptions()->acceptBillingChange($change['offer']->refresh(), $user, $shown->hash()));

        $this->assertSame(PlatformBillingException::OFFER_TERMS_CHANGED, $reason);
        $this->assertCount(0, $this->stripe->callsOf('applyPriceSchedule'));
    }

    public function test_an_invalid_submission_does_not_release_the_trial_another_checkout_holds(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);

        $this->accept($customer, $created['offer']);
        $this->assertTrue(PlatformTrialClaim::query()->where('workspace_id', $customer['workspace']->id)->where('state', 'reserved')->exists());

        // A stale / invalid second submission from the same Workspace.
        $reason = $this->reasonOf(fn () => $this->accept($customer, $created['offer'], null, str_repeat('0', 64)));

        $this->assertSame(PlatformBillingException::OFFER_TERMS_CHANGED, $reason);
        $this->assertTrue(
            PlatformTrialClaim::query()->where('workspace_id', $customer['workspace']->id)->where('state', 'reserved')->exists(),
            'the first, still-payable Checkout keeps its trial reservation',
        );
    }

    public function test_a_first_invalid_submission_still_releases_its_own_reservation(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);

        $reason = $this->reasonOf(fn () => $this->accept($customer, $created['offer'], null, str_repeat('0', 64)));

        $this->assertSame(PlatformBillingException::OFFER_TERMS_CHANGED, $reason);
        $this->assertFalse(PlatformTrialClaim::query()->where('workspace_id', $customer['workspace']->id)->exists());
    }

    public function test_the_consent_copy_states_the_cadence_actually_being_accepted(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $terms = $this->termsFor($created['offer'], $customer['user']);
        $data = $terms->toArray();

        $data['cycle'] = 'yearly';
        $data['cycle_label'] = 'Yearly';
        $html = view('offers._terms', ['terms' => OfferTerms::fromArray($data), 'plan' => []])->render();
        $this->assertStringContainsString('renews automatically yearly until you cancel', $html);
        $this->assertStringNotContainsString('every 28 days', $html);

        $html = view('offers._terms', ['terms' => $terms, 'plan' => []])->render();
        $this->assertStringContainsString('renews automatically every 28 days until you cancel', $html);
    }

    // =================================================================
    // P2 — verification UI while registration is closed
    // =================================================================

    public function test_an_unverified_recipient_gets_a_page_not_a_500_when_the_verification_route_is_absent(): void
    {
        $customer = $this->unpaidCustomer('buyer@example.test', verified: false);
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);

        // routes/auth.php registers `verification.send` only while registration is open.
        $without = new RouteCollection();
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getName() !== 'verification.send') {
                $without->add($route);
            }
        }
        app('router')->setRoutes($without);
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('verification.send'));

        config(['account.verify_account' => true]);
        $this->signInAs($customer['user']);

        $this->get(route('offers.show', ['token' => $created['token']]))
            ->assertOk()
            ->assertSee('data-role="offer-needs-verification"', false)
            ->assertSee('data-role="offer-verification-contact"', false);
    }
}
