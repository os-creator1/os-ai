<?php

namespace Tests\Feature\AgencyOutreach;

use App\Events\Conversation\InboundMessageReceived;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\AgencyOutreach\OutreachMessageSender;
use App\Library\Messaging\BusinessSmsSendingPath;
use App\Library\Messaging\ManagedDispatchDelegate;
use App\Library\Messaging\ManagedTransportBilling;
use App\Models\AgencyProspectingChannel;
use App\Models\Blacklists;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * An agency sending through its OWN Telnyx account, through the SAME dispatch seam as managed
 * messaging: outbound (Outreach, Conversations, Automations), inbound and delivery events,
 * billing separation, no silent fallback, and tenant isolation. Every Telnyx call is mocked.
 */
class AgencyByoTelnyxMessagingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;
    use BuildsAgencyByoChannels;

    protected function setUp(): void
    {
        parent::setUp();

        // bootOutreach() minus its catch-all Http::fake(): this suite fakes Telnyx precisely.
        $this->bindFakeAdapter();
        $this->ensureRequiredAppConfigRowsExist();
        User::create([
            'first_name' => 'Placeholder', 'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->fakeTelnyx();
    }

    // ------------------------------------------------------------------ outbound: Outreach

    public function test_outreach_sends_through_the_agencys_own_telnyx_account_not_the_platform(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['key' => 'agency-secret-key', 'profile' => 'agency-profile', 'sender' => '12025552000', 'default' => true]);

        $result = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello there', 'byo-op-1');

        $this->assertSame('sent', $result->status);
        $this->assertSame('tx-msg-1', $result->providerMessageId);

        $requests = $this->telnyxMessageRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('Bearer agency-secret-key', $requests[0]->header('Authorization')[0]);
        $this->assertSame('+12025552000', $requests[0]['from']);
        $this->assertSame('+12025551000', $requests[0]['to']);
        $this->assertSame('agency-profile', $requests[0]['messaging_profile_id']);
        $this->assertSame('Hello there', $requests[0]['text']);

        // The platform's managed adapter (and so its credentials) was never used.
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        $operation = DB::table('business_messaging_operations')->where('operation_key', 'byo-op-1')->sole();
        $this->assertSame('byo', $operation->transport_mode);
        $this->assertNull($operation->business_messaging_identity_id);
        $this->assertSame('+12025552000', $operation->sender_number);
        $this->assertSame($channel->id, (int) $operation->agency_prospecting_channel_id);
        $this->assertSame('accepted', $operation->status);
        $this->assertSame('tx-msg-1', $operation->provider_message_id);
        $this->assertSame((int) $business->id, (int) $operation->business_id);
    }

    public function test_a_repeated_operation_key_never_sends_twice(): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, ['default' => true]);

        $first = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'byo-dup-1');
        $second = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'byo-dup-1');

        $this->assertSame('sent', $first->status);
        $this->assertSame($first->providerMessageId, $second->providerMessageId);
        $this->assertCount(1, $this->telnyxMessageRequests());
        $this->assertSame(1, DB::table('business_messaging_operations')->where('operation_key', 'byo-dup-1')->count());
    }

    public function test_byo_transport_is_measured_as_byo_and_never_touches_the_managed_wallet(): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, ['default' => true]);

        $this->mock(ManagedTransportBilling::class, fn ($mock) => $mock->shouldNotReceive('reserve')->shouldNotReceive('commit'));

        app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'byo-bill-1');

        $measurement = DB::table('business_usage_measurements')->where('business_id', $business->id)->where('idempotency_key', 'byo-bill-1')->sole();
        $this->assertSame('byo', $measurement->transport_marker);
    }

    public function test_without_a_chosen_byo_sender_the_managed_path_is_unchanged(): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, ['default' => false]);

        $result = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'managed-1');

        $this->assertSame('sent', $result->status);
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Managed adapter carried it.');
        $this->assertCount(0, $this->telnyxMessageRequests());
        $this->assertSame('managed', DB::table('business_messaging_operations')->where('operation_key', 'managed-1')->value('transport_mode'));
    }

    // ------------------------------------------------------------------ no silent fallback

    public function test_a_rejected_key_fails_the_send_is_remembered_and_never_falls_back_to_managed(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeTelnyx(401);

        $first = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'byo-bad-1');

        $this->assertSame('failed', $first->status);
        $this->assertSame('invalid_api_key', $channel->fresh()->last_error_code);

        // Now the connection is reported not ready: no further provider call at all.
        $second = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello again', 'byo-bad-2');

        $this->assertSame(['blocked', 'byo_invalid_api_key'], [$second->status, $second->reason]);
        $this->assertCount(1, $this->telnyxMessageRequests());
        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Never the platform-paid number.');
    }

    /** @dataProvider unusableStates */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableStates')]
    public function test_an_unusable_chosen_sender_is_refused_with_its_exact_reason_and_sends_nothing(array $channelState, string $reason): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, array_merge(['default' => true], $channelState));

        $result = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'byo-unusable');

        $this->assertSame(['blocked', $reason], [$result->status, $result->reason]);
        $this->assertCount(0, $this->telnyxMessageRequests());
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, DB::table('business_messaging_operations')->where('operation_key', 'byo-unusable')->count());
    }

    public static function unusableStates(): array
    {
        return [
            'switched off' => [['status' => AgencyProspectingChannel::STATUS_DISABLED], 'byo_channel_disabled'],
            'disconnected' => [['status' => AgencyProspectingChannel::STATUS_DISCONNECTED], 'byo_disconnected'],
            'never verified' => [['verified' => null], 'byo_unverified'],
        ];
    }

    public function test_a_chosen_sender_whose_credentials_were_erased_is_refused_not_replaced(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);
        DB::table('sending_servers')->where('id', $channel->sending_server_id)->update(['api_key' => null]);

        $result = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'byo-erased');

        $this->assertSame(['blocked', 'byo_missing_credentials'], [$result->status, $result->reason]);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    // ------------------------------------------------------------------ Conversations / CRM history

    public function test_an_accepted_byo_send_is_recorded_in_the_agencys_own_conversation_on_the_sender_it_used(): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => true]);

        $result = ManagedDispatchDelegate::attempt((int) $business->id, '12025551000', 'Reply text', 'conv-1', [], '1', 'plain', 'manual');

        $this->assertTrue($result->accepted);

        $box = ChatBox::query()->where('business_id', $business->id)->sole();
        $this->assertSame('12025552000', $box->from);
        $this->assertSame('12025551000', $box->to);
        $this->assertSame((int) $business->customer_id, (int) $box->user_id);
        $this->assertSame('Reply text', ChatBoxMessage::query()->where('box_id', $box->id)->sole()->message);
    }

    public function test_switching_the_default_sender_keeps_history_and_starts_new_threads_on_the_new_sender(): void
    {
        [, $business, $workspace] = $this->agency('Snap', '14155550123');
        $channel = $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => true]);

        ManagedDispatchDelegate::attempt((int) $business->id, '12025551000', 'via byo', 'sw-1', [], '1', 'plain', 'manual');
        $byoBox = ChatBox::query()->where('business_id', $business->id)->sole();

        // The agency switches back to its MotionGrove number.
        $channel->forceFill(['is_default_sender' => false])->save();
        ManagedDispatchDelegate::attempt((int) $business->id, '12025551000', 'via managed', 'sw-2', [], '1', 'plain', 'manual');

        $boxes = ChatBox::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $boxes);
        $this->assertSame('12025552000', $boxes[0]->from);
        $this->assertSame('14155550123', $boxes[1]->from);
        $this->assertSame($byoBox->id, $boxes[0]->id, 'The earlier conversation is untouched.');
        $this->assertSame('via byo', ChatBoxMessage::query()->where('box_id', $byoBox->id)->sole()->message);
        $this->assertSame('byo', DB::table('business_messaging_operations')->where('operation_key', 'sw-1')->value('transport_mode'));
        $this->assertSame('managed', DB::table('business_messaging_operations')->where('operation_key', 'sw-2')->value('transport_mode'));
        $this->assertSame($channel->sender_number, $boxes[0]->from, 'Number ownership did not move.');
    }

    public function test_a_retry_keeps_the_transport_its_conversation_started_on_whatever_the_default_is_now(): void
    {
        [, $business, $workspace] = $this->agency('Snap', '14155550123');
        $channel = $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => true]);

        ManagedDispatchDelegate::attempt((int) $business->id, '12025551000', 'first', 'rt-1', [], '1', 'plain', 'manual', 'uid-1');
        $byoBox = ChatBox::query()->where('business_id', $business->id)->sole();

        // Default flips to the managed number; a retry in the BYO thread still goes via BYO.
        $channel->forceFill(['is_default_sender' => false])->save();
        ManagedDispatchDelegate::attempt((int) $business->id, '12025551000', 'retry', 'rt-2', [], '1', 'plain', 'manual', 'uid-1', (int) $byoBox->id, true);

        $this->assertSame('byo', DB::table('business_messaging_operations')->where('operation_key', 'rt-2')->value('transport_mode'));
        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'Never the platform-paid number for a BYO thread.');

        // And the reverse: a managed thread is retried on managed even though BYO is now the default.
        ManagedDispatchDelegate::attempt((int) $business->id, '12025557777', 'managed first', 'rt-3', [], '1', 'plain', 'manual', 'uid-2');
        $managedBox = ChatBox::query()->where('business_id', $business->id)->where('to', '12025557777')->sole();
        $channel->forceFill(['is_default_sender' => true])->save();
        ManagedDispatchDelegate::attempt((int) $business->id, '12025557777', 'managed retry', 'rt-4', [], '1', 'plain', 'manual', 'uid-2', (int) $managedBox->id, true);

        $this->assertSame('managed', DB::table('business_messaging_operations')->where('operation_key', 'rt-4')->value('transport_mode'));
    }

    // ------------------------------------------------------------------ Automations

    public function test_automations_resolve_the_byo_number_and_the_core_send_goes_through_the_byo_dispatcher(): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => true]);

        $path = app(BusinessSmsSendingPath::class)->resolve($business);

        $this->assertSame(['originator' => '12025552000', 'sending_server' => null], $path);

        $campaigns = app(\App\Repositories\Contracts\CampaignRepository::class);
        $parts = app(BusinessSmsSendingPath::class)->parsePhone('12025551000');
        $sendData = [
            'business_id' => (int) $business->id, 'user_id' => (int) $business->customer_id, 'message' => 'Automation text',
            'sms_type' => 'plain', 'originator' => 'sender_id', 'sender_id' => $path['originator'],
        ];

        $validation = $campaigns->checkQuickSendValidation($sendData)->getData();
        $this->assertSame('success', $validation->status, $validation->message ?? '');

        $sendData['sender_id'] = $validation->sender_id;
        $sendData['sms_type'] = $validation->sms_type;
        $sendData['user'] = User::query()->find($validation->user_id);
        $sendData['country_code'] = $parts['country_code'];
        $sendData['recipient'] = $parts['recipient'];
        $sendData['region_code'] = $parts['region_code'];
        $campaign = new \App\Models\Campaigns();
        $campaign->business_id = (int) $business->id;

        $response = $campaigns->quickSend($campaign, $sendData)->getData();

        $this->assertContains($response->status, ['success', 'info']);
        $this->assertCount(1, $this->telnyxMessageRequests());
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_a_foreign_number_is_not_an_authorised_originator(): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => true]);
        [, , $otherWorkspace] = $this->agency('Other Co');
        $this->byoChannel($otherWorkspace, ['sender' => '12025553000', 'default' => true]);

        $validation = app(\App\Repositories\Contracts\CampaignRepository::class)->checkQuickSendValidation([
            'business_id' => (int) $business->id, 'user_id' => (int) $business->customer_id, 'message' => 'x',
            'sms_type' => 'plain', 'originator' => 'sender_id', 'sender_id' => '12025553000',
        ])->getData();

        $this->assertSame('error', $validation->status);
    }

    // ------------------------------------------------------------------ inbound

    public function test_a_signed_reply_lands_in_the_agencys_own_conversation_once_and_emits_the_domain_event(): void
    {
        Event::fake([InboundMessageReceived::class]);
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => true]);

        $payload = $this->receivedPayload($channel, 'Yes, tell me more', 'in-1');
        $this->signedWebhook($channel, $payload)->assertOk()->assertJson(['status' => 'accepted']);

        $box = ChatBox::query()->where('business_id', $business->id)->sole();
        $this->assertSame(['12025552000', '12025551000'], [$box->from, $box->to]);
        $message = ChatBoxMessage::query()->where('box_id', $box->id)->sole();
        $this->assertSame('Yes, tell me more', $message->message);

        $operation = DB::table('business_messaging_operations')->where('provider_message_id', 'in-1')->sole();
        $this->assertSame(['byo', 'inbound', 'delivered'], [$operation->transport_mode, $operation->direction, $operation->status]);
        $this->assertSame($channel->id, (int) $operation->agency_prospecting_channel_id);
        $this->assertNotNull($channel->fresh()->webhook_verified_at);

        Event::assertDispatched(InboundMessageReceived::class, fn ($e) => $e->businessId === (int) $business->id && $e->senderPhone === '+12025551000');

        // The provider redelivers: nothing is processed twice.
        $this->signedWebhook($channel, $payload)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertSame(1, ChatBoxMessage::query()->where('box_id', $box->id)->count());
        Event::assertDispatchedTimes(InboundMessageReceived::class, 1);
    }

    public function test_a_stop_reply_opts_the_number_out_of_the_agencys_own_business_only(): void
    {
        [, $business, $workspace] = $this->agency();
        [, $otherBusiness] = $this->agency('Other Co');
        $channel = $this->byoChannel($workspace, ['default' => true]);

        $this->signedWebhook($channel, $this->receivedPayload($channel, 'STOP', 'in-stop'))->assertOk();

        $this->assertSame(1, Blacklists::query()->where('business_id', $business->id)->where('number', '12025551000')->count());
        $this->assertSame(0, Blacklists::query()->where('business_id', $otherBusiness->id)->count());

        $send = app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'after-stop');
        $this->assertSame(['blocked', 'opted_out'], [$send->status, $send->reason]);
        $this->assertCount(0, $this->telnyxMessageRequests());
    }

    public function test_webhook_authentication_rejects_everything_that_is_not_this_agencys_own_signed_event(): void
    {
        [, $business, $workspace] = $this->agency();
        [, , $otherWorkspace] = $this->agency('Other Co');
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $other = $this->byoChannel($otherWorkspace, ['default' => true]);
        $payload = $this->receivedPayload($channel, 'hi', 'in-auth');

        // Signed with ANOTHER agency's key.
        $this->signedWebhook($channel, $payload, $this->byoSecretKeys[(int) $other->id])->assertStatus(403);
        // Signed by nobody.
        $unsigned = $this->call('POST', route('prospecting.webhooks.agency_telnyx', [$channel->uid, \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($channel->uid)]), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $unsigned->assertStatus(403);
        // Wrong URL token / unknown channel: indistinguishable bare 404s.
        $this->signedWebhook($channel, $payload, null, 'not-the-token')->assertStatus(404);
        $this->signedWebhook($channel, $payload, null, null, 'no-such-channel')->assertStatus(404);
        // A valid signature of another agency's key on THEIR url cannot be replayed on ours.
        $this->signedWebhook($other, $this->receivedPayload($other, 'hi', 'in-auth-2'), $this->byoSecretKeys[(int) $channel->id])->assertStatus(403);

        $this->assertSame(0, ChatBox::query()->count());
        $this->assertSame(0, DB::table('business_messaging_operations')->where('direction', 'inbound')->count());
        $this->assertNull($channel->fresh()->webhook_verified_at);
    }

    public function test_a_channel_without_a_stored_webhook_key_authenticates_nothing(): void
    {
        [, , $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true, 'webhook_key' => false]);

        $this->signedWebhook($channel, $this->receivedPayload($channel, 'hi', 'in-nokey'), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))->assertStatus(403);
        $this->assertSame(0, ChatBox::query()->count());
    }

    public function test_a_signed_event_for_a_number_or_profile_that_is_not_this_channels_is_attributed_to_nobody(): void
    {
        [, , $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => true]);

        $this->signedWebhook($channel, $this->receivedPayload($channel, 'hi', 'in-x1', '+12025551000', null, '+12025559999'))
            ->assertOk()->assertJson(['status' => 'unattributed']);
        $this->signedWebhook($channel, $this->receivedPayload($channel, 'hi', 'in-x2', '+12025551000', 'someone-elses-profile'))
            ->assertOk()->assertJson(['status' => 'unattributed']);

        $this->assertSame(0, ChatBox::query()->count());
        $this->assertSame(0, DB::table('business_messaging_operations')->where('direction', 'inbound')->count());
    }

    public function test_replies_keep_arriving_on_an_old_sender_after_the_default_changes(): void
    {
        [, $business, $workspace] = $this->agency();
        $old = $this->byoChannel($workspace, ['sender' => '12025552000', 'default' => false]);

        $this->signedWebhook($old, $this->receivedPayload($old, 'late reply', 'in-old'))->assertOk()->assertJson(['status' => 'accepted']);

        $this->assertSame('12025552000', ChatBox::query()->where('business_id', $business->id)->sole()->from);
    }

    // ------------------------------------------------------------------ delivery events

    public function test_a_delivery_callback_moves_only_the_operation_this_channel_sent(): void
    {
        [, $business, $workspace] = $this->agency();
        [, $otherBusiness, $otherWorkspace] = $this->agency('Other Co');
        $channel = $this->byoChannel($workspace, ['default' => true]);
        $other = $this->byoChannel($otherWorkspace, ['default' => true]);

        app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'dlr-1');
        app(OutreachMessageSender::class)->send($otherBusiness, '12025551000', 'Hello', 'dlr-other');
        $mine = DB::table('business_messaging_operations')->where('operation_key', 'dlr-1')->value('provider_message_id');
        $theirs = DB::table('business_messaging_operations')->where('operation_key', 'dlr-other')->value('provider_message_id');
        $this->assertNotSame($mine, $theirs);

        // The OTHER agency, with its own valid signature, names MY message id: refused, nothing moves.
        $this->signedWebhook($other, $this->deliveryPayload($other, $mine, 'delivered'));
        $this->assertSame('accepted', DB::table('business_messaging_operations')->where('operation_key', 'dlr-1')->value('status'));

        // My own channel delivers my message.
        $this->signedWebhook($channel, $this->deliveryPayload($channel, $mine, 'delivered'))->assertOk()->assertJson(['status' => 'accepted']);
        $this->assertSame('delivered', DB::table('business_messaging_operations')->where('operation_key', 'dlr-1')->value('status'));
        $this->assertSame('accepted', DB::table('business_messaging_operations')->where('operation_key', 'dlr-other')->value('status'));

        // Replay is a no-op.
        $this->signedWebhook($channel, $this->deliveryPayload($channel, $mine, 'delivered'))->assertOk()->assertJson(['status' => 'duplicate']);
    }

    public function test_a_delivery_failure_marks_the_agencys_conversation_bubble_failed(): void
    {
        [, $business, $workspace] = $this->agency();
        $channel = $this->byoChannel($workspace, ['default' => true]);

        ManagedDispatchDelegate::attempt((int) $business->id, '12025551000', 'Will fail', 'dlr-fail', [], '1', 'plain', 'manual', 'send-uid-1');
        $providerId = DB::table('business_messaging_operations')->where('operation_key', 'dlr-fail')->value('provider_message_id');

        $this->signedWebhook($channel, $this->deliveryPayload($channel, $providerId, 'delivery_failed'))->assertOk();

        $this->assertSame('failed', DB::table('business_messaging_operations')->where('operation_key', 'dlr-fail')->value('status'));
        $this->assertSame('delivery_failed', ChatBoxMessage::query()->where('send_uid', 'send-uid-1')->value('send_status'));
    }

    public function test_the_managed_platform_endpoint_cannot_move_a_byo_operation(): void
    {
        [, $business, $workspace] = $this->agency();
        $this->byoChannel($workspace, ['default' => true]);
        app(OutreachMessageSender::class)->send($business, '12025551000', 'Hello', 'wall-1');
        $providerId = DB::table('business_messaging_operations')->where('operation_key', 'wall-1')->value('provider_message_id');

        // A validly "platform-signed" delivery event (the fake adapter accepts the signature)
        // naming that BYO operation's provider id.
        $this->postJson(route('inbound.telnyx_managed'), [
            'kind' => 'delivery_status', 'provider_message_id' => $providerId, 'delivery_status' => 'delivered',
        ]);

        $this->assertSame('accepted', DB::table('business_messaging_operations')->where('operation_key', 'wall-1')->value('status'));
    }

    // ------------------------------------------------------------------ isolation of who may use BYO at all

    public function test_only_the_agencys_own_business_can_ever_use_a_byo_sender(): void
    {
        [, $business, $workspace] = $this->agency();
        [, $otherBusiness] = $this->agency('Other Co');
        $channel = $this->byoChannel($workspace, ['default' => true]);

        $this->assertSame($channel->id, AgencyByoSender::selectedChannel($business)?->id);
        $this->assertNull(AgencyByoSender::selectedChannel($otherBusiness), 'Another agency never sees it.');

        // Dispatching a foreign channel for another Business is refused outright.
        $this->expectException(\App\Library\Messaging\Exceptions\ByoSenderNotReadyException::class);
        app(\App\Library\Messaging\ByoMessageDispatcher::class)->dispatch($otherBusiness, $channel, '12025551000', 'x', 'foreign-1');
    }
}
