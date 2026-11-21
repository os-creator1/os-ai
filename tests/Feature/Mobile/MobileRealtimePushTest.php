<?php

namespace Tests\Feature\Mobile;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Events\Conversation\InboundMessageReceived;
use App\Events\Mobile\MobileConversationChanged;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Library\Mobile\Push\MobilePushTransport;
use App\Listeners\Mobile\NotifyMobileOfInboundMessage;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\MobileDevice;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Mobile\Support\FakePushTransport;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Phase 2B: live conversation events and push for the phone. Telnyx is the fake managed adapter, broadcasting is
 * Event::fake (+ a configured pusher driver only for the channel-auth tests), push is FakePushTransport:
 * nothing real is contacted, sent or broadcast.
 */
class MobileRealtimePushTest extends TestCase
{
    use CreatesAutomationFixtures, CreatesCustomerContextFixtures {
        CreatesAutomationFixtures::ensureRequiredAppConfigRowsExist insteadof CreatesCustomerContextFixtures;
        CreatesAutomationFixtures::platformAdminId insteadof CreatesCustomerContextFixtures;
    }
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';
    private const BUSINESS_NUMBER = '+14155550199';
    private const CUSTOMER = '+14155552671';
    private const TOKEN_A = 'ExponentPushToken[aaaaaaaaaaaaaaaaaaaaaa]';
    private const TOKEN_B = 'ExponentPushToken[bbbbbbbbbbbbbbbbbbbbbb]';

    private FakePushTransport $push;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        $this->bindFakeAdapter();
        config(['mobile.push.enabled' => true]);
        $this->push = new FakePushTransport();
        $this->app->instance(MobilePushTransport::class, $this->push);
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------

    /** @return array{0: Customer, 1: Business, 2: Workspace, 3: object} */
    private function managedTenant(string $number = self::BUSINESS_NUMBER): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, $number, true);
        $customer->user->sms_unit = 1000;
        $customer->user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return [$customer->fresh(), $business->fresh(), $workspace, $identity];
    }

    /** Signs a customer in on a phone and registers a push token. Returns the bearer token. */
    private function phone(Customer $customer, string $pushToken, ?array $permissions = null, string $install = null): string
    {
        $customer->user->forceFill(['password' => Hash::make(self::PASSWORD)])->save();

        if ($permissions !== null) {
            $customer->forceFill(['permissions' => json_encode($permissions)])->save();
        }

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $customer->user->email, 'password' => self::PASSWORD,
            'device' => ['installation_id' => $install ?? 'install-' . Str::random(8), 'platform' => 'android'],
        ])->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson('/api/mobile/v1/devices/current', ['push_provider' => 'expo', 'push_token' => $pushToken])->assertOk();

        return $token;
    }

    private function api(string $token, string $method, string $url, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])->json($method, $url, $body);
    }

    private function inbound(object $identity, string $providerId = null, string $body = 'My secret quote request, call me'): void
    {
        $this->fakeAdapter->queueInboundWebhook(new InboundWebhookEvent(
            kind: InboundWebhookEventKind::MessageReceived, messagingProfileId: $identity->messaging_profile_id,
            destinationNumber: self::BUSINESS_NUMBER, fromNumber: self::CUSTOMER, body: $body, mediaUrls: [],
            providerMessageId: $providerId ?? 'pm_' . Str::random(10), deliveryStatus: null, occurredAt: CarbonImmutable::now(),
        ));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();
    }

    private function conversation(Business $business): ChatBox
    {
        $box = app(ConversationHistoryWriter::class)->conversationFor($business, '14155550199', '14155552671');
        $box->reply_by_customer = true;
        $box->save();

        return $box->fresh();
    }

    private function changedFor(object $user, string $type = null): \Illuminate\Support\Collection
    {
        return collect(Event::dispatched(MobileConversationChanged::class))->map(fn ($a) => $a[0])
            ->filter(fn ($e) => $e->userUid === $user->uid && ($type === null || $e->type === $type))->values();
    }

    // ---- inbound: live event + push -----------------------------------------------------------------------------

    public function test_an_inbound_sms_updates_the_inbox_live_bumps_unread_and_pushes_a_privacy_safe_notification(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, $business, , $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A);

        $this->inbound($identity);

        $box = ChatBox::query()->where('business_id', $business->id)->sole();
        $events = $this->changedFor($owner->user, 'received');
        $this->assertCount(1, $events);
        $this->assertSame($box->uid, $events[0]->conversationUid);
        $this->assertSame($business->uid, $events[0]->businessUid);
        $this->assertSame(['private-mobile.business.' . $business->uid . '.user.' . $owner->user->uid], collect($events[0]->broadcastOn())->map->name->all());
        $this->assertSame(1, (int) $box->notification, 'The shared unread counter rose.');

        $this->assertCount(1, $this->push->sent);
        $m = $this->push->sent[0];
        $this->assertSame(self::TOKEN_A, $m['to']);
        $this->assertSame('New message received.', $m['body']);
        $this->assertSame(['v' => 1, 't' => 'message', 'b' => $business->uid, 'c' => $box->uid], $m['data']);

        // Privacy: nothing about the customer or the message anywhere in what leaves the server (push or live event).
        $wire = strtolower(json_encode($m) . json_encode($events[0]->broadcastWith()));
        foreach (['secret quote', 'call me', '4155552671', '14155552671', strtolower($business->name)] as $leak) {
            $this->assertStringNotContainsString($leak, $wire);
        }
    }

    public function test_a_replayed_inbound_webhook_produces_one_push_and_one_event(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, , , $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A);

        $this->inbound($identity, 'pm_same');
        $this->inbound($identity, 'pm_same');

        $this->assertCount(1, $this->push->sent);
        $this->assertCount(1, $this->changedFor($owner->user, 'received'));
    }

    public function test_a_retried_queue_job_for_the_same_occurrence_never_pushes_twice(): void
    {
        [$owner, $business, , $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A);
        $this->inbound($identity);
        $this->assertCount(1, $this->push->sent);

        // The queue runs the very same job again (a timeout/worker restart): same occurrence key.
        $operationId = (int) DB::table('business_messaging_operations')->where('business_id', $business->id)->orderByDesc('id')->value('id');
        (new NotifyMobileOfInboundMessage())->handle(InboundMessageReceived::fromManagedOperation((int) $business->id, self::CUSTOMER, $operationId));
        (new NotifyMobileOfInboundMessage())->handle(InboundMessageReceived::fromManagedOperation((int) $business->id, self::CUSTOMER, $operationId));

        $this->assertCount(1, $this->push->sent);
        $this->assertSame(1, DB::table('mobile_push_deliveries')->count());
    }

    public function test_outgoing_messages_never_push_but_do_notify_phones_live(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, $business, $workspace] = $this->managedTenant();
        $token = $this->phone($owner, self::TOKEN_A);
        $box = $this->conversation($business);

        $this->api($token, 'POST', "/api/mobile/v1/workspaces/{$workspace->uid}/businesses/{$business->uid}/conversations/{$box->uid}/reply", ['body' => 'On my way', 'idempotency_key' => (string) Str::uuid()])->assertOk();

        $this->assertCount(0, $this->push->sent, 'An outgoing SMS is never pushed.');
        $this->assertGreaterThanOrEqual(1, $this->changedFor($owner->user, 'outbound')->count());
    }

    public function test_push_is_a_noop_when_disabled_and_inbound_is_still_recorded(): void
    {
        config(['mobile.push.enabled' => false]);
        [$owner, $business, , $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A);

        $this->inbound($identity);

        $this->assertCount(0, $this->push->sent);
        $this->assertSame(1, (int) ChatBox::query()->where('business_id', $business->id)->value('notification'));
    }

    public function test_an_unavailable_push_provider_never_loses_the_inbound_message(): void
    {
        $this->push->throw = true;
        [$owner, $business, , $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A);

        $this->inbound($identity);

        $this->assertSame(1, DB::table('chat_box_messages')->whereIn('direction', ['incoming', 'inbound'])->count());
        $this->assertSame(1, (int) ChatBox::query()->where('business_id', $business->id)->value('notification'));
        $this->assertSame(0, DB::table('mobile_push_deliveries')->count(), 'The failed attempt released its claim so it can be retried.');
    }

    // ---- eligibility --------------------------------------------------------------------------------------------

    public function test_a_phone_signed_in_to_another_business_receives_nothing_for_this_one(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$ownerA, , , $identityA] = $this->managedTenant();
        [$ownerB] = $this->managedTenant('+14155550188');
        $this->phone($ownerA, self::TOKEN_A);
        $this->phone($ownerB, self::TOKEN_B);

        $this->inbound($identityA);

        $this->assertCount(1, $this->push->sent);
        $this->assertSame(self::TOKEN_A, $this->push->sent[0]['to']);
        $this->assertCount(0, $this->changedFor($ownerB->user));
    }

    public function test_only_devices_with_a_live_session_are_pushed(): void
    {
        [$owner, , , $identity] = $this->managedTenant();
        $tokenLoggedOut = $this->phone($owner, self::TOKEN_A, null, 'install-out-0001');
        $this->phone($owner, self::TOKEN_B, null, 'install-live-0001');

        // Phone A signs out; phone B's session is revoked server-side (token deleted) and a third has expired.
        $this->api($tokenLoggedOut, 'POST', '/api/mobile/v1/auth/logout')->assertOk();
        $this->assertCount(1, MobileDevice::query()->where('user_id', $owner->user_id)->get());
        DB::table('personal_access_tokens')->where('tokenable_id', $owner->user_id)->update(['expires_at' => now()->subMinute()]);

        $this->inbound($identity);
        $this->assertCount(0, $this->push->sent, 'An expired session is never a push target.');

        DB::table('personal_access_tokens')->where('tokenable_id', $owner->user_id)->update(['expires_at' => now()->addDay()]);
        $this->inbound($identity);
        $this->assertCount(1, $this->push->sent);
        $this->assertSame(self::TOKEN_B, $this->push->sent[0]['to']);

        DB::table('personal_access_tokens')->where('tokenable_id', $owner->user_id)->delete();
        $this->push->sent = [];
        $this->inbound($identity);
        $this->assertCount(0, $this->push->sent, 'A revoked session is never a push target.');
    }

    public function test_a_disabled_account_and_a_locked_workspace_receive_nothing(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, , $workspace, $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A);

        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Lock fixture.');
        $this->inbound($identity);
        $this->assertCount(0, $this->push->sent);
        $this->assertCount(0, $this->changedFor($owner->user));
    }

    public function test_a_disabled_user_receives_nothing(): void
    {
        [$owner, , , $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A);
        $owner->user->forceFill(['status' => false])->save();

        $this->inbound($identity);

        $this->assertCount(0, $this->push->sent);
    }

    public function test_location_limited_members_are_notified_only_for_conversations_at_their_locations(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, $business, $workspace, $identity] = $this->managedTenant();
        $granted = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $other = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $granted);
        $this->phone($owner, self::TOKEN_A);
        $this->phone($staff, self::TOKEN_B, ['access_backend', 'chat_box']);

        // The conversation sits at a Location the staff member was NOT granted.
        $box = $this->conversation($business);
        DB::table('chat_boxes')->where('id', $box->id)->update(['location_id' => $other->id]);
        $this->inbound($identity);

        $this->assertSame([self::TOKEN_A], array_column($this->push->sent, 'to'));
        $this->assertCount(0, $this->changedFor($staff->user));

        // At their own Location they are notified too.
        $this->push->sent = [];
        DB::table('chat_boxes')->where('id', $box->id)->update(['location_id' => $granted->id]);
        $this->inbound($identity);
        $this->assertEqualsCanonicalizing([self::TOKEN_A, self::TOKEN_B], array_column($this->push->sent, 'to'));
        $this->assertCount(1, $this->changedFor($staff->user, 'received'));
    }

    // ---- token hygiene ------------------------------------------------------------------------------------------

    public function test_provider_reported_unregistered_and_malformed_tokens_are_cleared_and_never_reused(): void
    {
        [$owner, , , $identity] = $this->managedTenant();
        $this->phone($owner, self::TOKEN_A, null, 'install-dead-0001');
        $this->push->tickets = [['status' => 'error', 'error' => 'DeviceNotRegistered']];

        $this->inbound($identity);

        $this->assertCount(1, $this->push->sent);
        $this->assertNull(MobileDevice::query()->where('user_id', $owner->user_id)->first()->push_token, 'DeviceNotRegistered clears the token.');

        $this->push->sent = [];
        $this->inbound($identity);
        $this->assertCount(0, $this->push->sent, 'A cleared token is never sent to again.');

        // A malformed token that somehow reached the table is cleared, not sent.
        MobileDevice::query()->where('user_id', $owner->user_id)->update(['push_token' => 'not-a-real-token']);
        $this->inbound($identity);
        $this->assertCount(0, $this->push->sent);
        $this->assertNull(MobileDevice::query()->where('user_id', $owner->user_id)->first()->push_token);
    }

    // ---- delivery status + read ---------------------------------------------------------------------------------

    public function test_delivery_updates_reach_the_right_conversation_and_move_the_bubble(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, $business, $workspace, $identity] = $this->managedTenant();
        $token = $this->phone($owner, self::TOKEN_A);
        $box = $this->conversation($business);
        $other = app(ConversationHistoryWriter::class)->conversationFor($business, '14155550199', '14155558888');
        $this->api($token, 'POST', "/api/mobile/v1/workspaces/{$workspace->uid}/businesses/{$business->uid}/conversations/{$box->uid}/reply", ['body' => 'Hi', 'idempotency_key' => (string) Str::uuid()])->assertOk();

        $row = DB::table('chat_box_messages')->where('box_id', $box->id)->where('direction', 'outgoing')->sole();
        $operation = DB::table('business_messaging_operations')->where('id', $row->business_messaging_operation_id)->sole();
        $dlr = fn (string $status) => $this->fakeAdapter->queueInboundWebhook(new InboundWebhookEvent(
            kind: InboundWebhookEventKind::DeliveryStatus, messagingProfileId: $identity->messaging_profile_id, destinationNumber: null, fromNumber: null,
            body: null, mediaUrls: [], providerMessageId: $operation->provider_message_id, deliveryStatus: $status, occurredAt: CarbonImmutable::now(),
        ));

        $dlr('delivered');
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();
        $this->assertSame('delivered', DB::table('chat_box_messages')->where('id', $row->id)->value('send_status'));
        $statusEvents = $this->changedFor($owner->user, 'status');
        $this->assertCount(1, $statusEvents);
        $this->assertSame($box->uid, $statusEvents[0]->conversationUid, 'The correct conversation, not the other thread.');
        $this->assertNotSame($other->uid, $statusEvents[0]->conversationUid);
        $this->assertCount(0, $this->push->sent, 'A status change is never pushed.');

        // The mobile API reports the new state.
        $m = collect($this->api($token, 'GET', "/api/mobile/v1/workspaces/{$workspace->uid}/businesses/{$business->uid}/conversations/{$box->uid}")->json('data.messages'))->last();
        $this->assertSame('delivered', $m['status']);
    }

    public function test_a_late_failed_receipt_marks_delivery_failed_and_notifies(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, $business, $workspace, $identity] = $this->managedTenant();
        $token = $this->phone($owner, self::TOKEN_A);
        $box = $this->conversation($business);
        $this->api($token, 'POST', "/api/mobile/v1/workspaces/{$workspace->uid}/businesses/{$business->uid}/conversations/{$box->uid}/reply", ['body' => 'Hi', 'idempotency_key' => (string) Str::uuid()])->assertOk();
        $row = DB::table('chat_box_messages')->where('box_id', $box->id)->where('direction', 'outgoing')->sole();
        $operation = DB::table('business_messaging_operations')->where('id', $row->business_messaging_operation_id)->sole();

        $this->fakeAdapter->queueInboundWebhook(new InboundWebhookEvent(
            kind: InboundWebhookEventKind::DeliveryStatus, messagingProfileId: $identity->messaging_profile_id, destinationNumber: null, fromNumber: null,
            body: null, mediaUrls: [], providerMessageId: $operation->provider_message_id, deliveryStatus: 'failed', occurredAt: CarbonImmutable::now(),
        ));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();

        $this->assertSame('delivery_failed', DB::table('chat_box_messages')->where('id', $row->id)->value('send_status'));
        $this->assertCount(1, $this->changedFor($owner->user, 'status'));
    }

    public function test_marking_read_tells_the_users_other_screens_live(): void
    {
        Event::fake([MobileConversationChanged::class]);
        [$owner, $business, $workspace] = $this->managedTenant();
        $token = $this->phone($owner, self::TOKEN_A);
        $box = $this->conversation($business);
        DB::table('chat_boxes')->where('id', $box->id)->update(['notification' => 2]);

        $this->api($token, 'POST', "/api/mobile/v1/workspaces/{$workspace->uid}/businesses/{$business->uid}/conversations/{$box->uid}/read")->assertOk();

        $this->assertCount(1, $this->changedFor($owner->user, 'read'));
        $this->assertCount(0, $this->push->sent);
    }

    // ---- channel authorization + config -------------------------------------------------------------------------

    private function enablePusher(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => '1234',
            'broadcasting.connections.pusher.options.cluster' => 'mt1',
        ]);
    }

    private function auth(string $token, string $channel)
    {
        return $this->api($token, 'POST', '/api/mobile/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);
    }

    public function test_a_phone_can_subscribe_only_to_its_own_channel_for_a_business_it_may_open(): void
    {
        $this->enablePusher();
        [$ownerA, $businessA] = $this->managedTenant();
        [$ownerB, $businessB] = $this->managedTenant('+14155550188');
        $tokenA = $this->phone($ownerA, self::TOKEN_A);
        $mine = 'private-' . MobileConversationChanged::channelName($businessA->uid, $ownerA->user->uid);

        $ok = $this->auth($tokenA, $mine)->assertOk();
        $this->assertStringStartsWith('test-key:', $ok->json('auth'));

        // Another Business, another person's channel, the web inbox channel, garbage: all refused.
        $this->auth($tokenA, 'private-' . MobileConversationChanged::channelName($businessB->uid, $ownerA->user->uid))->assertStatus(403);
        $this->auth($tokenA, 'private-' . MobileConversationChanged::channelName($businessA->uid, $ownerB->user->uid))->assertStatus(403);
        $this->auth($tokenA, 'private-chat.business.' . $businessA->uid)->assertStatus(403);
        $this->auth($tokenA, 'presence-mobile.business.' . $businessA->uid . '.user.' . $ownerA->user->uid)->assertStatus(403);
        $this->api($tokenA, 'POST', '/api/mobile/v1/broadcasting/auth', ['channel_name' => $mine])->assertStatus(403);
    }

    public function test_subscription_requires_a_mobile_session_and_ends_with_lock_or_membership_loss(): void
    {
        $this->enablePusher();
        [$owner, $business, $workspace] = $this->managedTenant();
        $token = $this->phone($owner, self::TOKEN_A);
        $channel = 'private-' . MobileConversationChanged::channelName($business->uid, $owner->user->uid);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/mobile/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])->assertStatus(401);
        $wildcard = $owner->user->createToken('legacy', ['*'])->plainTextToken;
        $this->auth($wildcard, $channel)->assertStatus(401);

        $this->auth($token, $channel)->assertOk();
        app(EntitlementManager::class)->changePlanStatus($workspace, WorkspacePlanAssignmentStatus::Suspended, $this->platformAdminId(), 'Lock fixture.');
        $this->auth($token, $channel)->assertStatus(403);
    }

    public function test_a_member_who_loses_access_cannot_subscribe_and_cannot_open_the_conversation_from_a_notification(): void
    {
        $this->enablePusher();
        [, $business, $workspace] = $this->managedTenant();
        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $token = $this->phone($staff, self::TOKEN_B, ['access_backend', 'chat_box']);
        $box = $this->conversation($business);
        $channel = 'private-' . MobileConversationChanged::channelName($business->uid, $staff->user->uid);
        $conversationUrl = "/api/mobile/v1/workspaces/{$workspace->uid}/businesses/{$business->uid}/conversations/{$box->uid}";

        $this->auth($token, $channel)->assertOk();
        $this->api($token, 'GET', $conversationUrl)->assertOk();

        // Membership removed: the notification tap's server re-check (the normal API) now says not found, the
        // Business is no longer listed, and the channel is refused.
        $membership->forceFill(['is_active' => false])->save();
        $this->api($token, 'GET', $conversationUrl)->assertStatus(404);
        $this->assertSame([], collect($this->api($token, 'GET', '/api/mobile/v1/businesses')->json('data.businesses'))->pluck('business_uid')->all());
        $this->auth($token, $channel)->assertStatus(403);
    }

    public function test_the_realtime_config_exposes_only_public_parameters(): void
    {
        [$owner] = $this->managedTenant();
        $token = $this->phone($owner, self::TOKEN_A);

        $this->api($token, 'GET', '/api/mobile/v1/realtime/config')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.key', null);

        $this->enablePusher();
        $res = $this->api($token, 'GET', '/api/mobile/v1/realtime/config')->assertOk();
        $res->assertJsonPath('data.enabled', true)->assertJsonPath('data.key', 'test-key')->assertJsonPath('data.cluster', 'mt1');
        $this->assertStringNotContainsString('test-secret', $res->getContent());
        $this->assertStringNotContainsString('1234', $res->json('data.key'));
    }
}
