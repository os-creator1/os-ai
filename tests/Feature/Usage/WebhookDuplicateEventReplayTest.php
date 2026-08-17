<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §13 step 4/§25 item 85 — a genuine Stripe redelivery
 * of an already-seen provider_event_id is absorbed as a no-op via
 * UNIQUE(provider, provider_event_id), never a second row.
 */
class WebhookDuplicateEventReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_redelivered_event_id_is_absorbed_as_a_no_op(): void
    {
        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());

        $rawBody = json_encode([
            'id' => 'evt_redelivered',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_x', 'metadata' => new \stdClass()]],
        ]);

        $first = $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody);
        $first->assertStatus(200);

        $second = $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'valid',
        ], $rawBody);
        $second->assertStatus(200);

        $this->assertSame(1, DB::table('payment_provider_events')->where('provider_event_id', 'evt_redelivered')->count());
    }
}
