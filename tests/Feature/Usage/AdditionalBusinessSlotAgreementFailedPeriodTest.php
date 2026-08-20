<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\Currency;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M4 contract §13/§23 (Correction Round 1) — strengthened: asserts
 * exactly 3 total pre-lapse attempts (initial + 2 retries, never phrased
 * as "3 retries") produce payment_lapsed; no automatic 4th attempt ever
 * occurs; each pre-lapse retry is a genuine second provider-side call
 * (via the fake gateway's own confirmPaymentIntent() call-recording),
 * never merely a re-retrieval.
 */
class AdditionalBusinessSlotAgreementFailedPeriodTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProviderGateway $gateway;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    private function entitledWorkspaceWithLapsingCharge(): array
    {
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $this->currencyId, '0.5000', $admin->id, 'Fixture pricing.');
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', false, 0);

        $manager = app(UsageBillingCheckoutManager::class);
        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 2, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_initial', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_initial'];
        $manager->confirmSlotAgreementFromReturn($agreement);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($agreement->id);

        \Illuminate\Support\Facades\DB::table('additional_business_slot_agreements')->where('id', $agreement->id)->update([
            'next_renewal_at' => now()->subMinute(),
        ]);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        // Attempt 1 (the scheduled creation itself) leaves a real
        // PaymentIntent reference — a hard decline at creation never
        // persists one at all (ProviderCardDeclinedException carries no
        // id, matching M3's own established createOffSessionPaymentIntent
        // precedent), which would leave nothing for a retry to confirm
        // against. A subsequent async failure notification (a webhook
        // reporting the decline) is what genuinely produces the first
        // 'failed' transition while preserving that reference.
        $this->gateway->paymentIntentOutcomes['*'] = 'requires_action';
        $result = $manager->createScheduledRenewalCharge($agreement);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($result->renewalChargeId);

        $event = PaymentProviderEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_fake_'.uniqid(), 'event_type' => 'payment_intent.payment_failed',
            'provider_object_id' => $charge->provider_session_or_intent_reference, 'payload_encrypted' => '{}',
            'payload_hash' => hash('sha256', '{}'), 'state' => 'received', 'attempts' => 0, 'received_at' => now(),
        ]);
        $manager->markSlotRenewalChargeFailedFromWebhook($charge, 'generic_decline', $event);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);

        return [$workspace, $owner, $admin, $agreement, $charge];
    }

    public function test_exactly_three_total_pre_lapse_attempts_produce_lapse(): void
    {
        [, $owner, , $agreement, $charge] = $this->entitledWorkspaceWithLapsingCharge();
        $manager = app(UsageBillingCheckoutManager::class);

        $this->gateway->confirmPaymentIntentOutcomes['*'] = 'declined';

        // Attempt 2.
        $manager->retrySlotRenewalAsOwner($charge, $owner->id);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertFalse((bool) $agreement->payment_lapsed);

        // Attempt 3 — the final pre-lapse attempt.
        $manager->retrySlotRenewalAsOwner($charge, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);

        $this->assertTrue((bool) $agreement->payment_lapsed);
        $this->assertNotNull($agreement->payment_lapsed_at);
        $this->assertNull($agreement->next_renewal_at);

        // A 4th attempt is unreachable while payment_lapsed is false...
        // it is now true, so this is the explicit post-lapse recovery
        // path, exercised (and asserted genuine) below.
        $this->assertCount(2, $this->gateway->confirmPaymentIntentCalls);
    }

    public function test_each_retry_is_a_genuine_second_provider_call_not_a_mere_retrieval(): void
    {
        [, $owner, , , $charge] = $this->entitledWorkspaceWithLapsingCharge();
        $manager = app(UsageBillingCheckoutManager::class);

        $manager->retrySlotRenewalAsOwner($charge, $owner->id);

        $this->assertCount(1, $this->gateway->confirmPaymentIntentCalls);
        $this->assertSame($charge->provider_session_or_intent_reference, $this->gateway->confirmPaymentIntentCalls[0]['providerPaymentIntentId']);
    }

    public function test_no_automatic_fourth_attempt_ever_occurs(): void
    {
        [$workspace, $owner, , $agreement, $charge] = $this->entitledWorkspaceWithLapsingCharge();
        $manager = app(UsageBillingCheckoutManager::class);
        $this->gateway->confirmPaymentIntentOutcomes['*'] = 'declined';

        $manager->retrySlotRenewalAsOwner($charge, $owner->id);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);
        $manager->retrySlotRenewalAsOwner($charge, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertTrue((bool) $agreement->payment_lapsed);

        // InitiateSlotAgreementRenewal's own due-agreement query excludes
        // payment_lapsed = true; it is asserted to skip this agreement
        // entirely on its own next scheduled run.
        $due = app(AdditionalBusinessSlotAgreementRepository::class)->findDueForRenewal();
        $this->assertFalse($due->contains(fn ($a) => (int) $a->id === (int) $agreement->id));
    }

    public function test_ordinal_4_is_reachable_only_through_explicit_post_lapse_recovery(): void
    {
        [$workspace, $owner, , $agreement, $charge] = $this->entitledWorkspaceWithLapsingCharge();
        $manager = app(UsageBillingCheckoutManager::class);
        $this->gateway->confirmPaymentIntentOutcomes['*'] = 'declined';

        $manager->retrySlotRenewalAsOwner($charge, $owner->id);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);
        $manager->retrySlotRenewalAsOwner($charge, $owner->id);
        $charge = app(AdditionalBusinessSlotRenewalChargeRepository::class)->findById($charge->id);

        // Now lapsed — an explicit owner retry succeeds and clears lapse.
        $this->gateway->confirmPaymentIntentOutcomes['*'] = 'succeeded';
        $manager->retrySlotRenewalAsOwner($charge, $owner->id);

        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById((int) $agreement->id);
        $this->assertFalse((bool) $agreement->payment_lapsed);
        $this->assertNotNull($agreement->payment_lapsed_cleared_at);
        $this->assertNotNull($agreement->next_renewal_at);

        // The gateway's own confirmPaymentIntent() idempotency key is a
        // sha256 digest — independently recompute the exact expected
        // ordinal-4 key (M4 contract §23) and assert equality, since the
        // digest itself cannot be reversed to inspect the plaintext.
        $expectedOrdinal4Key = hash('sha256', $charge->local_idempotency_key.':attempt:4');
        $lastCall = end($this->gateway->confirmPaymentIntentCalls);
        $this->assertSame($expectedOrdinal4Key, $lastCall['idempotencyKey']);
    }
}
