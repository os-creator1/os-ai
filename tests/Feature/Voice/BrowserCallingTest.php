<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Models\BusinessVoiceBrowserCredential;
use App\Models\BusinessVoiceCall;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

class BrowserCallingTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const CONN = 'conn_callcontrol_1';
    private const WEBRTC_CONN = 'conn_webrtc_1';
    private const PHONE = '14155552671';
    private const JWT = 'aaaa1111.bbbb2222.cccc3333';

    private $customer;
    private $business;
    private $workspace;
    private $number;
    private $contact;
    private string $secret;
    private int $legSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);

        config([
            'messaging.voice.customer_calls.enabled' => true,
            'messaging.voice.browser.enabled' => true,
            'messaging.voice.browser.credential_connection_id' => self::WEBRTC_CONN,
            'messaging.voice.connection_id' => self::CONN,
            'messaging.voice.webhook_url' => 'https://app.example.test/webhooks/telnyx-voice',
            'services.telnyx.mode' => 'live',
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ]);

        [$this->customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($this->business);
        $this->number = $this->attachNumber($identity, $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Active, 'num_ref_1');
        \DB::table('business_messaging_numbers')->where('id', $this->number->id)->update([
            'number_type' => 'local', 'voice_connection_id' => self::CONN, 'voice_assigned_at' => now(),
        ]);
        $group = ContactGroups::create(['customer_id' => $this->customer->user_id, 'name' => 'g', 'status' => true]);
        $this->contact = Contacts::create(['customer_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'group_id' => $group->id, 'phone' => self::PHONE, 'status' => 'subscribe']);
        $this->authenticateAs($this->customer);

        // One dispatcher for every Telnyx endpoint the browser-call flow touches.
        Http::fake(['api.telnyx.com/v2/*' => function (Request $r) {
            $url = $r->url();
            if (str_ends_with($url, '/telephony_credentials')) {
                return Http::response(['data' => ['id' => 'cred_1', 'sip_username' => 'gencredabc', 'sip_password' => 'SECRET_SIP_PASSWORD']], 201);
            }
            if (str_ends_with($url, '/token')) {
                return Http::response(self::JWT, 201);
            }
            if (str_contains($url, '/actions/')) {
                return Http::response(['data' => ['result' => 'ok']], 200);
            }
            if (str_ends_with($url, '/calls')) {
                $this->legSeq++;

                return Http::response(['data' => ['call_control_id' => (str_starts_with((string) $r['to'], 'sip:') ? 'agent-' : 'contact-') . $this->legSeq]], 200);
            }

            return Http::response([], 404);
        }]);
    }

    private function url(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.calls.' . $name, [$this->workspace->uid, $this->business->uid, ...$extra]);
    }

    private function startBrowserSession()
    {
        return $this->postJson($this->url('browser-session'));
    }

    private function placeBrowser(string $key = 'req-key-0001')
    {
        return $this->postJson($this->url('store'), ['contact_uid' => $this->contact->uid, 'idempotency_key' => $key, 'channel' => 'browser']);
    }

    /** @return list<Request> */
    private function sent(callable $filter): array
    {
        return array_values(array_filter(array_map(fn ($pair) => $pair[0], Http::recorded()->all()), $filter));
    }

    private function webhook(string $eventId, string $type, string $callId, ?string $clientState = null, array $extra = [], ?string $occurredAt = null)
    {
        $payload = ['call_control_id' => $callId] + $extra + ($clientState !== null ? ['client_state' => $clientState] : []);
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'occurred_at' => $occurredAt, 'payload' => $payload]]);
        $ts = (string) time();
        $sig = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $this->secret));

        return $this->call('POST', route('webhooks.telnyx_voice'), [], [], [], [
            'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function clientStateOfAgentDial(): string
    {
        $dial = $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls') && str_starts_with((string) $r['to'], 'sip:'))[0];

        return $dial['client_state'];
    }

    // ------------------------------------------------------------------ credentials

    public function test_a_session_returns_only_a_login_token_and_reuses_the_credential(): void
    {
        $response = $this->startBrowserSession()->assertOk()->assertJsonPath('data.login_token', self::JWT);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET_SIP_PASSWORD', $body);
        $this->assertStringNotContainsString('fixture_api_key', $body);
        $this->assertStringNotContainsString('gencredabc', $body);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $credential = BusinessVoiceBrowserCredential::firstOrFail();
        $this->assertSame('gencredabc', $credential->sip_username);
        $this->assertSame(['sip_password'], array_values(array_diff(['sip_password'], array_keys($credential->getAttributes()))), 'The SIP password is never stored.');

        $this->startBrowserSession()->assertOk();
        $this->assertCount(1, $this->sent(fn (Request $r) => str_ends_with($r->url(), '/telephony_credentials')), 'The credential is created once and reused.');
        $this->assertSame(1, BusinessVoiceBrowserCredential::count());
    }

    public function test_a_session_is_refused_when_browser_calling_is_off_or_the_user_may_not_call(): void
    {
        config(['messaging.voice.browser.enabled' => false]);
        $this->startBrowserSession()->assertStatus(503)->assertJsonPath('error.code', 'browser_calling_unavailable');

        config(['messaging.voice.browser.enabled' => true]);
        $this->authenticateAs($this->customer, ['view_numbers']);
        $this->assertContains($this->startBrowserSession()->getStatusCode(), [401, 403]);
        $this->assertSame(0, BusinessVoiceBrowserCredential::count());
    }

    // ------------------------------------------------------------------ placing

    public function test_a_browser_call_needs_a_session_then_rings_the_browser_first(): void
    {
        $this->placeBrowser()->assertStatus(409)->assertJsonPath('error.code', 'browser_session_required');
        $this->assertSame(0, BusinessVoiceCall::count());

        $this->startBrowserSession()->assertOk();
        $response = $this->placeBrowser('req-key-0002')->assertStatus(202)
            ->assertJsonPath('data.channel', 'browser')->assertJsonPath('data.ui_state', 'connecting')->assertJsonPath('data.audio.browser', true);

        // Leg 1 is the AGENT's browser (SIP URI), from the business number; the contact is NOT dialled yet.
        $dials = $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls'));
        $this->assertCount(1, $dials);
        $this->assertSame('sip:gencredabc@sip.telnyx.com', $dials[0]['to']);
        $this->assertSame($this->number->phone_number, $dials[0]['from']);
        $this->assertSame(self::CONN, $dials[0]['connection_id']);
        $this->assertSame(690, $dials[0]['time_limit_secs']);

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('agent-1', $call->agent_call_control_id);
        $this->assertNull($call->telnyx_call_control_id);
        $this->assertStringNotContainsString('4155552671', $response->getContent());
    }

    public function test_a_second_browser_call_while_one_is_live_is_refused(): void
    {
        $this->startBrowserSession()->assertOk();
        $this->placeBrowser()->assertStatus(202);

        $other = Contacts::create(['customer_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'group_id' => $this->contact->group_id, 'phone' => '14155552672', 'status' => 'subscribe']);
        $this->postJson($this->url('store'), ['contact_uid' => $other->uid, 'idempotency_key' => 'req-key-0003', 'channel' => 'browser'])
            ->assertStatus(409)->assertJsonPath('error.code', 'already_on_a_call');
    }

    // ------------------------------------------------------------------ the two-leg flow

    public function test_the_full_flow_dials_the_contact_when_the_browser_answers_and_bridges_when_the_contact_answers(): void
    {
        $this->startBrowserSession();
        $uid = $this->placeBrowser()->assertStatus(202)->json('data.id');
        $state = $this->clientStateOfAgentDial();

        // The browser answers: only now is the contact dialled, with the same unguessable token.
        $this->webhook('e1', 'call.answered', 'agent-1', $state)->assertOk()->assertJson(['status' => 'applied']);
        $contactDials = $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls') && $r['to'] === '+14155552671');
        $this->assertCount(1, $contactDials);
        $this->assertSame($state, $contactDials[0]['client_state']);
        $this->assertSame($this->number->phone_number, $contactDials[0]['from']);
        $this->assertSame(600, $contactDials[0]['time_limit_secs']);
        $this->assertSame('initiated', BusinessVoiceCall::firstOrFail()->status);
        $this->assertSame('contact-2', BusinessVoiceCall::firstOrFail()->telnyx_call_control_id);

        // Redelivery of the same event neither dials nor changes anything.
        $this->webhook('e1', 'call.answered', 'agent-1', $state)->assertJson(['status' => 'duplicate']);
        $this->assertCount(1, $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls') && $r['to'] === '+14155552671'));

        // The contact answers: bridge agent <-> contact (this is the two-way audio).
        $this->webhook('e2', 'call.answered', 'contact-2', $state, [], '2026-11-12T10:00:08Z')->assertJson(['status' => 'applied']);
        $bridge = $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls/agent-1/actions/bridge'));
        $this->assertCount(1, $bridge);
        $this->assertSame('contact-2', $bridge[0]['call_control_id']);
        $this->getJson($this->url('show', [$uid]))->assertJsonPath('data.ui_state', 'connected');

        // Hang-up from the contact side closes the call with a duration and drops the browser leg.
        $this->webhook('e3', 'call.hangup', 'contact-2', $state, ['hangup_cause' => 'normal_clearing', 'end_time' => '2026-11-12T10:01:20Z'])->assertJson(['status' => 'applied']);
        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('ended', $call->status);
        $this->assertSame(72, $call->duration_seconds);
        $this->assertCount(1, $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls/agent-1/actions/hangup')));
        $this->getJson($this->url('show', [$uid]))->assertJsonPath('data.ui_state', 'ended')->assertJsonPath('data.duration_seconds', 72);
    }

    public function test_a_browser_that_never_answers_fails_the_call_and_the_contact_is_never_dialled(): void
    {
        $this->startBrowserSession();
        $this->placeBrowser();
        $state = $this->clientStateOfAgentDial();

        $this->webhook('e1', 'call.hangup', 'agent-1', $state, ['hangup_cause' => 'timeout'])->assertJson(['status' => 'applied']);

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('failed', $call->status);
        $this->assertSame('browser_not_answered', $call->failure_code);
        $this->assertCount(0, $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls') && $r['to'] === '+14155552671'));
    }

    public function test_a_contact_leg_that_outlives_a_dropped_browser_is_hung_up(): void
    {
        $this->startBrowserSession();
        $this->placeBrowser();
        $state = $this->clientStateOfAgentDial();

        // The browser drops before the contact leg's id was ever saved, so the call is already failed...
        $this->webhook('e1', 'call.hangup', 'agent-1', $state, ['hangup_cause' => 'originator_cancel'])->assertJson(['status' => 'applied']);
        $this->assertSame('failed', BusinessVoiceCall::firstOrFail()->status);

        // ...when the late contact leg reports in. It is adopted and torn down, never left ringing or answering silence.
        $this->webhook('e2', 'call.initiated', 'contact-late', $state)->assertOk();
        $this->assertCount(1, $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls/contact-late/actions/hangup')));
        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('contact-late', $call->telnyx_call_control_id);
        $this->assertSame('failed', $call->status, 'A terminated call is never revived.');
    }

    public function test_an_answer_reported_after_the_hangup_still_records_the_answer_time_and_duration(): void
    {
        $this->startBrowserSession();
        $this->placeBrowser();
        $state = $this->clientStateOfAgentDial();
        $this->webhook('e1', 'call.answered', 'agent-1', $state);

        $this->webhook('e2', 'call.hangup', 'contact-2', $state, ['end_time' => '2026-11-12T10:01:20Z']);
        $this->assertNull(BusinessVoiceCall::firstOrFail()->duration_seconds);

        $this->webhook('e3', 'call.answered', 'contact-2', $state, [], '2026-11-12T10:00:08Z');

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('ended', $call->status);
        $this->assertSame(72, $call->duration_seconds);
        $this->assertNotNull($call->answered_at);
    }

    public function test_an_event_with_the_wrong_token_never_touches_a_browser_call(): void
    {
        $this->startBrowserSession();
        $this->placeBrowser();

        $this->webhook('e1', 'call.answered', 'agent-9', base64_encode(str_repeat('a', 64)))->assertOk();

        $this->assertCount(0, $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls') && $r['to'] === '+14155552671'));
        $this->assertSame('requested', BusinessVoiceCall::firstOrFail()->status);
    }

    public function test_hang_up_from_the_crm_ends_both_legs_and_records_the_call(): void
    {
        $this->startBrowserSession();
        $uid = $this->placeBrowser()->json('data.id');
        $state = $this->clientStateOfAgentDial();
        $this->webhook('e1', 'call.answered', 'agent-1', $state);
        $this->webhook('e2', 'call.answered', 'contact-2', $state, [], '2026-11-12T10:00:08Z');

        $this->postJson($this->url('hangup', [$uid]))->assertOk()->assertJsonPath('data.ui_state', 'ended');

        $this->assertCount(1, $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls/agent-1/actions/hangup')));
        $this->assertCount(1, $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls/contact-2/actions/hangup')));
        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame('ended', $call->status);
        $this->assertSame('user_hangup', $call->hangup_cause);
        $this->assertNotNull($call->duration_seconds);
    }

    public function test_the_existing_server_call_flow_is_unchanged(): void
    {
        $response = $this->postJson($this->url('store'), ['contact_uid' => $this->contact->uid, 'idempotency_key' => 'req-key-0009'])->assertStatus(202)
            ->assertJsonPath('data.channel', 'server')->assertJsonPath('data.audio.browser', false);

        $dial = $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls'))[0];
        $this->assertSame('+14155552671', $dial['to']);
        $this->assertSame('contact-1', BusinessVoiceCall::firstOrFail()->telnyx_call_control_id);
    }

    // ------------------------------------------------------------------ CRM page

    public function test_the_contact_page_offers_a_call_button_only_when_calling_is_available(): void
    {
        $url = route('customer.workspaces.businesses.people.show', [$this->workspace->uid, $this->business->uid, $this->contact->uid]);

        $page = $this->get($url)->assertOk();
        $page->assertSee('data-role="contact-call"', false)->assertSee('id="mg-softphone"', false)->assertSee('webrtc-2.28.2.js', false);
        $this->assertStringNotContainsString('fixture_api_key', $page->getContent());

        config(['messaging.voice.browser.enabled' => false]);
        $this->get($url)->assertOk()->assertDontSee('data-role="contact-call"', false)->assertDontSee('id="mg-softphone"', false);
    }
}
