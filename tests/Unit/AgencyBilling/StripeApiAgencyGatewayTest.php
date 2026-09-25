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
            $this->assertArrayNotHasKey('type', $params);
            $this->assertSame('account', $params['controller']['fees']['payer']);
            $this->assertSame('stripe', $params['controller']['losses']['payments']);
            $this->assertSame('stripe', $params['controller']['requirement_collection']);
            $this->assertSame('full', $params['controller']['stripe_dashboard']['type']);
            $this->assertSame('US', $params['country']);
            $this->assertSame('company', $params['business_type']);
            $this->assertTrue($params['capabilities']['card_payments']['requested']);
            $this->assertTrue($params['capabilities']['transfers']['requested']);
            $this->assertSame('agency-workspace-uid-1', $params['metadata']['app_agency_workspace_uid']);
        }
    }

    /**
     * Real Stripe test-mode acceptance found this class's own former
     * `type: express` account creation rejected by direct-charge Checkout
     * with "Creating direct charges with type=express or type=custom is not
     * supported for new platforms." — a genuine platform-policy
     * restriction, not an app bug. This correction mirrors Lane B's own
     * already-working `controller`-based shape
     * (StripeApiConnectGateway::accountCreateParams(),
     * tests/Feature/Payments/StripeConnectOnboardingTest.php::
     * test_no_application_fee_or_platform_intermediation_is_configured())
     * rather than switching to destination charges or another platform
     * pass-through — no `application_fee_amount`, no `transfer_data`, no
     * `on_behalf_of` anywhere in this class, so the platform is never a
     * party to the Agency's revenue.
     */
    public function test_no_application_fee_or_platform_intermediation_is_configured(): void
    {
        $gateway = (string) file_get_contents(app_path('Library/AgencyBilling/StripeApiAgencyGateway.php'));

        $this->assertStringContainsString("'fees' => ['payer' => 'account']", $gateway);
        $this->assertStringContainsString("'losses' => ['payments' => 'stripe']", $gateway);
        $this->assertStringContainsString("'requirement_collection' => 'stripe'", $gateway);
        $this->assertStringContainsString("'stripe_dashboard' => ['type' => 'full']", $gateway);

        $this->assertStringNotContainsString("'type' => 'standard'", $gateway);
        $this->assertStringNotContainsString("'type' => 'express'", $gateway);
        $this->assertStringNotContainsString("'type' => 'custom'", $gateway);

        // The class's own docblock names these three params in prose to
        // explain why they're absent, so match actual array-key usage
        // rather than a bare substring.
        $this->assertStringNotContainsString("'application_fee_amount' =>", $gateway);
        $this->assertStringNotContainsString("'transfer_data' =>", $gateway);
        $this->assertStringNotContainsString("'on_behalf_of' =>", $gateway);
    }
}
