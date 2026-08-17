<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\StripePaymentProviderGateway;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentIntentService;
use Stripe\Service\PaymentMethodService;
use Stripe\Service\SetupIntentService;
use Stripe\StripeClient;
use Stripe\Webhook;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §3.1/§25 item 75 — confirms the installed Stripe SDK
 * (v7.128.0) exposes every method StripePaymentProviderGateway calls,
 * via reflection/class-existence assertions only — zero live network call,
 * zero real Stripe API request.
 */
class StripePaymentProviderGatewayCompatibilityTest extends TestCase
{
    public function test_installed_sdk_exposes_every_method_the_gateway_calls(): void
    {
        $this->assertTrue(method_exists(CustomerService::class, 'retrieve'));
        $this->assertTrue(method_exists(CustomerService::class, 'create'));
        $this->assertTrue(method_exists(SetupIntentService::class, 'create'));
        $this->assertTrue(method_exists(SetupIntentService::class, 'retrieve'));
        $this->assertTrue(method_exists(PaymentMethodService::class, 'retrieve'));
        $this->assertTrue(method_exists(PaymentMethodService::class, 'detach'));
        $this->assertTrue(method_exists(PaymentIntentService::class, 'create'));
        $this->assertTrue(method_exists(Webhook::class, 'constructEvent'));
    }

    public function test_installed_sdk_exposes_every_exception_class_the_gateway_catches(): void
    {
        foreach ([
            \Stripe\Exception\AuthenticationException::class,
            \Stripe\Exception\RateLimitException::class,
            \Stripe\Exception\CardException::class,
            \Stripe\Exception\InvalidRequestException::class,
            \Stripe\Exception\ApiConnectionException::class,
            \Stripe\Exception\ApiErrorException::class,
            \Stripe\Exception\SignatureVerificationException::class,
        ] as $exceptionClass) {
            $this->assertTrue(class_exists($exceptionClass), "$exceptionClass must exist in the installed SDK.");
        }
    }

    public function test_installed_sdk_response_object_classes_exist(): void
    {
        $this->assertTrue(class_exists(Customer::class));
        $this->assertTrue(class_exists(SetupIntent::class));
        $this->assertTrue(class_exists(PaymentMethod::class));
        $this->assertTrue(class_exists(PaymentIntent::class));
        $this->assertTrue(class_exists(StripeClient::class));
    }

    public function test_gateway_fails_closed_on_missing_mode(): void
    {
        config(['services.stripe.mode' => null, 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook.secret' => 'whsec_fake', 'services.stripe.api_version' => '2024-06-20']);

        $this->expectException(\RuntimeException::class);
        new StripePaymentProviderGateway();
    }

    public function test_gateway_fails_closed_on_empty_secret(): void
    {
        config(['services.stripe.mode' => 'test', 'services.stripe.secret' => '', 'services.stripe.webhook.secret' => 'whsec_fake', 'services.stripe.api_version' => '2024-06-20']);

        $this->expectException(\RuntimeException::class);
        new StripePaymentProviderGateway();
    }

    public function test_gateway_fails_closed_on_empty_webhook_secret(): void
    {
        config(['services.stripe.mode' => 'test', 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook.secret' => null, 'services.stripe.api_version' => '2024-06-20']);

        $this->expectException(\RuntimeException::class);
        new StripePaymentProviderGateway();
    }

    public function test_gateway_fails_closed_on_empty_api_version(): void
    {
        config(['services.stripe.mode' => 'test', 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook.secret' => 'whsec_fake', 'services.stripe.api_version' => null]);

        $this->expectException(\RuntimeException::class);
        new StripePaymentProviderGateway();
    }

    public function test_gateway_fails_closed_on_live_key_with_test_mode(): void
    {
        config(['services.stripe.mode' => 'test', 'services.stripe.secret' => 'sk_live_fake', 'services.stripe.webhook.secret' => 'whsec_fake', 'services.stripe.api_version' => '2024-06-20']);

        $this->expectException(\RuntimeException::class);
        new StripePaymentProviderGateway();
    }

    public function test_gateway_constructs_successfully_with_valid_test_mode_config(): void
    {
        config(['services.stripe.mode' => 'test', 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook.secret' => 'whsec_fake', 'services.stripe.api_version' => '2024-06-20']);

        $gateway = new StripePaymentProviderGateway();
        $this->assertInstanceOf(StripePaymentProviderGateway::class, $gateway);
    }
}
