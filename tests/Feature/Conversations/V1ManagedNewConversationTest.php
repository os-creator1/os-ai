<?php

namespace Tests\Feature\Conversations;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\ChatBox;
use App\Models\Country;
use App\Models\CustomerBasedPricingPlan;
use App\Models\PlansCoverageCountries;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Manual acceptance defect 2 (P0), full correction — a real V1 self-signup
 * account with a genuinely active platform subscription still could not
 * complete a new conversation: ChatBoxController::sent()/new() and
 * EloquentCampaignRepository::quickSend() dereferenced or required legacy
 * Customer::activeSubscription()/CustomerBasedPricingPlan/
 * PlansCoverageCountries — none of which a normal V1 signup ever populates
 * (RFC-004: legacy SMS-billing machinery, never V1 Workspace-plan
 * authority) — so the send either crashed or returned "Price Plan
 * unavailable" before ever reaching the existing managed transport
 * (ManagedDispatchDelegate/ManagedMessageDispatcher/
 * BusinessMessagingIdentityResolver) that was already fully capable of
 * carrying it.
 *
 * This proves the whole corrected path end-to-end, through the real HTTP
 * route, for the exact V1 acceptance shape: a Workspace plan assignment, a
 * provider-confirmed PlatformSubscription, zero legacy Subscription, zero
 * CustomerBasedPricingPlan, and a Business with a real managed messaging
 * identity and one active primary number.
 */
class V1ManagedNewConversationTest extends TestCase
{
    use CreatesPlatformSubscriptions;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeStripe();
        $this->bindFakeAdapter();
    }

    /**
     * @return array{customer: \App\Models\Customer, workspace: \App\Models\Workspace, business: \App\Models\Business, identity: \App\Models\BusinessMessagingIdentity, number: \App\Models\BusinessMessagingNumber}
     */
    private function v1ManagedTenant(): array
    {
        // Real §7 signup spine: assigned Workspace plan + provider-confirmed
        // PlatformSubscription — and, deliberately, nothing else. No legacy
        // Subscription, no CustomerBasedPricingPlan, no PlansCoverageCountries
        // row is ever written by this fixture.
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Managed V1 Business', BusinessStatus::Active);

        // A real managed messaging identity and one active primary number —
        // carrier readiness satisfied (campaign_assignment_status stays
        // null, which isCampaignAssignmentConfirmedOrNotRequired() treats as
        // not required).
        $identity = $this->attachIdentity($business);
        $number = $this->attachNumber($identity, $this->uniqueNumber(), true);

        return [
            'customer' => $fixture['customer'],
            'workspace' => $fixture['workspace'],
            'business' => $business,
            'identity' => $identity,
            'number' => $number,
        ];
    }

    private function usCountry(): Country
    {
        return Country::firstOrCreate(
            ['country_code' => '1', 'iso_code' => 'US'],
            ['name' => 'United States', 'status' => 1],
        );
    }

    public function test_a_v1_managed_business_can_send_a_new_conversation_end_to_end(): void
    {
        $tenant = $this->v1ManagedTenant();
        $this->authenticateAs($tenant['customer']);
        $country = $this->usCountry();

        $response = $this->post(
            route('customer.workspaces.businesses.conversations.sent', [$tenant['workspace']->uid, $tenant['business']->uid]),
            [
                'sms_type' => 'plain',
                'country_code' => (string) $country->id,
                'recipient' => '4155559999',
                'message' => 'Hello from a real V1 managed account',
                'idempotency_token' => (string) Str::uuid(),
                // Deliberately NOT submitted: 'sender_id', 'sending_server' —
                // the redesigned managed compose form never sends them, and
                // the controller must never need them for a managed send.
            ],
        );

        $response->assertRedirect();
        $this->assertNotSame('Price Plan unavailable', session('message'));

        // Exactly one managed provider dispatch, to the correct Business's
        // authoritative managed FROM number — never a value this request
        // could have forged, since none was ever submitted.
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $sent = $this->fakeAdapter->sentRequests[0];
        $this->assertSame((int) $tenant['number']->id, $sent->businessMessagingNumberId);
        $this->assertSame($tenant['number']->phone_number, $sent->fromNumber);
        $this->assertSame('+14155559999', $sent->toNumber);
        $this->assertSame((int) $tenant['identity']->id, $sent->businessMessagingIdentityId);

        // The managed operation and usage measurement were each written
        // exactly once.
        $this->assertSame(
            1,
            DB::table(ManagedMessageDispatcher::TABLE)->where('business_id', (int) $tenant['business']->id)->count(),
        );
        $this->assertSame(1, DB::table('business_usage_measurements')->where('business_id', (int) $tenant['business']->id)->count());

        // Conversation history exists for the new thread.
        $this->assertSame(1, ChatBox::where('business_id', (int) $tenant['business']->id)->count());

        // Zero legacy Subscription/pricing rows exist anywhere — this
        // account never had one, and nothing on this path fabricated one.
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, CustomerBasedPricingPlan::query()->count());
        $this->assertSame(0, PlansCoverageCountries::query()->count());
    }

    public function test_the_new_conversation_compose_screen_never_shows_price_plan_unavailable_for_a_v1_managed_business(): void
    {
        $tenant = $this->v1ManagedTenant();
        $this->authenticateAs($tenant['customer']);

        $response = $this->get(route('customer.workspaces.businesses.conversations.new', [$tenant['workspace']->uid, $tenant['business']->uid]));

        $response->assertOk();
        $response->assertDontSee('Price Plan unavailable');
        $response->assertSee($tenant['number']->phone_number);
    }

    /**
     * A V1 platform-backed Business (real assignment + PlatformSubscription)
     * that has not yet completed managed messaging setup at all (no
     * identity) must be told so honestly — never "Price Plan unavailable",
     * and never a 500.
     */
    public function test_a_v1_business_with_no_managed_identity_yet_gets_the_readiness_message_not_price_plan_unavailable(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Not Yet Set Up', BusinessStatus::Active);
        $this->authenticateAs($fixture['customer']);

        $response = $this->get(route('customer.workspaces.businesses.conversations.new', [$fixture['workspace']->uid, $business->uid]));

        $response->assertRedirect(route('customer.workspaces.businesses.conversations.index', [$fixture['workspace']->uid, $business->uid]));
        $response->assertSessionHas('message', __('locale.conversations.send_failure.messaging_not_ready'));
    }
}
