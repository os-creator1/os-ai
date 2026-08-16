<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\RealUsageAuthorizationGateway;
use App\Library\Usage\UsageCapacityDecision;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §11/§14 — for every one of the fifteen
 * PlatformFeature cases, check() returns authorized: true given the
 * post-M1-backfill classification state (is_metered = false
 * universally); evaluateCoarseCapacity()'s internal UsageCapacityDecision
 * never leaks past the gateway boundary.
 */
class RealUsageAuthorizationGatewayTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_every_platform_feature_is_authorized_at_m1(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $gateway = app(RealUsageAuthorizationGateway::class);

        foreach (PlatformFeature::cases() as $feature) {
            $result = $gateway->check($business, $feature);

            $this->assertTrue($result->authorized, "Expected {$feature->value} to be authorized at M1 (every feature is_metered=false).");
            $this->assertNull($result->reason);
        }
    }

    public function test_check_return_type_never_leaks_the_internal_capacity_decision(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $gateway = app(RealUsageAuthorizationGateway::class);
        $result = $gateway->check($business, PlatformFeature::Crm);

        $this->assertNotInstanceOf(UsageCapacityDecision::class, $result);
        $this->assertInstanceOf(\App\Library\Entitlement\UsageAuthorizationResult::class, $result);
    }
}
