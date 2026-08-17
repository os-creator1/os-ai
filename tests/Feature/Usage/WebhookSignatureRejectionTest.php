<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §13 step 2/§25 item 84 — an invalid/missing
 * signature is rejected before any row is inserted or any mutation
 * occurs.
 */
class WebhookSignatureRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_signature_returns_400_and_inserts_no_row(): void
    {
        $gateway = new FakePaymentProviderGateway();
        $gateway->nextWebhookSignatureIsValid = false;
        app()->instance(PaymentProviderGateway::class, $gateway);

        $rawBody = json_encode(['id' => 'evt_bad_sig', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_x']]]);

        $response = $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => 'invalid',
        ], $rawBody);

        $response->assertStatus(400);
        $this->assertSame(0, DB::table('payment_provider_events')->count());
    }

    public function test_valid_signature_persists_and_returns_200(): void
    {
        $gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $gateway);

        $rawBody = json_encode([
            'id' => 'evt_good_sig',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_x', 'metadata' => new \stdClass()]],
        ]);

        $response = $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody);

        $response->assertStatus(200);
        $this->assertSame(1, DB::table('payment_provider_events')->where('provider_event_id', 'evt_good_sig')->count());
    }
}
