<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Models\Blacklists;
use App\Models\BusinessVoiceCall;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/** Calling from Conversations: linked contacts and conversations whose number is not a contact. Telnyx is mocked. */
class ConversationUnlinkedCallTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use CreatesTimelineFixtures;
    use RefreshDatabase;

    private const CONN = 'conn_callcontrol_1';
    private const CUSTOMER_PHONE = '19708250000';

    private $customer;
    private $business;
    private $workspace;
    private $number;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        User::create(['first_name' => 'Placeholder', 'last_name' => 'SuperAdmin', 'email' => 'ph' . uniqid('', true) . '@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        $pair = sodium_crypto_sign_keypair();
        config([
            'messaging.voice.customer_calls.enabled' => true,
            'messaging.voice.browser.enabled' => true,
            'messaging.voice.browser.credential_connection_id' => 'conn_webrtc_1',
            'messaging.voice.connection_id' => self::CONN,
            'messaging.voice.webhook_url' => 'https://app.example.test/webhooks/telnyx-voice',
            'services.telnyx.mode' => 'live',
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ]);

        [$this->customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($this->business);
        $this->number = $this->attachNumber($identity, $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Active, 'num_ref_1');
        \DB::table('business_messaging_numbers')->where('id', $this->number->id)->update(['number_type' => 'local', 'voice_connection_id' => self::CONN, 'voice_assigned_at' => now()]);
        $this->authenticateAs($this->customer);

        Http::fake(['api.telnyx.com/v2/*' => function (Request $r) {
            $url = $r->url();
            if (str_ends_with($url, '/telephony_credentials')) {
                return Http::response(['data' => ['id' => 'cred_1', 'sip_username' => 'gencredabc']], 201);
            }
            if (str_ends_with($url, '/token')) {
                return Http::response('aaaa1111.bbbb2222.cccc3333', 201);
            }
            if (str_contains($url, '/actions/')) {
                return Http::response(['data' => ['result' => 'ok']], 200);
            }
            if (str_ends_with($url, '/calls')) {
                $this->seq++;

                return Http::response(['data' => ['call_control_id' => (str_starts_with((string) $r['to'], 'sip:') ? 'agent-' : 'contact-') . $this->seq]], 200);
            }

            return Http::response([], 404);
        }]);
    }

    private function url(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.calls.' . $name, [$this->workspace->uid, $this->business->uid, ...$extra]);
    }

    private function place(array $target, string $key = 'conv-key-0001')
    {
        $this->postJson($this->url('browser-session'))->assertOk();

        return $this->postJson($this->url('store'), $target + ['idempotency_key' => $key, 'channel' => 'browser']);
    }

    /** @return list<Request> */
    private function dials(): array
    {
        return array_values(array_filter(array_map(fn ($p) => $p[0], Http::recorded()->all()), fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls')));
    }

    private function timeline($box)
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$this->workspace->uid, $this->business->uid, $box->uid]));
    }

    public function test_an_unlinked_conversation_number_can_be_called_without_creating_a_contact(): void
    {
        $box = $this->conversationWith($this->business, self::CUSTOMER_PHONE);

        $this->place(['conversation_uid' => $box->uid])->assertStatus(202);

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertNull($call->contact_id);
        $this->assertSame('+' . self::CUSTOMER_PHONE, $call->caller_number);
        $this->assertSame(0, Contacts::query()->where('business_id', $this->business->id)->count(), 'No fake contact was created.');
        $this->assertCount(1, $this->dials(), 'Only the agent browser leg is rung first.');
        $this->assertStringStartsWith('sip:', (string) $this->dials()[0]['to']);

        // When the browser answers, the CUSTOMER's number is dialled from the Business's number (never the other way round).
        $clientState = $this->dials()[0]['client_state'];
        $this->webhook('evt-a', 'call.answered', 'agent-1', $clientState)->assertOk();
        $contactDial = $this->dials()[1];
        $this->assertSame('+' . self::CUSTOMER_PHONE, $contactDial['to']);
        $this->assertSame($this->number->phone_number, $contactDial['from']);
    }

    public function test_a_conversation_whose_number_is_a_contact_is_called_as_that_contact(): void
    {
        $group = ContactGroups::create(['customer_id' => $this->customer->user_id, 'name' => 'g', 'status' => true]);
        $contact = Contacts::create(['customer_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'group_id' => $group->id, 'phone' => self::CUSTOMER_PHONE, 'status' => 'subscribe']);
        $box = $this->conversationWith($this->business, self::CUSTOMER_PHONE);

        $this->place(['conversation_uid' => $box->uid])->assertStatus(202);

        $this->assertSame($contact->id, BusinessVoiceCall::firstOrFail()->contact_id);
        $this->assertNull(BusinessVoiceCall::firstOrFail()->caller_number);
    }

    public function test_existing_contact_calls_are_unchanged_and_a_request_needs_a_target(): void
    {
        $group = ContactGroups::create(['customer_id' => $this->customer->user_id, 'name' => 'g', 'status' => true]);
        $contact = Contacts::create(['customer_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'group_id' => $group->id, 'phone' => self::CUSTOMER_PHONE, 'status' => 'subscribe']);

        $this->place(['contact_uid' => $contact->uid])->assertStatus(202);
        $this->postJson($this->url('store'), ['idempotency_key' => 'conv-key-0002'])->assertStatus(422);
    }

    public function test_authorization_tenancy_blocklist_and_limits_still_apply(): void
    {
        $box = $this->conversationWith($this->business, self::CUSTOMER_PHONE);

        // A conversation of another Business is not found.
        [, $otherBusiness] = $this->tenant(WorkspacePlanTier::Growth);
        $foreign = $this->conversationWith($otherBusiness, self::CUSTOMER_PHONE);
        $this->place(['conversation_uid' => $foreign->uid])->assertStatus(404)->assertJsonPath('error.code', 'conversation_not_found');
        $this->assertSame(0, BusinessVoiceCall::count());

        // A blocked number is refused.
        Blacklists::create(['user_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'number' => self::CUSTOMER_PHONE, 'reason' => 'x']);
        $this->postJson($this->url('store'), ['conversation_uid' => $box->uid, 'idempotency_key' => 'conv-key-0003', 'channel' => 'browser'])->assertStatus(403)->assertJsonPath('error.code', 'contact_blocked');
        Blacklists::query()->delete();

        // Without the chat_box permission a conversation cannot be used to dial.
        $this->authenticateAs($this->customer, ['place_voice_calls']);
        $this->postJson($this->url('store'), ['conversation_uid' => $box->uid, 'idempotency_key' => 'conv-key-0004', 'channel' => 'browser'])->assertStatus(404);
        $this->assertSame(0, BusinessVoiceCall::count());

        // Calling switched off: refused with the existing message.
        $this->authenticateAs($this->customer);
        config(['messaging.voice.customer_calls.enabled' => false]);
        $this->postJson($this->url('store'), ['conversation_uid' => $box->uid, 'idempotency_key' => 'conv-key-0005', 'channel' => 'browser'])->assertStatus(503)->assertJsonPath('error.code', 'calling_unavailable');
    }

    public function test_the_conversation_offers_the_call_for_an_unlinked_number_only_when_calling_is_available(): void
    {
        $box = $this->conversationWith($this->business, self::CUSTOMER_PHONE);

        $call = $this->timeline($box)->assertOk()->json('call');
        $this->assertSame($box->uid, $call['conversation']);
        $this->assertSame('', $call['uid']);
        $this->assertSame('+' . self::CUSTOMER_PHONE, $call['phone']);

        config(['messaging.voice.browser.enabled' => false]);
        $this->assertNull($this->timeline($box)->assertOk()->json('call'));

        config(['messaging.voice.browser.enabled' => true]);
        Blacklists::create(['user_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'number' => self::CUSTOMER_PHONE, 'reason' => 'x']);
        $this->assertNull($this->timeline($box)->assertOk()->json('call'));
    }

    public function test_the_workspace_owner_can_call_without_granting_themselves_a_permission_but_staff_cannot(): void
    {
        $box = $this->conversationWith($this->business, self::CUSTOMER_PHONE);
        // Only ordinary back-office access: no explicit place_voice_calls grant.
        $this->authenticateAs($this->customer, ['chat_box']);

        $this->assertSame('+' . self::CUSTOMER_PHONE, $this->timeline($box)->assertOk()->json('call.phone'));
        $this->place(['conversation_uid' => $box->uid])->assertStatus(202);

        // The same user as a NON-owner (staff) still needs the explicit permission.
        \DB::table('workspaces')->where('id', $this->workspace->id)->update(['owner_user_id' => User::query()->orderBy('id')->value('id')]);
        $json = $this->timeline($box)->assertOk()->json();
        $this->assertNull($json['call']);
        $this->assertStringContainsString('place voice calls', $json['call_unavailable']);
        $before = BusinessVoiceCall::count();
        $this->assertGreaterThanOrEqual(400, $this->postJson($this->url('store'), ['conversation_uid' => $box->uid, 'idempotency_key' => 'staff-key-0001', 'channel' => 'browser'])->status());
        $this->assertSame($before, BusinessVoiceCall::count());

        // With the explicit permission, staff can.
        $this->authenticateAs($this->customer, ['chat_box', 'place_voice_calls']);
        $this->assertNotNull($this->timeline($box)->assertOk()->json('call'));
    }

    public function test_missing_telephony_configuration_is_named_precisely_without_leaking_values_or_bypassing_checks(): void
    {
        $box = $this->conversationWith($this->business, self::CUSTOMER_PHONE);
        config(['messaging.voice.browser.credential_connection_id' => '']);

        $json = $this->timeline($box)->assertOk()->json();
        $this->assertNull($json['call']);
        $this->assertStringContainsString('TELNYX_WEBRTC_CONNECTION_ID', $json['call_unavailable']);
        $this->assertStringNotContainsString('fixture_api_key', json_encode($json));

        config(['services.telnyx.mode' => 'sandbox']);
        $this->assertStringContainsString('live mode', $this->timeline($box)->json('call_unavailable'));
        // The safety check still stands: nothing can be placed.
        $this->postJson($this->url('store'), ['conversation_uid' => $box->uid, 'idempotency_key' => 'cfg-key-00001', 'channel' => 'browser'])->assertStatus(503);

        // A number that is not callable at all gets no calling message.
        $bad = $this->conversationWith($this->business, '123');
        $this->assertNull($this->timeline($bad)->json('call_unavailable'));
    }

    public function test_the_conversations_page_loads_the_softphone_for_outbound_calling_regardless_of_inbound_settings(): void
    {
        config(['messaging.voice.inbound.enabled' => false]);
        $html = $this->get(route('customer.workspaces.businesses.conversations.index', [$this->workspace->uid, $this->business->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('id="mg-softphone"', $html);
        $this->assertStringContainsString('js/crm/mg-softphone.js', $html);
    }

    private function webhook(string $eventId, string $type, string $callId, string $clientState)
    {
        $pair = sodium_crypto_sign_keypair();
        config(['services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'occurred_at' => now()->toIso8601String(), 'payload' => ['call_control_id' => $callId, 'client_state' => $clientState]]]);
        $ts = (string) time();
        $sig = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, sodium_crypto_sign_secretkey($pair)));

        return $this->call('POST', route('webhooks.telnyx_voice'), [], [], [], ['HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json'], $body);
    }
}
