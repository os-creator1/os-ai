<?php

namespace Tests\Feature\Conversations;

use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Library\Timeline\ContactActivityTimeline;
use App\Library\Timeline\TimelineItem;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\Campaigns;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Conversations is the canonical history of what happened with a person, so a
 * managed send the provider accepted must be in it — with its final text,
 * exactly once, and shown as one bubble — whoever asked for it: a person in
 * Conversations, an Automations V2 step, a campaign.
 *
 * Every send here goes through the REAL send core and the real managed
 * dispatcher; only the provider is the deterministic fake adapter, and
 * `Http::fake()` catches any send that escaped into a legacy provider.
 */
class ManagedOutboundConversationHistoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;

    private const MANAGED_NUMBER = '+14155550199';

    private const PERSON = '14155552671';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();

        // The first user is always a super admin; keep the customer from being it.
        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    // =================================================================
    // A. A reply from Conversations
    // =================================================================

    public function test_a_managed_reply_from_conversations_is_recorded_with_its_text_and_shows_once_on_reopen(): void
    {
        [$customer, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);

        $this->reply($workspace, $business, $box, 'We have 10am or 2pm on Saturday.', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        Http::assertNothingSent();

        $messages = DB::table('chat_box_messages')->where('box_id', $box->id)->where('direction', 'outgoing')->get();
        $this->assertCount(1, $messages, 'The accepted send is in the conversation it was sent from.');
        $this->assertSame('We have 10am or 2pm on Saturday.', $messages[0]->message);
        $this->assertSame('from', $messages[0]->send_by);
        $this->assertSame(ConversationHistoryWriter::SOURCE_CONVERSATIONS, $messages[0]->source);
        $this->assertSame((int) DB::table(ManagedMessageDispatcher::TABLE)->where('direction', 'outbound')->value('id'), (int) $messages[0]->business_messaging_operation_id);

        // Billing/report semantics are not touched: a managed quick send still writes no report.
        $this->assertSame(0, DB::table('reports')->count());

        $timeline = $this->openTimeline($workspace, $business, $box)->assertOk()->json('timeline');
        $this->assertSame(1, substr_count($timeline, 'We have 10am or 2pm on Saturday.'), 'Reopened: the exact message, once.');
        $this->assertStringContainsString('Sent manually', $timeline);
    }

    // =================================================================
    // B. A reply the provider refuses
    // =================================================================

    public function test_a_refused_managed_reply_records_nothing_and_leaves_no_bubble(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $this->fakeAdapter->rejections['*'] = ProviderErrorCategory::Terminal;

        $this->reply($workspace, $business, $box, 'This one will not arrive', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error']);

        $this->assertSame(0, DB::table('chat_box_messages')->where('direction', 'outgoing')->count());
        $this->assertStringNotContainsString('This one will not arrive', $this->openTimeline($workspace, $business, $box)->json('timeline'));
    }

    // =================================================================
    // C. The same logical send, again
    // =================================================================

    public function test_replaying_the_same_reply_sends_once_and_records_once(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $token = (string) Str::uuid();

        $this->reply($workspace, $business, $box, 'Double click', $token)->assertJson(['status' => 'success']);
        $this->reply($workspace, $business, $box, 'Double click', $token)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The dispatcher returns the recorded result; no second provider call.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('direction', 'outgoing')->count());

        // Two genuinely separate replies with separate tokens are two messages.
        $this->reply($workspace, $business, $box, 'A second, real reply', (string) Str::uuid())->assertJson(['status' => 'success']);
        $this->assertSame(2, DB::table('chat_box_messages')->where('direction', 'outgoing')->count());
    }

    public function test_when_history_cannot_be_written_the_accepted_send_is_still_a_success_and_a_replay_records_it_once(): void
    {
        [, $business, $workspace] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $token = (string) Str::uuid();

        Log::spy();

        $this->app->instance(ConversationHistoryWriter::class, new class {
            public function recordManagedOutbound(mixed ...$arguments): never
            {
                throw new \RuntimeException('history store unavailable');
            }
        });

        $this->reply($workspace, $business, $box, 'Sent while history was down', $token)
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The provider accepted it.');
        $this->assertSame(0, DB::table('chat_box_messages')->where('direction', 'outgoing')->count());
        Log::shouldHaveReceived('error')->withArgs(fn (string $event, array $context): bool => $event === 'conversation_history.managed_outbound_not_recorded'
            && $context['business_id'] === (int) $business->id
            && ! str_contains(json_encode($context), self::PERSON)
            && ! str_contains(json_encode($context), 'history was down'))->once();

        // History is back; the client retries the same logical send.
        $this->app->forgetInstance(ConversationHistoryWriter::class);

        $this->reply($workspace, $business, $box, 'Sent while history was down', $token)->assertJson(['status' => 'success']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Reconciled without sending it again.');
        $this->assertSame(['Sent while history was down'], DB::table('chat_box_messages')->where('direction', 'outgoing')->pluck('message')->all());
    }

    // =================================================================
    // D. Automations V2 over managed transport
    // =================================================================

    public function test_an_automation_text_over_managed_transport_is_recorded_on_the_persons_conversation_and_shown_once(): void
    {
        [, $business] = $this->managedTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->smsStep('Thanks for booking with us!'), $this->endStep()], name: 'Booking thanks');
        $contact = $this->contact($business, $this->contactGroup($business), self::PERSON);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $stepRunId = (int) DB::table('automation_step_runs')->where('enrollment_id', $enrollment->id)->where('node_type', 'send_sms')->value('id');
        $this->assertSame('succeeded', DB::table('automation_step_runs')->where('id', $stepRunId)->value('status'));

        $box = ChatBox::query()->where('business_id', $business->id)->where('to', self::PERSON)->sole();
        $this->assertSame('14155550199', $box->from, 'The conversation is the Business\'s managed number and this person.');

        $message = DB::table('chat_box_messages')->where('box_id', $box->id)->sole();
        $this->assertSame('Thanks for booking with us!', $message->message);
        $this->assertSame($stepRunId, (int) $message->automation_step_run_id);

        $items = $this->timelineFor($business, $box);
        $bubbles = array_values(array_filter($items, fn (TimelineItem $item): bool => $item->isMessage()));

        $this->assertCount(1, $bubbles);
        $this->assertSame('Sent by automation: Booking thanks', $bubbles[0]->via);
        $this->assertNotContains('Automation “Booking thanks” sent a text', array_map(fn (TimelineItem $item): string => $item->title, $items), 'The message stands for its step: no second copy as a card.');
    }

    // =================================================================
    // E. A campaign send
    // =================================================================

    /**
     * One managed campaign send, tracked by two campaign jobs — two contacts of
     * the campaign on the same number share one operation key (a redelivered
     * job reaches it the same way). Driven exactly as SendMessage does it:
     * sendSMS(), then track_message().
     */
    public function test_a_managed_campaign_send_tracked_twice_is_one_provider_send_one_message_and_one_bubble(): void
    {
        [, $business] = $this->managedTenant();
        $server = SendingServer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $campaign = Campaigns::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'campaign_name' => 'Spring promo',
            'message' => 'Spring sessions are open',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_NEW,
        ]);

        $group = $this->contactGroup($business, 'Spring list');
        $firstContact = $this->contact($business, $group, self::PERSON);
        $secondContact = $this->contact($business, $group, self::PERSON);

        $payload = [
            'user_id' => $business->customer_id,
            'campaign_id' => $campaign->id,
            'phone' => self::PERSON,
            'sender_id' => 'AUTOSENDER',
            'message' => 'Spring sessions are open',
            'sms_type' => 'plain',
            'cost' => 0,
            'sms_count' => 1,
        ];

        // THE INITIAL SEND.
        $first = $campaign->sendSMS($payload);
        $campaign->track_message($first, $firstContact, $server);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'One provider send.');
        $this->assertSame('Sent', $first->status);

        $message = DB::table('chat_box_messages')->where('direction', 'outgoing')->sole();
        $this->assertSame('Spring sessions are open', $message->message);
        $this->assertSame(ConversationHistoryWriter::SOURCE_CAMPAIGN, $message->source);

        $box = ChatBox::query()->findOrFail($message->box_id);
        $this->assertSame(['Spring sessions are open'], $this->bubbleBodies($business, $box), 'One timeline bubble.');
        $this->assertSame(['Sent by campaign: Spring promo'], array_map(fn (TimelineItem $item) => $item->via, $this->bubbles($business, $box)));

        // THE SAME LOGICAL SEND AGAIN — the second job for that number.
        $again = $campaign->sendSMS($payload);
        $campaign->track_message($again, $secondContact, $server);

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Zero second provider sends.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('direction', 'outgoing')->count(), 'Still one canonical conversation message.');
        $this->assertSame(['Spring sessions are open'], $this->bubbleBodies($business, $box), 'Still ONE timeline bubble.');

        // Campaign accounting is exactly what it was: each tracked job has its
        // own report and its own tracking log, and neither collides.
        $operationId = (int) $message->business_messaging_operation_id;
        $reports = DB::table('reports')->where('campaign_id', $campaign->id)->orderBy('id')->get();

        $this->assertCount(2, $reports);
        $this->assertSame('Sent', $again->status);
        $this->assertNotSame((int) $first->id, (int) $again->id);
        $this->assertSame([$operationId, $operationId], $reports->pluck('business_messaging_operation_id')->map(fn ($id) => (int) $id)->all(), 'Both reports carry the one managed operation.');
        $this->assertSame((int) $first->id, (int) DB::table(ManagedMessageDispatcher::TABLE)->where('id', $operationId)->value('report_id'), 'The delivery correlation still names the first report.');
        $this->assertSame(
            [(string) $first->id, (string) $again->id],
            DB::table('tracking_logs')->where('campaign_id', $campaign->id)->orderBy('id')->pluck('message_id')->map(fn ($id) => (string) $id)->all(),
        );
    }
    public function test_sender_verification_still_refuses_a_business_that_is_not_managed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);
        $customer->user->sms_unit = 1000;
        $customer->user->save();

        $box = app(ConversationHistoryWriter::class)->conversationFor($business, '14155550377', self::PERSON);
        $box->save();

        $this->reply($workspace, $business, $box, 'From a number this Business does not own', (string) Str::uuid())
            ->assertOk()
            ->assertJson(['status' => 'error', 'message' => __('locale.sender_id.sender_id_invalid', ['sender_id' => '14155550377'])]);

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, DB::table('chat_box_messages')->count());
    }

    // =================================================================
    // F. Business isolation
    // =================================================================

    public function test_the_same_person_in_two_businesses_never_shares_history(): void
    {
        [, $alpha, $alphaWorkspace] = $this->managedTenant(self::MANAGED_NUMBER);
        [, $bravo] = $this->managedTenant('+14155550288');

        $alphaBox = $this->inboundConversation($alpha, self::PERSON);
        $bravoBox = $this->inboundConversation($bravo, self::PERSON, '14155550288');

        $this->reply($alphaWorkspace, $alpha, $alphaBox, 'Only for Alpha', (string) Str::uuid())->assertJson(['status' => 'success']);

        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $alphaBox->id)->where('direction', 'outgoing')->count());
        $this->assertSame(0, DB::table('chat_box_messages')->where('box_id', $bravoBox->id)->where('direction', 'outgoing')->count());
        $this->assertSame(0, ChatBox::query()->where('business_id', $bravo->id)->where('id', '!=', $bravoBox->id)->count());

        foreach ($this->timelineFor($bravo, $bravoBox) as $item) {
            $this->assertNotSame('Only for Alpha', $item->body);
        }
    }

    // -----------------------------------------------------------------

    /**
     * An entitled, sendable Business with a managed identity and one active
     * primary number.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
    private function managedTenant(string $managedNumber = self::MANAGED_NUMBER): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);

        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, $managedNumber, true);

        $customer->user->sms_unit = 1000;
        $customer->user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return [$customer->fresh(), $business->fresh(), $workspace];
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
        $this->authenticateAsCustomer(Customer::query()->where('user_id', $business->customer_id)->firstOrFail(), ['chat_box']);

        return $this->postJson(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $box->uid]), [
            'message' => $message,
            'idempotency_token' => $token,
        ]);
    }

    private function openTimeline(Workspace $workspace, Business $business, ChatBox $box): TestResponse
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$workspace->uid, $business->uid, $box->uid]));
    }

    /** @return list<TimelineItem> the message bubbles only */
    private function bubbles(Business $business, ChatBox $box): array
    {
        return array_values(array_filter($this->timelineFor($business, $box), fn (TimelineItem $item): bool => $item->isMessage()));
    }

    /** @return list<?string> */
    private function bubbleBodies(Business $business, ChatBox $box): array
    {
        return array_map(fn (TimelineItem $item): ?string => $item->body, $this->bubbles($business, $box));
    }

    /** @return list<TimelineItem> */
    private function timelineFor(Business $business, ChatBox $box): array
    {
        $box = $box->fresh();

        return app(ContactActivityTimeline::class)->forConversation($business, $box, $box->resolveDisplayContact($business))->items;
    }
}
