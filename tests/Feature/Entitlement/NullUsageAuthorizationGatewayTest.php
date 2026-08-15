<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\Contracts\UsageAuthorizationGateway;
use App\Library\Entitlement\NullUsageAuthorizationGateway;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NullUsageAuthorizationGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_container_resolves_to_the_null_implementation(): void
    {
        $this->assertInstanceOf(NullUsageAuthorizationGateway::class, app(UsageAuthorizationGateway::class));
    }

    public function test_always_authorizes_for_an_arbitrary_business_and_feature(): void
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
