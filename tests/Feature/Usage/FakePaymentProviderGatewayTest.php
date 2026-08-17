<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\ProviderCardDeclinedException;
use App\Exceptions\Usage\WebhookSignatureVerificationException;
use App\Library\Usage\FakePaymentProviderGateway;
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
}
