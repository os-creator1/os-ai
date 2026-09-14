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
        [$agency, $viewed, $workspace] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);

        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

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
        [$agency, $viewed, $workspace] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);
        $this->authenticateAs($agency);

        // A failed bubble, from before View As starts — the exact bubble a
        // retry while viewing targets.
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($workspace, $viewed, $box, 'Are you still open Saturday?', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('failed', $message->send_status);
        $this->fakeAdapter->rejections = [];

        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $this->postJson(route('customer.workspaces.businesses.conversations.retry', [$workspace->uid, $viewed->uid, $box->uid]), [
            'send_uid' => $message->send_uid,
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->assertCount(2, $this->fakeAdapter->sentRequests, 'The original refused attempt, then the retry while viewing.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count(), 'Still one logical bubble.');
        $this->assertSame('sent', DB::table('chat_box_messages')->where('id', $message->id)->value('send_status'));
    }

    public function test_view_as_double_click_retry_still_makes_no_second_provider_call(): void
    {
        [$agency, $viewed, $workspace] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);
        $this->authenticateAs($agency);

        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;
        $this->reply($workspace, $viewed, $box, 'Double click me', (string) Str::uuid())->assertJson(['status' => 'error']);
        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->fakeAdapter->rejections = [];

        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        // The claim a first, still-in-flight retry click already made.
        DB::table('chat_box_messages')->where('id', $message->id)->update(['send_status' => 'sending']);

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
        [$agency, $viewed, $workspace] = $this->managedAgencyTenant();
        $box = $this->inboundConversation($viewed, self::PERSON);

        Blacklists::create([
            'business_id' => $viewed->id,
            'user_id' => $viewed->customer_id,
            'number' => self::PERSON,
            'reason' => 'Opted out',
        ]);

        $this->authenticateAs($agency);
        $this->startViewAs($workspace, $viewed)->assertRedirect(route('user.home'));

        $this->reply($workspace, $viewed, $box, 'Are you still there?', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => 'Number contains in the blacklist']);

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Refused before any provider call, exactly as for the real customer.');
    }

    // -----------------------------------------------------------------

    /**
     * An Agency-tier Workspace with a managed, sendable Business the agency
     * owner can view as.
     *
     * @return array{0: \App\Models\Customer, 1: Business, 2: Workspace}
     */
    private function managedAgencyTenant(): array
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Primary', 'Northwind Agency');
        $viewed = $this->addBusiness($customer, $workspace, 'Harbor Lane Studios');

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

        $customer->user->sms_unit = 1000;
        $customer->user->save();

        return [$customer->fresh(), $viewed->fresh(), $workspace];
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
