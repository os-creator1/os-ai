<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\StripePaymentProviderGateway;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Service\Checkout\SessionService;
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

        // M4 contract §15a — the Checkout Session and PaymentIntent
        // confirmation seams the gateway extension calls.
        $this->assertTrue(method_exists(SessionService::class, 'create'));
        $this->assertTrue(method_exists(SessionService::class, 'retrieve'));
        $this->assertTrue(method_exists(PaymentIntentService::class, 'confirm'));
    }

    public function test_installed_sdk_checkout_session_response_object_class_exists(): void
    {
        $this->assertTrue(class_exists(Session::class));
    }

    /**
     * M4 contract §15a (Correction Round 1) — the exact, corrected
     * Checkout Session request shape: mode: 'payment' with exactly one
     * line_items entry built from inline price_data, never a separately-
     * created Stripe Price/Product. Verified at the source level (this
     * file's own established zero-live-network-call discipline) — a
     * Checkout Session create request missing the required line_items
     * structure would fail this assertion.
     */
    public function test_checkout_session_create_request_includes_the_required_line_items_structure(): void
    {
        $source = file_get_contents(app_path('Library/Usage/StripePaymentProviderGateway.php'));

        $this->assertStringContainsString("'mode' => 'payment'", $source);
        $this->assertStringContainsString("'line_items'", $source);
        $this->assertStringContainsString("'price_data'", $source);
        $this->assertStringContainsString("'unit_amount' => \$amountMinorUnits", $source);
        $this->assertStringContainsString("'quantity' => 1", $source);
        $this->assertStringContainsString("'setup_future_usage' => 'off_session'", $source);
        // Never a separately-created Stripe Price/Product id.
        $this->assertStringNotContainsString("'price' =>", $source);
    }

    /**
     * M4 contract §15a — createCheckoutSession() fails closed when the
     * newly-created Session unexpectedly carries no redirect url;
     * retrieveCheckoutSession() resolves the actual PaymentMethod via
     * PaymentIntent expansion on a complete Session, never assuming the
     * locally-saved default.
     */
    public function test_gateway_source_fails_closed_on_a_missing_redirect_url_and_resolves_payment_method_via_expansion(): void
    {
        $source = file_get_contents(app_path('Library/Usage/StripePaymentProviderGateway.php'));

        $this->assertStringContainsString('blank($session->url)', $source);
        $this->assertStringContainsString('ProviderInvalidRequestException', $source);
        $this->assertStringContainsString("'expand' => ['payment_intent.payment_method']", $source);
    }

    public function test_confirm_payment_intent_request_shape_includes_payment_method_and_idempotency_key(): void
    {
        $source = file_get_contents(app_path('Library/Usage/StripePaymentProviderGateway.php'));

        $this->assertStringContainsString("'payment_method' => \$providerPaymentMethodId", $source);
        $this->assertStringContainsString("'idempotency_key' => \$idempotencyKey", $source);
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
