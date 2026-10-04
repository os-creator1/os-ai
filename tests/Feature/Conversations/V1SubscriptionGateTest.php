<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Conversations\ConversationSendFailureReason;
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
 *
 * ChatGPT review correction (round 4) — passing that first gate was not
 * enough on its own: quickSend()/sent()/new() still required legacy
 * CustomerBasedPricingPlan/PlansCoverageCountries, which a V1 account also
 * never populates, so the send still failed with "Price Plan unavailable"
 * past this check. See V1ManagedNewConversationTest for the full,
 * managed-ready V1 account proven end-to-end. This file's own fixture
 * (tenant() — an assigned plan, no PlatformSubscription, no managed
 * identity) is exactly the "genuinely paid but messaging setup not
 * finished yet" shape, so it now proves the honest readiness message
 * instead of either the old "Price Plan unavailable" or a 200 render of a
 * compose form with empty, unusable selects.
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

    public function test_a_v1_account_with_an_assigned_plan_but_no_messaging_setup_yet_gets_the_readiness_message(): void
    {
        // tenant() assigns a real WorkspacePlanAssignment and creates no
        // legacy Subscription and no managed messaging identity — exactly
        // the "paid V1 self-signup that has not finished messaging setup"
        // shape (§7 requires no A2P/number setup at signup).
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.conversations.new', [$workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]));
        $response->assertSessionHas('message', ConversationSendFailureReason::MessagingNotReady->customerMessage());
        $this->assertNotSame('Price Plan unavailable', session('message'));
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
