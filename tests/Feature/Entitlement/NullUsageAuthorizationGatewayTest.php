<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\Contracts\UsageAuthorizationGateway;
use App\Library\Entitlement\NullUsageAuthorizationGateway;
use App\Library\Entitlement\RealUsageAuthorizationGateway;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RFC-005 M1 §11 — NullUsageAuthorizationGateway remains a valid,
 * intentionally-permissive UsageAuthorizationGateway implementation even
 * though it is no longer the active container binding
 * (RealUsageAuthorizationGateway is, per the M1 contract's own authorized
 * §11 binding switch). This file keeps the two concerns distinct: the
 * active container binding is asserted directly against
 * RealUsageAuthorizationGateway, while Null's own permissive behavior is
 * proven by instantiating it directly, independent of what the container
 * currently binds.
 */
class NullUsageAuthorizationGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_to_the_real_implementation(): void
    {
        $this->assertInstanceOf(RealUsageAuthorizationGateway::class, app(UsageAuthorizationGateway::class));
    }

    public function test_null_implementation_still_always_authorizes_when_instantiated_directly(): void
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'B', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);

        foreach (PlatformFeature::cases() as $feature) {
            $result = app(NullUsageAuthorizationGateway::class)->check($business, $feature);
            $this->assertTrue($result->authorized);
            $this->assertNull($result->reason);
        }
    }
}
