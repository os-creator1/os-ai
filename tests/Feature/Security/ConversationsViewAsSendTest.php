<?php

namespace Tests\Feature\Security;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Product decision (PR #301 correction, superseding the first pass on this
 * file): View As is true impersonation. An authorized agency/platform actor
 * using View As may perform anything the viewed customer could, including a
 * real Conversations send or retry — there is no special View-As
 * prohibition for it.
 *
 * What DOES still apply, unconditionally, is every ordinary check a real
 * customer's own send goes through: Business authorization and tenancy,
 * messaging readiness, current funding, STOP/blacklist, and the retry
 * seam's own idempotency/double-click guard. View As narrows nothing about
 * those and grants nothing beyond them either.
 *
 * The acting HTTP identity (Auth::user()) is never reassigned by View As —
 * it stays the real agency/platform actor throughout the request, exactly
 * as it does for every other route this session's own middleware narrows
 * rather than impersonates at the authentication layer. Nothing in this
 * feature's code path reads or writes an actor identity of its own, so
 * there is nothing here to falsify.
 *
 * Contract 13 remediation, R2 correction: the four sending tests below run
 * against the CURRENT, correct cross-Workspace Agency View As topology —
 * createAgencyManagedClient() plus the real
 * customer.workspaces.clients.view-as route, replacing the pre-Contract-13
 * fixture's now-impossible sibling-Business-in-one-Workspace shape.
 *
 * That corrected fixture exposed a genuine production integration gap, since
 * fixed: ChatBoxController::resolveBusiness() authorized purely through
 * WorkspaceManager::userCanAccessBusiness(), which correctly and permanently
 * answers false for an Agency actor viewing a Client cross-Workspace (see
 * AgencyViewAsTest::test_the_real_agency_actor_stays_the_actor_and_client_tenancy_is_not_widened),
 * and the "defence in depth" check below it only ever narrowed further. So
 * these sends never actually worked cross-Workspace; the old fixture only
 * appeared to prove they did because the acting Agency owner also happened to
 * own the viewed Workspace. The authorization now runs through the shared
 * App\Library\Workspace\BusinessRouteAccess decision — ordinary tenancy OR
 * the exact currently-valid View-As target — so these tests exercise the real
 * product behavior they always claimed to.
 *
 * The complementary shell-side half of the same gap (CustomerContextResolver
 * resolving the Agency's OWN Business while a client was being viewed) is
 * covered by AgencyViewAsContextResolutionTest.
 */
class ConversationsViewAsSendTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private const PERSON = '14155552671';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();
    }

    public function test_view_as_can_send_a_reply_exactly_as_the_customer_could(): void
    {
        [$agency, $viewed, $workspace, $agencyWorkspace] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);

        $this->authenticateAs($agency);
        $this->startClientViewAs($agencyWorkspace, $workspace)->assertRedirect(route('user.home'));

        $this->reply($workspace, $viewed, $box, 'Sent while viewing as the client', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('Sent while viewing as the client', $message->message);
        $this->assertSame('sent', $message->send_status);
    }

    public function test_view_as_can_retry_a_failed_send_exactly_as_the_customer_could(): void
    {
        [$agency, $viewed, $workspace, $agencyWorkspace, $clientOwner] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);

        // A failed bubble, from before View As starts — the exact bubble a
        // retry while viewing targets. The CLIENT authors it, as they would
        // have: the Agency actor has no ordinary tenancy of this Business and
        // no session yet, so it could not have been them.
        $this->authenticateAs($clientOwner);
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($workspace, $viewed, $box, 'Are you still open Saturday?', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status);
        $this->fakeAdapter->rejections = [];

        $this->authenticateAs($agency);
        $this->startClientViewAs($agencyWorkspace, $workspace)->assertRedirect(route('user.home'));

        $this->postJson(route('customer.workspaces.businesses.conversations.retry', [$workspace->uid, $viewed->uid, $box->uid]), [
            'send_uid' => $message->send_uid,
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->assertCount(2, $this->fakeAdapter->sentRequests, 'The original refused attempt, then the retry while viewing.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'Still one logical bubble.');
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));
    }

    public function test_view_as_double_click_retry_still_makes_no_second_provider_call(): void
    {
        [$agency, $viewed, $workspace, $agencyWorkspace, $clientOwner] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);

        $this->authenticateAs($clientOwner);
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($workspace, $viewed, $box, 'Double click me', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->fakeAdapter->rejections = [];

        $this->authenticateAs($agency);
        $this->startClientViewAs($agencyWorkspace, $workspace)->assertRedirect(route('user.home'));

        // The claim a first, still-in-flight retry click already made —
        // freshly stamped, so reconciliation correctly treats it as a live
        // claim rather than a dead one and leaves it untouched.
        DB::table('chat_box_messages')->where('id', $message->id)->update(['send_status' => 'sending', 'send_claimed_at' => now()]);

        $this->postJson(route('customer.workspaces.businesses.conversations.retry', [$workspace->uid, $viewed->uid, $box->uid]), [
            'send_uid' => $message->send_uid,
        ])->assertOk()->assertJson(['status' => 'error']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The retry claim guard applies identically while viewing — no second provider call.');
    }

    /**
     * View As grants no exemption from the ordinary rules a real customer's
     * own send is refused by (item 7's STOP/blacklist requirement) — proven
     * here as a blacklisted destination, refused identically while viewing.
     */
    public function test_view_as_is_still_refused_by_the_same_blacklist_rule_as_the_customer(): void
    {
        [$agency, $viewed, $workspace, $agencyWorkspace] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);

        Blacklists::create([
            'business_id' => $viewed->id,
            'user_id' => $viewed->customer_id,
            'number' => self::PERSON,
            'reason' => 'Opted out',
        ]);

        $this->authenticateAs($agency);
        $this->startClientViewAs($agencyWorkspace, $workspace)->assertRedirect(route('user.home'));

        $this->reply($workspace, $viewed, $box, 'Are you still there?', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => 'Number contains in the blacklist']);

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Refused before any provider call, exactly as for the real customer.');
    }

    // -----------------------------------------------------------------

    /**
     * A real, current V1 Agency-managed Client Workspace (Contract 13
     * remediation: the old fixture put the "viewed" Business as a sibling
     * inside the Agency's own Workspace — impossible under
     * businesses_workspace_id_unique. The current, canonical shape is a
     * separate Client Workspace linked by an Active
     * AgencyClientWorkspaceRelationship, viewed via startAgencyView()/the
     * customer.workspaces.clients.view-as route — which is exactly what
     * this class's own docblock describes: "an authorized agency/platform
     * actor using View As" on a client, not a sibling Business).
     *
     * @return array{0: \App\Models\Customer, 1: Business, 2: Workspace, 3: Workspace, 4: \App\Models\Customer}
     */
    private function managedAgencyTenant(): array
    {
        $pair = $this->createAgencyManagedClient(clientBusinessName: 'Harbor Lane Studios', agencyBusinessName: 'Primary', agencyWorkspaceName: 'Northwind Agency');
        $customer = $pair['agencyOwner'];
        $agencyWorkspace = $pair['agencyWorkspace'];
        $workspace = $pair['clientWorkspace'];
        $viewed = $pair['clientBusiness'];
        $clientOwner = $pair['clientOwner'];

        // A managed Client account carries its OWN plan, exactly like any
        // other customer: the `conversations` entitlement below is decided
        // against the Client Workspace, never against the Agency's.
        $this->assignTier($workspace, WorkspacePlanTier::Growth);

        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'View As Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'options' => json_encode(['plain' => true, 'mms' => true, 'plain_sms' => 0.05, 'mms_sms' => 0.10]),
        ]);

        Subscription::create([
            'user_id' => $viewed->customer_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        $identity = $this->attachIdentity($viewed);
        $this->attachNumber($identity, '+14155550199', true);

        // The sending units belong to the Business that sends — the CLIENT's
        // own owner. View As spends the viewed customer's allowance, never the
        // Agency actor's own (Contract 04 §6/§11: viewing confers no financial
        // authority, and certainly no funding transfer).
        $clientOwner->user->sms_unit = 1000;
        $clientOwner->user->save();

        return [$customer->fresh(), $viewed->fresh(), $workspace, $agencyWorkspace, $clientOwner->fresh()];
    }

    /**
     * The current, real cross-Workspace Agency View As entry point
     * (Contract 04/07/08A): posts to customer.workspaces.clients.view-as,
     * the same route AgencyClientsController::viewAs() registers and the
     * only one this class's Agency-manages-a-client scenario now uses.
     */
    private function startClientViewAs(Workspace $agencyWorkspace, Workspace $clientWorkspace): TestResponse
    {
        return $this->post(route('customer.workspaces.clients.view-as', [$agencyWorkspace->uid, $clientWorkspace->uid]));
    }

    /** The conversation managed inbound (#285) keys for this person. */
    private function inboundConversation(Business $business, string $person, string $businessDigits = '14155550199'): ChatBox
    {
        $box = app(ConversationHistoryWriter::class)->conversationFor($business, $businessDigits, $person);
        $box->reply_by_customer = true;
        $box->save();

        return $box->fresh();
    }

    private function reply(Workspace $workspace, Business $business, ChatBox $box, string $message, string $token): TestResponse
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $box->uid]), [
            'message' => $message,
            'idempotency_token' => $token,
        ]);
    }
}
