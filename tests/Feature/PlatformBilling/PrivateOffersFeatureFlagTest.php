<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\PlatformPrivateOfferRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PlatformBilling\Concerns\CreatesPrivateOffers;
use Tests\TestCase;

/**
 * Private Offers sit behind a server-side switch that is OFF by default
 * (config/platform_billing.php). While it is off nothing NEW can be offered,
 * viewed, accepted or applied, yet an agreement already in force keeps every
 * contractual term.
 *
 * Also pins the webhook reading of an invoice event in the newer API shape.
 */
class PrivateOffersFeatureFlagTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPrivateOffers;

    private function boot(bool $privateOffers): void
    {
        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->sellableTier(WorkspacePlanTier::Growth, null, '100.00');
        config(['platform_billing.private_offers_enabled' => $privateOffers]);
    }

    public function test_private_offers_are_off_unless_the_platform_turns_them_on(): void
    {
        // Straight from config/platform_billing.php: no test helper has run.
        $this->assertFalse(config('platform_billing.private_offers_enabled'));
        $this->assertFalse(\App\Library\PlatformBilling\Offers\PrivateOffersFeature::enabled());
    }

    public function test_nothing_new_can_be_created_linked_viewed_accepted_or_applied_while_off(): void
    {
        $this->boot(true);
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test', 'trial_days' => 14]);
        $offer = $created['offer'];

        config(['platform_billing.private_offers_enabled' => false]);

        // The services refuse, whatever the caller.
        try {
            $this->makeOffer(['customer_email' => 'other@example.test']);
            $this->fail('An offer was created while Private Offers are off.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('offer', $e->errors());
        }

        try {
            $this->offers()->rotateLink($offer, $this->platformAdminId(), 'new link');
            $this->fail('A link was rotated while Private Offers are off.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('offer', $e->errors());
        }

        // The secret link no longer resolves, shows nothing and cannot be accepted.
        $this->assertNull($this->redemptions()->findByToken($created['token']));
        $view = $this->redemptions()->present($offer->refresh(), $customer['user']);
        $this->assertSame('unavailable', $view['state']);
        $this->assertNull($view['terms']);

        try {
            $this->acceptAs($customer['user'], $offer);
            $this->fail('An offer was accepted while Private Offers are off.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::OFFER_UNAVAILABLE, $e->reason);
        }

        $this->assertSame(0, PlatformPrivateOfferRedemption::query()->count());
        $this->assertCount(0, $this->stripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_the_routes_answer_404_while_off_and_a_revoke_still_works(): void
    {
        $this->boot(true);
        $created = $this->makeOffer(['customer_email' => 'buyer@example.test']);
        $uid = $created['offer']->uid;

        config(['platform_billing.private_offers_enabled' => false]);

        // A real link and a made-up one look the same.
        $this->get(route('offers.show', ['token' => $created['token']]))->assertNotFound();
        $this->get(route('offers.show', ['token' => Str::random(43)]))->assertNotFound();
        $this->get(route('offers.signup', ['token' => $created['token']]))->assertNotFound();

        $this->signInAsOwner();
        $this->get(route('admin.platform-billing.offers.index'))->assertNotFound();
        $this->get(route('admin.platform-billing.offers.show', [$uid]))->assertNotFound();
        $this->post(route('admin.platform-billing.offers.store'), ['kind' => 'new_subscription', 'tier' => 'growth', 'reason' => 'trying'])->assertNotFound();
        $this->post(route('admin.platform-billing.offers.new-link', [$uid]), ['reason' => 'rotate'])->assertNotFound();
        $this->post(route('admin.platform-billing.offers.apply', [$uid]), ['reason' => 'apply'])->assertNotFound();
        $this->assertSame(0, DB::table('platform_private_offers')->where('uid', '!=', $uid)->count());

        // Withdrawing an offer must always be possible.
        $this->post(route('admin.platform-billing.offers.revoke', [$uid]), ['reason' => 'No longer wanted.'])
            ->assertRedirect();
        $this->assertTrue($created['offer']->refresh()->isRevoked());
    }

    public function test_a_subscriber_on_an_agreement_keeps_every_term_when_the_switch_is_turned_off(): void
    {
        $this->boot(true);
        $customer = $this->unpaidCustomer('buyer@example.test');
        $created = $this->makeOffer([
            'customer_email' => 'buyer@example.test',
            'discount_type' => 'fixed', 'discount_value' => '30.00', 'discount_duration' => 'periods', 'discount_periods' => 2,
        ]);
        $session = $this->acceptAs($customer['user'], $created['offer']);
        $providerSubscriptionId = $this->payOnStripe($session->sessionId);
        $redemption = PlatformPrivateOfferRedemption::query()->latest('id')->firstOrFail();
        $this->redemptions()->handleReturn($redemption, $customer['user']);
        $manager = app(PlatformSubscriptionManager::class);

        // The platform switches the feature off after the customer has signed.
        config(['platform_billing.private_offers_enabled' => false]);

        $subscription = $customer['workspace']->refresh();
        $local = \App\Models\PlatformSubscription::query()->where('provider_subscription_id', $providerSubscriptionId)->firstOrFail();

        // Renewals, the promotion boundary, cancel and resume all still honour
        // the agreement: 70.00, 70.00, then the standard 100.00.
        $manager->requestCancellation($customer['workspace']);
        $manager->resumeSubscription($customer['workspace']);
        $this->assertSame('applied', $redemption->refresh()->schedule_state);

        foreach (range(1, 3) as $i) {
            $this->stripe->advancePeriod($providerSubscriptionId);
            $manager->applyProviderSubscription($local->refresh(), $providerSubscriptionId, 'evt_flag_off_' . $i, now());
        }

        $this->assertSame([7000, 7000, 10000, 10000], array_column($this->stripe->charges, 'amount'));
        $this->assertSame('100.00', (string) $local->refresh()->price_snapshot);
        $this->assertSame('redeemed', $redemption->refresh()->state);
    }

    public function test_an_invoice_event_in_the_newer_api_shape_still_finds_its_subscription(): void
    {
        $this->boot(false);
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $providerSubscriptionId = $fixture['provider_subscription_id'];

        // API versions from 2025-03-31 drop `invoice.subscription`; the id lives
        // under `parent.subscription_details`.
        $eventId = 'evt_newshape_' . Str::random(10);
        $body = json_encode([
            'id' => $eventId,
            'type' => 'invoice.paid',
            'created' => now()->getTimestamp(),
            'data' => ['object' => [
                'id' => 'in_fake_' . Str::random(8),
                'object' => 'invoice',
                'customer' => (string) $fixture['subscription']->provider_customer_id,
                'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => $providerSubscriptionId]],
            ]],
        ]);

        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();

        $row = DB::table('platform_subscription_events')->where('provider_event_id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertSame($providerSubscriptionId, $row->provider_subscription_id);
        $this->assertSame('processed', $row->state);
    }
}
