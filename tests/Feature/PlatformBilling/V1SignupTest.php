<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\V1SignupManager;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §7/§15 — the canonical V1 signup: a brand-new
 * customer reaches a correctly provisioned Workspace / Business / Primary
 * Location with the correct plan authority behind it.
 */
class V1SignupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
    }

    private function signup(): V1SignupManager
    {
        return app(V1SignupManager::class);
    }

    /** @return array<string, mixed> */
    private function draft(string $name = 'Harbor Lane Studios'): array
    {
        return [
            'business_name' => $name,
            'industry' => 'photo_booth_service',
            'timezone' => 'UTC',
            'country_code' => 'US',
        ];
    }

    /** @return array<string, array{0: WorkspacePlanTier}> */
    public static function tiers(): array
    {
        return [
            'core' => [WorkspacePlanTier::Core],
            'growth' => [WorkspacePlanTier::Growth],
            'agency' => [WorkspacePlanTier::Agency],
        ];
    }

    #[DataProvider('tiers')]
    public function test_a_brand_new_customer_can_sign_up_on_each_tier(WorkspacePlanTier $tier): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier($tier);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription(
            $customer, $this->draft(), $catalog,
            'https://app.test/done', 'https://app.test/cancel',
        );

        // The customer completes checkout on Stripe's hosted page.
        $this->stripe->completeCheckout($session->sessionId);
        $workspace = $this->signup()->completeSignup($session->sessionId);

        $this->assertNotNull($workspace);

        // Workspace + exactly one Business + exactly one Primary Location.
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('Harbor Lane Studios', (string) $business->name);
        $locations = BusinessLocation::query()->where('business_id', $business->id)->get();
        $this->assertCount(1, $locations);

        // The canonical V1 plan authority, at the tier the customer chose.
        $assignment = WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertFalse((bool) $assignment->is_complimentary);
        $this->assertSame($tier, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($workspace)->tier);

        // ...and the account is usable.
        $this->assertSame(CustomerAccountAccessState::Usable,
            app(CustomerAccountAccessResolver::class)->resolve($workspace)->state);
    }

    /**
     * Manual acceptance defect 1 (P0) — createForCustomerInWorkspace() always
     * provisions a self-signup Business Draft, and before this fix nothing
     * anywhere ever moved it to Active: activateClientBusiness() exists only
     * for the unrelated Agency-invited-client path. Every V1 self-signup
     * account was permanently stuck Draft even after paying.
     */
    public function test_completing_signup_activates_the_newly_provisioned_business(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');

        $workspace = Workspace::query()->where('owner_user_id', $customer->user_id)->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(BusinessStatus::Draft, $business->status, 'Provisioning creates the Business Draft, before any payment is confirmed.');

        $this->stripe->completeCheckout($session->sessionId);
        $this->signup()->completeSignup($session->sessionId);

        $business->refresh();
        $this->assertSame(BusinessStatus::Active, $business->status);
        $this->assertNotNull($business->activated_at);
    }

    /**
     * ChatGPT review correction — workspace_plan_assignments is the V1
     * entitlement/access authority (RFC-004 §14), so a provider-confirmed
     * subscription is reason to WRITE that authority, never reason to expose
     * an Active Business on the strength of provider confirmation alone.
     * activateFromConfirmedSubscription() must attempt the assignment BEFORE
     * activating the Business, so an unexpected assignment failure leaves
     * the account exactly as inconsistent as it already was (Draft, no
     * assignment) rather than Active with no canonical assignment behind it.
     *
     * WorkspacePlanAssignmentRepository is the actual persistence seam both
     * V1SignupManager's own pre-check and EntitlementManager::assignFirstPlan()'s
     * write share (PlatformSubscriptionManager and EntitlementManager are
     * both `final` and cannot be Mockery-doubled while still satisfying their
     * constructor type hints, so the repository contract underneath them is
     * the correct place to inject the failure).
     */
    public function test_an_unexpected_assignment_failure_leaves_the_business_draft_and_propagates(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');
        $this->stripe->completeCheckout($session->sessionId);

        $workspace = Workspace::query()->where('owner_user_id', $customer->user_id)->sole();
        $business = Business::query()->where('workspace_id', $workspace->id)->sole();

        $this->mock(WorkspacePlanAssignmentRepository::class, function ($mock): void {
            $mock->shouldReceive('findByWorkspaceId')->andReturn(null);
            $mock->shouldReceive('create')
                ->once()
                ->andThrow(new \RuntimeException('simulated unexpected assignment failure'));
        });

        $thrown = null;

        try {
            $this->signup()->completeSignup($session->sessionId);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'An unexpected assignment failure must propagate, not be swallowed.');
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(), 'No fake plan assignment appears.');

        $business->refresh();
        $this->assertSame(BusinessStatus::Draft, $business->status, 'The Business must remain Draft when the canonical assignment did not persist.');
        $this->assertNull($business->activated_at);
    }

    public function test_an_abandoned_checkout_leaves_no_paid_state(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        // Checkout opens; the customer never finishes it.
        $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');

        $this->assertSame(1, Workspace::query()->count(), 'The account exists…');
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            '…but §7 — no provider result, no plan assignment.');
        $this->assertSame(PlatformSubscriptionStatus::Pending, PlatformSubscription::query()->sole()->status);
    }

    public function test_re_entering_signup_after_abandoning_does_not_create_a_second_workspace(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');
        $second = $this->signup()->startSubscription($customer->fresh(), $this->draft(), $catalog, 'https://a', 'https://b');

        $this->assertSame(1, Workspace::query()->count());
        $this->assertSame(1, Business::query()->count());
        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertNotNull($second->sessionId);
    }

    public function test_completing_signup_twice_assigns_exactly_one_plan(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');
        $this->stripe->completeCheckout($session->sessionId);

        // The browser returns AND the webhook arrives — both call the same
        // idempotent completion.
        $this->signup()->completeSignup($session->sessionId);
        $this->signup()->completeSignup($session->sessionId);

        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
    }

    public function test_signup_writes_nothing_into_the_legacy_subscription_world(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');
        $this->stripe->completeCheckout($session->sessionId);
        $this->signup()->completeSignup($session->sessionId);

        foreach (['subscriptions', 'subscription_transactions', 'invoices'] as $table) {
            $this->assertSame(0, DB::table($table)->count(),
                "§4.1 — V1 signup must not create a legacy [{$table}] row.");
        }
    }

    public function test_a_trial_signup_stores_the_payment_method_bearing_subscription_and_the_trial_end(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 9);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');
        $this->stripe->completeCheckout($session->sessionId);
        $workspace = $this->signup()->completeSignup($session->sessionId);

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame(9, (int) $subscription->trial_days_snapshot);
        $this->assertNotNull($subscription->provider_customer_id, 'A payment method was collected at checkout.');
        $this->assertNotNull($subscription->provider_subscription_id);

        $this->assertNotNull(WorkspacePlanAssignment::query()
            ->where('workspace_id', $workspace->id)->sole()->trial_ends_at);

        // The trial length actually sent to the provider is the configured one.
        $this->assertSame(9, $this->stripe->callsOf('createSubscriptionCheckout')[0]['args']['trial_days']);
    }

    public function test_signup_requires_no_a2p_google_calendar_or_business_stripe_connect(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $customer = $this->createCustomer();

        $session = $this->signup()->startSubscription($customer, $this->draft(), $catalog, 'https://a', 'https://b');
        $this->stripe->completeCheckout($session->sessionId);
        $workspace = $this->signup()->completeSignup($session->sessionId);

        $this->assertNotNull($workspace, 'Signup completes without any of those integrations.');
        $this->assertSame(0, DB::table('business_stripe_connections')->count(),
            "A Business's own connected Stripe account is lane B, and is never part of signup.");
    }
}
