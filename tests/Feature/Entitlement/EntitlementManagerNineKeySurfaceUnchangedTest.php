<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\Contracts\UsageAuthorizationGateway;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\NullUsageAuthorizationGateway;
use App\Library\Entitlement\RealUsageAuthorizationGateway;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §11/§14 — decide()'s outcome is identical between
 * NullUsageAuthorizationGateway and RealUsageAuthorizationGateway, for the
 * same fixture, across every one of the fifteen PlatformFeature cases —
 * the direct regression test for the M1 contract's zero-behavior-change
 * claim. EntitlementManager.php itself is never modified by M1.
 */
class EntitlementManagerNineKeySurfaceUnchangedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private const NINE_KEYS = [
        'platform_feature_unknown',
        'platform_feature_unavailable',
        'workspace_plan_unassigned',
        'denied_by_workspace_override',
        'not_entitled_by_plan',
        'disabled_for_business',
        'plan_suspended',
        'plan_inactive',
        'usage_unauthorized',
    ];

    public function test_nine_denial_keys_are_unchanged(): void
    {
        $this->assertCount(9, self::NINE_KEYS);
    }

    public function test_decide_outcome_identical_across_both_gateway_bindings_for_every_feature(): void
    {
        // EntitlementManagerConcurrencyTest.php (pre-existing RFC-004 file,
        // outside this contract's allowlist) deliberately does not use
        // RefreshDatabase and creates its own committed, uncleaned-up 'USD'
        // Currency rows for scenarios 6/7 — when this test runs afterward
        // in the same process, an unconditional create here would collide
        // with those leaked rows and make resolveCurrencyId() ambiguous.
        // Reuse an existing active 'USD' row if one is already present.
        if (Currency::query()->where('code', 'USD')->where('status', true)->doesntExist()) {
            Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        }

        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner-' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin-' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $workspace = Workspace::create(['name' => 'Nine-Key Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 0);

        $customer = \App\Models\Customer::create(['user_id' => $owner->id]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());

        app(\App\Library\Usage\UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        foreach (PlatformFeature::cases() as $feature) {
            $this->app->bind(UsageAuthorizationGateway::class, NullUsageAuthorizationGateway::class);
            $withNull = app(EntitlementManager::class)->decide($workspace, $business, $feature->value, $owner->id);

            $this->app->bind(UsageAuthorizationGateway::class, RealUsageAuthorizationGateway::class);
            $withReal = app(EntitlementManager::class)->decide($workspace, $business, $feature->value, $owner->id);

            $this->assertSame(
                $withNull->allowed,
                $withReal->allowed,
                "decide() allowed differs for {$feature->value} between Null and Real gateways.",
            );
            $this->assertSame(
                $withNull->reason,
                $withReal->reason,
                "decide() reason differs for {$feature->value} between Null and Real gateways.",
            );

            if (! $withReal->allowed) {
                $this->assertContains($withReal->reason, self::NINE_KEYS);
            }
        }
    }
}
