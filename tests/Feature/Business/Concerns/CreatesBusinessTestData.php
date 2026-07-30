<?php

namespace Tests\Feature\Business\Concerns;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;

trait CreatesBusinessTestData
{
    protected function createCustomer(): Customer
    {
        $user = User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'customer' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        return Customer::create([
            'user_id' => $user->id,
        ]);
    }

    protected function businessAttributes(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Snap Booth Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $overrides);
    }

    /**
     * The ordinary Business-fixture choke point: creates a fresh Workspace
     * owned by the Customer and persists the Business through
     * BusinessRepository::createForCustomerInWorkspace() — the sole
     * supported creation method post-Slice-3B (RFC-003 §10.6 step 2). A new
     * Workspace per call is deliberate; nothing here requires two Businesses
     * created via this helper to share one Workspace (RFC-003 §7.4).
     */
    protected function createBusinessWithWorkspace(Customer $customer, array $attributes): Business
    {
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'owner_user_id' => $customer->user_id,
            'is_active' => true,
        ]);

        return app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $attributes);
    }
}
