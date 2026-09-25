<?php

namespace Tests\Unit\AgencyBilling;

use App\Library\AgencyBilling\StripeApiAgencyGateway;
use Tests\TestCase;

/**
 * Same defect, same fix, as StripeApiConnectGatewayTest — Stripe rejects a
 * present-but-empty email on `POST /v1/accounts`, and `'email' => null`
 * serializes to exactly that over the wire. See that test's docblock.
 */
class StripeApiAgencyGatewayTest extends TestCase
{
    public function test_a_null_email_is_omitted_entirely(): void
    {
        $params = StripeApiAgencyGateway::accountCreateParams('US', null, 'agency-workspace-uid-1');

        $this->assertArrayNotHasKey('email', $params);
    }

    public function test_a_real_email_is_included(): void
    {
        $params = StripeApiAgencyGateway::accountCreateParams('US', 'agency@example.test', 'agency-workspace-uid-1');

        $this->assertSame('agency@example.test', $params['email']);
    }

    public function test_the_locked_commercial_posture_is_unaffected_by_email_presence(): void
    {
        $withEmail = StripeApiAgencyGateway::accountCreateParams('US', 'agency@example.test', 'agency-workspace-uid-1');
        $withoutEmail = StripeApiAgencyGateway::accountCreateParams('US', null, 'agency-workspace-uid-1');

        foreach ([$withEmail, $withoutEmail] as $params) {
            $this->assertSame('express', $params['type']);
            $this->assertSame('US', $params['country']);
            $this->assertSame('company', $params['business_type']);
            $this->assertTrue($params['capabilities']['card_payments']['requested']);
            $this->assertTrue($params['capabilities']['transfers']['requested']);
            $this->assertSame('agency-workspace-uid-1', $params['metadata']['app_agency_workspace_uid']);
        }
    }
}
