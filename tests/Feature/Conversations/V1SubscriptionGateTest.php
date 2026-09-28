<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Manual acceptance defect 2 (P0) — "New Conversation" was hard-blocked for
 * every V1 account: ChatBoxController::new() gated on the LEGACY
 * Customer::activeSubscription(), which no V1 self-signup account ever
 * populates (V1 bills through WorkspacePlanAssignment instead), so the
 * check read "no active subscription" regardless of a genuinely active
 * platform subscription.
 */
class V1SubscriptionGateTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    public function test_a_v1_account_with_an_active_platform_subscription_can_open_new_conversation(): void
    {
        // tenant() assigns a real WorkspacePlanAssignment and creates no
        // legacy Subscription at all — exactly the V1 self-signup shape.
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.conversations.new', [$workspace->uid, $business->uid]));

        $response->assertOk();
    }

    /**
     * A Workspace with no plan assignment at all never reaches the
     * subscription check this fix touches: EntitlementManager::decide()
     * already refuses the Conversations feature itself first (a separate,
     * pre-existing, unrelated gate), so the request 404s before
     * ChatBoxController::new() ever runs its own check. That is still a
     * genuine block — this test exists to catch a future change that
     * loosens both gates at once, not to assert which one fires.
     */
    public function test_an_account_with_no_subscription_of_any_kind_is_still_blocked(): void
    {
        $fixture = $this->createIndependentWorkspaceBusiness();
        $this->authenticateAs($fixture['customer']);

        $url = route('customer.workspaces.businesses.conversations.new', [$fixture['workspace']->uid, $fixture['business']->uid]);

        $this->get($url)->assertNotFound();
    }
}
