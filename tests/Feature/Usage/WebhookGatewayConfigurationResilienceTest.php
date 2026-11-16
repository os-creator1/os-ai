<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\StripePaymentProviderGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regression: the usage-billing webhook returned HTTP 500 whenever the
 * outbound-API configuration (mode / secret-key prefix / api_version) was
 * incomplete, because signature verification ran the full provider
 * configuration check. Verification now needs only the webhook secret.
 */
class WebhookGatewayConfigurationResilienceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_regression_secret';

    private function useRealGatewayWithBrokenApiConfig(?string $webhookSecret = self::SECRET): void
    {
        config([
            'services.stripe.webhook.secret' => $webhookSecret,
            'services.stripe.api_version' => null,
            'services.stripe.secret' => '',
            'services.stripe.mode' => null,
        ]);
        app()->instance(PaymentProviderGateway::class, new StripePaymentProviderGateway());
    }

    private function post(string $body, ?string $signature)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_Stripe-Signature'] = $signature;
        }

        return $this->call('POST', route('webhooks.stripe.usage-billing'), [], [], [], $server, $body);
    }

    private function sign(string $body): string
    {
        $t = time();

        return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, self::SECRET);
    }

    private function body(string $id = 'evt_cfg_1'): string
    {
        return json_encode(['id' => $id, 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_cfg', 'object' => 'payment_intent', 'metadata' => new \stdClass()]]]);
    }

    public function test_unsigned_request_is_400_even_with_incomplete_api_config(): void
    {
        $this->useRealGatewayWithBrokenApiConfig();
        Queue::fake();

        $this->post($this->body(), null)->assertStatus(400);
        $this->post($this->body(), 't=1,v1=bad')->assertStatus(400);
        $this->assertSame(0, DB::table('payment_provider_events')->count());
        Queue::assertNothingPushed();
    }

    public function test_missing_webhook_secret_fails_closed_with_400_not_500(): void
    {
        $this->useRealGatewayWithBrokenApiConfig('');
        Queue::fake();

        $this->post($this->body(), $this->sign($this->body()))->assertStatus(400);
        $this->assertSame(0, DB::table('payment_provider_events')->count());
    }

    public function test_signed_event_is_stored_once_and_duplicate_is_acknowledged_without_reprocessing(): void
    {
        $this->useRealGatewayWithBrokenApiConfig();
        Queue::fake();
        $body = $this->body('evt_cfg_dup');

        $this->post($body, $this->sign($body))->assertStatus(200);
        $this->post($body, $this->sign($body))->assertStatus(200);

        $this->assertSame(1, DB::table('payment_provider_events')->where('provider_event_id', 'evt_cfg_dup')->count());
        Queue::assertPushed(\App\Jobs\Usage\ProcessPaymentProviderEvent::class, 1);
    }
}
