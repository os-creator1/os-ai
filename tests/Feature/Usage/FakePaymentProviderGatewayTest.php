<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\ProviderCardDeclinedException;
use App\Exceptions\Usage\WebhookSignatureVerificationException;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentIntentResult;
use App\Library\Usage\PaymentMethodResult;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §25 item 76 — proves FakePaymentProviderGateway is a
 * faithful, deterministic stand-in for the real gateway's own return-shape
 * contract, never a live network call.
 */
class FakePaymentProviderGatewayTest extends TestCase
{
    public function test_create_or_retrieve_customer_is_deterministic(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $created = $gateway->createOrRetrieveCustomer(null, 'idem-1');
        $this->assertTrue($created->wasCreated);
        $this->assertStringStartsWith('cus_fake_', $created->providerCustomerId);

        $retrieved = $gateway->createOrRetrieveCustomer('cus_existing', 'idem-2');
        $this->assertFalse($retrieved->wasCreated);
        $this->assertSame('cus_existing', $retrieved->providerCustomerId);
    }

    public function test_setup_intent_creation_and_retrieval_pair_deterministically(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $created = $gateway->createSetupIntent('cus_x', 'idem-3');
        $this->assertStringStartsWith('seti_fake_', $created->providerSetupIntentId);
        $this->assertSame('requires_payment_method', $created->status);

        $retrieved = $gateway->retrieveSetupIntent($created->providerSetupIntentId);
        $this->assertSame('succeeded', $retrieved->status);
        $this->assertNotNull($retrieved->providerPaymentMethodId);
        $this->assertStringStartsWith('pm_fake_', $retrieved->providerPaymentMethodId);
    }

    public function test_registered_payment_method_is_returned_verbatim(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $result = new PaymentMethodResult('pm_registered', 'cus_x', 'card', 'mastercard', '4444', 6, 2028);
        $gateway->registerPaymentMethod($result);

        $this->assertSame($result, $gateway->retrievePaymentMethod('pm_registered'));
    }

    public function test_off_session_payment_intent_outcomes_are_configurable(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $succeeded = $gateway->createOffSessionPaymentIntent('cus_x', 'pm_x', 1000, 'USD', 'idem-succeed', []);
        $this->assertSame('succeeded', $succeeded->status);

        $gateway->paymentIntentOutcomes['idem-requires-action'] = 'requires_action';
        $requiresAction = $gateway->createOffSessionPaymentIntent('cus_x', 'pm_x', 1000, 'USD', 'idem-requires-action', []);
        $this->assertSame('requires_action', $requiresAction->status);
        $this->assertNotNull($requiresAction->clientSecret);

        $gateway->paymentIntentOutcomes['idem-decline'] = 'declined';
        $this->expectException(ProviderCardDeclinedException::class);
        $gateway->createOffSessionPaymentIntent('cus_x', 'pm_x', 1000, 'USD', 'idem-decline', []);
    }

    /**
     * M4 contract §21 (Correction Round 2 §B) — reproduces the real
     * gateway's own behavior of carrying the declined PaymentIntent's real
     * id on the exception, and the configurable escape hatch that lets a
     * test force the fail-closed null-evidence case instead.
     */
    public function test_declined_off_session_payment_intent_carries_the_generated_id_by_default(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $gateway->paymentIntentOutcomes['idem-decline'] = 'declined';

        try {
            $gateway->createOffSessionPaymentIntent('cus_x', 'pm_x', 1000, 'USD', 'idem-decline', []);
            $this->fail('Expected ProviderCardDeclinedException.');
        } catch (ProviderCardDeclinedException $e) {
            $this->assertNotNull($e->providerPaymentIntentId);
            $this->assertStringStartsWith('pi_fake_', $e->providerPaymentIntentId);
        }
    }

    public function test_declined_off_session_payment_intent_can_be_forced_to_omit_the_id(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $gateway->paymentIntentOutcomes['idem-decline'] = 'declined';
        $gateway->declineOmitsPaymentIntentId['idem-decline'] = true;

        try {
            $gateway->createOffSessionPaymentIntent('cus_x', 'pm_x', 1000, 'USD', 'idem-decline', []);
            $this->fail('Expected ProviderCardDeclinedException.');
        } catch (ProviderCardDeclinedException $e) {
            $this->assertNull($e->providerPaymentIntentId);
        }
    }

    public function test_webhook_signature_verification_is_deterministic(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $rawBody = json_encode([
            'id' => 'evt_fake_1',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_x', 'metadata' => ['app_subject_kind' => 'funding_attempt'], 'amount' => 1000, 'currency' => 'usd', 'customer' => 'cus_x']],
        ]);

        $result = $gateway->verifyWebhookSignature($rawBody, 'sig', 'whsec_fake');
        $this->assertSame('evt_fake_1', $result->providerEventId);
        $this->assertSame('payment_intent.succeeded', $result->eventType);
        $this->assertSame('pi_x', $result->providerObjectId);
        $this->assertSame(['app_subject_kind' => 'funding_attempt'], $result->metadata);
        $this->assertSame(1000, $result->amountMinorUnits);
        $this->assertSame('USD', $result->currencyCode);

        $gateway->nextWebhookSignatureIsValid = false;
        $this->expectException(WebhookSignatureVerificationException::class);
        $gateway->verifyWebhookSignature($rawBody, 'sig', 'whsec_fake');
    }

    public function test_detach_payment_method_removes_it_from_the_fake_registry(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $result = new PaymentMethodResult('pm_to_detach', 'cus_x', 'card', 'visa', '4242', 12, 2030);
        $gateway->registerPaymentMethod($result);

        $gateway->detachPaymentMethod('pm_to_detach');

        // Retrieving an unregistered id falls back to the deterministic
        // default fixture rather than throwing — proving detach cleared it.
        $fallback = $gateway->retrievePaymentMethod('pm_to_detach');
        $this->assertNotSame($result, $fallback);
    }

    /**
     * M4 contract §15a — a freshly created Session is always open/unpaid
     * with a redirect url and no PaymentMethod/PaymentIntent reference
     * yet; retrieveCheckoutSession() defaults to complete/paid with a
     * null redirectUrl once configured (or by its own default), never
     * carrying a redirect for an already-completed Session.
     */
    public function test_checkout_session_create_then_retrieve_defaults_to_a_complete_paid_session_with_no_redirect_url(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $created = $gateway->createCheckoutSession('cus_x', 2000, 'USD', 'Additional Business slots', 'https://success.test', 'https://cancel.test', 'idem-checkout-1', []);
        $this->assertSame('open', $created->status);
        $this->assertSame('unpaid', $created->paymentStatus);
        $this->assertNotNull($created->redirectUrl);
        $this->assertNull($created->providerPaymentMethodId);
        $this->assertNull($created->providerPaymentIntentId);

        $retrieved = $gateway->retrieveCheckoutSession($created->providerCheckoutSessionId);
        $this->assertSame('complete', $retrieved->status);
        $this->assertSame('paid', $retrieved->paymentStatus);
        $this->assertNull($retrieved->redirectUrl);
        $this->assertNotNull($retrieved->providerPaymentMethodId);
        $this->assertNotNull($retrieved->providerPaymentIntentId);
        $this->assertSame(2000, $retrieved->amountMinorUnits);
        $this->assertSame('USD', $retrieved->currencyCode);
        $this->assertSame('cus_x', $retrieved->providerCustomerId);
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §8.B — proves
     * createCheckoutSessionCalls records the exact setupFutureUsageOffSession
     * value each caller actually passed, mirroring confirmPaymentIntentCalls'
     * own existing "prove a specific call happened with specific
     * parameters" purpose.
     */
    public function test_create_checkout_session_records_the_setup_future_usage_flag_per_call(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $gateway->createCheckoutSession('cus_x', 2000, 'USD', 'Additional Business slots', 'https://success.test', 'https://cancel.test', 'idem-true', [], true);
        $gateway->createCheckoutSession('cus_y', 1000, 'USD', 'Wallet top-up', 'https://success.test', 'https://cancel.test', 'idem-false', [], false);
        $gateway->createCheckoutSession('cus_z', 500, 'USD', 'Wallet top-up', 'https://success.test', 'https://cancel.test', 'idem-default', []);

        $this->assertCount(3, $gateway->createCheckoutSessionCalls);
        $this->assertTrue($gateway->createCheckoutSessionCalls[0]['setupFutureUsageOffSession']);
        $this->assertFalse($gateway->createCheckoutSessionCalls[1]['setupFutureUsageOffSession']);
        $this->assertFalse($gateway->createCheckoutSessionCalls[2]['setupFutureUsageOffSession'], 'The default must be false when the caller omits the argument.');
    }

    public function test_checkout_session_outcome_is_configurable_per_idempotency_key(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $created = $gateway->createCheckoutSession('cus_x', 2000, 'USD', 'Additional Business slots', 'https://success.test', 'https://cancel.test', 'idem-checkout-2', []);

        $gateway->checkoutSessionOutcomes['idem-checkout-2'] = ['status' => 'expired', 'paymentStatus' => 'unpaid'];

        $retrieved = $gateway->retrieveCheckoutSession($created->providerCheckoutSessionId);
        $this->assertSame('expired', $retrieved->status);
        $this->assertNull($retrieved->providerPaymentMethodId);
        $this->assertNull($retrieved->providerPaymentIntentId);
    }

    /**
     * M4 contract §3 item 7k — confirmPaymentIntent() records every call
     * it receives, so a test can assert a genuine second provider-side
     * attempt actually occurred, never merely that the existing
     * PaymentIntent was re-retrieved.
     */
    public function test_confirm_payment_intent_is_configurable_and_records_every_call(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $succeeded = $gateway->confirmPaymentIntent('pi_fake_x', 'pm_fake_y', 'idem-confirm-succeed');
        $this->assertSame('succeeded', $succeeded->status);

        $gateway->confirmPaymentIntentOutcomes['idem-confirm-decline'] = 'declined';
        $this->expectException(ProviderCardDeclinedException::class);

        try {
            $gateway->confirmPaymentIntent('pi_fake_x', 'pm_fake_z', 'idem-confirm-decline');
        } finally {
            $this->assertCount(2, $gateway->confirmPaymentIntentCalls);
            $this->assertSame('pi_fake_x', $gateway->confirmPaymentIntentCalls[0]['providerPaymentIntentId']);
            $this->assertSame('pm_fake_y', $gateway->confirmPaymentIntentCalls[0]['providerPaymentMethodId']);
            $this->assertSame('idem-confirm-succeed', $gateway->confirmPaymentIntentCalls[0]['idempotencyKey']);
        }
    }

    /**
     * Receipt Boundary Correction Contract §8 — mirrors
     * registerCheckoutSessionResult()'s own established pattern: an
     * explicit registration is returned verbatim, including a
     * deliberately-null receipt field simulating missing evidence.
     */
    public function test_registered_payment_intent_result_is_returned_verbatim(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $result = new PaymentIntentResult('pi_registered', 'succeeded', null, 5000, 'USD', null, null);
        $gateway->registerPaymentIntentResult($result);

        $this->assertSame($result, $gateway->retrievePaymentIntent('pi_registered'));
    }

    /**
     * Receipt Boundary Correction Contract §8/§J — the Fake's own default
     * fallback (no explicit registration) always carries non-null
     * deterministic receipt evidence, so existing tests relying on the
     * default need zero fixture changes.
     */
    public function test_unregistered_payment_intent_retrieval_returns_stable_deterministic_receipt_fields(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $result = $gateway->retrievePaymentIntent('pi_unregistered');

        $this->assertNotNull($result->receiptUrl);
        $this->assertNotNull($result->receiptChargeId);
        $this->assertStringStartsWith('ch_fake_', $result->receiptChargeId);
    }

    /**
     * Receipt Boundary Correction Contract §J — deterministic, never
     * random: the same provider object id always yields the same
     * receiptChargeId/receiptUrl across repeated calls.
     */
    public function test_repeated_unregistered_payment_intent_retrieval_returns_the_same_receipt_identity(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $first = $gateway->retrievePaymentIntent('pi_repeated');
        $second = $gateway->retrievePaymentIntent('pi_repeated');

        $this->assertSame($first->receiptChargeId, $second->receiptChargeId);
        $this->assertSame($first->receiptUrl, $second->receiptUrl);
    }

    /**
     * Receipt Boundary Correction Contract §8 — an explicitly registered
     * CheckoutSessionResult's own receipt fields are returned verbatim,
     * never overridden by the Fake's own deterministic default.
     */
    public function test_checkout_session_receipt_fields_are_returned_verbatim_when_explicitly_registered(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $result = new CheckoutSessionResult('cs_registered', 'complete', 'paid', null, 2000, 'USD', 'cus_x', 'pi_x', 'pm_x', 'https://example.test/receipt', 'ch_explicit');
        $gateway->registerCheckoutSessionResult($result);

        $retrieved = $gateway->retrieveCheckoutSession('cs_registered');
        $this->assertSame('https://example.test/receipt', $retrieved->receiptUrl);
        $this->assertSame('ch_explicit', $retrieved->receiptChargeId);
    }

    /**
     * Receipt Boundary Correction Contract §I — removes all test-author
     * discretion for proving "no provider call occurred": the Fake itself
     * records every requested provider object id.
     */
    public function test_retrieve_payment_intent_calls_are_recorded(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $gateway->retrievePaymentIntent('pi_call_recorded_1');
        $gateway->retrievePaymentIntent('pi_call_recorded_2');

        $this->assertSame(['pi_call_recorded_1', 'pi_call_recorded_2'], $gateway->retrievePaymentIntentCalls);
    }

    public function test_retrieve_checkout_session_calls_are_recorded(): void
    {
        $gateway = new FakePaymentProviderGateway();

        $gateway->retrieveCheckoutSession('cs_call_recorded_1');

        $this->assertSame(['cs_call_recorded_1'], $gateway->retrieveCheckoutSessionCalls);
    }
}
