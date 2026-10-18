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

    /**
     * Gives the Business's Workspace a confirmed plan assignment — what a paid
     * customer has by the time onboarding can activate their Draft Business
     * (release-risk closure item 3: an unpaid Workspace may not activate).
     */
    protected function assignPaidPlanFixture(Business $business): void
    {
        $workspace = Workspace::query()->findOrFail($business->workspace_id);

        $admin = User::create([
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'email' => 'platform-owner-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        app(\App\Library\Entitlement\EntitlementManager::class)->assignFirstPlan(
            $workspace,
            \App\Enums\Entitlement\WorkspacePlanTier::Growth,
            (int) $admin->id,
            'Onboarding fixture: paid plan.',
            true,
            0,
        );
    }
}
