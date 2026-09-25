<?php

namespace Tests\Unit\Payments;

use App\Library\Payments\StripeApiConnectGateway;
use Tests\TestCase;

/**
 * Stripe rejects `POST /v1/accounts` with a present-but-empty email
 * ("Invalid email address: ") — it does not treat that the same as no
 * email at all. `createAccount(..., $email = null, ...)` used to send
 * `'email' => null`, which the SDK serializes to that same empty string
 * over the wire, so every Connect onboarding attempt with no email on
 * file failed closed with a generic provider error. These tests pin the
 * params-building behavior directly, without a network call.
 */
class StripeApiConnectGatewayTest extends TestCase
{
    public function test_a_null_email_is_omitted_entirely(): void
    {
        $params = StripeApiConnectGateway::accountCreateParams('US', null, 'business-uid-1');

        $this->assertArrayNotHasKey('email', $params);
    }

    public function test_a_real_email_is_included(): void
    {
        $params = StripeApiConnectGateway::accountCreateParams('US', 'owner@example.test', 'business-uid-1');

        $this->assertSame('owner@example.test', $params['email']);
    }

    public function test_the_locked_commercial_posture_is_unaffected_by_email_presence(): void
    {
        $withEmail = StripeApiConnectGateway::accountCreateParams('US', 'owner@example.test', 'business-uid-1');
        $withoutEmail = StripeApiConnectGateway::accountCreateParams('US', null, 'business-uid-1');

        foreach ([$withEmail, $withoutEmail] as $params) {
            $this->assertSame('US', $params['country']);
            $this->assertSame('account', $params['controller']['fees']['payer']);
            $this->assertSame('stripe', $params['controller']['losses']['payments']);
            $this->assertSame('stripe', $params['controller']['requirement_collection']);
            $this->assertSame('full', $params['controller']['stripe_dashboard']['type']);
            $this->assertTrue($params['capabilities']['card_payments']['requested']);
            $this->assertTrue($params['capabilities']['transfers']['requested']);
            $this->assertSame('business-uid-1', $params['metadata']['business_uid']);
        }
    }
}
