<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Models\AgencyProspectingChannel;
use App\Models\BusinessVoiceBrowserCredential;
use App\Models\BusinessVoiceCall;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\AgencyOutreach\Concerns\BuildsAgencyByoChannels;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Outbound browser calling through an Agency's OWN Telnyx account. Telnyx is mocked end to end: no call is ever placed and
 * no real credential exists. The platform's own Telnyx settings are fully configured throughout, so any request that
 * wrongly used them (a silent fallback to MotionGrove-paid voice) is caught by the key / connection assertions.
 */
class AgencyByoVoiceCallingTest extends TestCase
{
    use BuildsAgencyByoChannels;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const PLATFORM_KEY = 'platform_key_fixture_never_for_agencies';
    private const AGENCY_KEY = 'agency_key_fixture_owned_by_agency';
    private const PLATFORM_CONN = 'platform_cc_conn';
    private const PLATFORM_WEBRTC = 'platform_webrtc_conn';
    private const BYO_CONN = 'byo_cc_conn_1';
    private const BYO_WEBRTC = 'byo_webrtc_conn_1';
    private const BYO_NUMBER = '+14155550142';
    private const PHONE = '14155552671';
    private const JWT = 'aaaa1111.bbbb2222.cccc3333';

    private $customer;
    private $business;
    private $workspace;
    private $contact;
    private AgencyProspectingChannel $channel;
    private int $legSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'messaging.voice.customer_calls.enabled' => true,
            'messaging.voice.browser.enabled' => true,
            'messaging.voice.browser.credential_connection_id' => self::PLATFORM_WEBRTC,
            'messaging.voice.connection_id' => self::PLATFORM_CONN,
            'messaging.voice.webhook_url' => 'https://app.example.test/webhooks/telnyx-voice',
            'services.telnyx.mode' => 'live',
            'services.telnyx.api_key' => self::PLATFORM_KEY,
            'services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
            // Voice webhook URLs must be https to be usable.
            'app.url' => 'https://app.example.test',
        ]);
        \Illuminate\Support\Facades\URL::forceScheme('https');

        [$this->customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
        \DB::table('businesses')->where('id', $this->business->id)->update(['status' => \App\Enums\Business\BusinessStatus::Active->value]);
        $this->business = $this->business->fresh();

        $group = ContactGroups::create(['customer_id' => $this->customer->user_id, 'name' => 'g', 'status' => true]);
        $this->contact = Contacts::create(['customer_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'group_id' => $group->id, 'phone' => self::PHONE, 'status' => 'subscribe']);
        $this->authenticateAs($this->customer);

        $this->channel = $this->readyVoiceChannel($this->workspace);

        Http::fake(['api.telnyx.com/v2/*' => function (Request $r) {
            $url = $r->url();
            if (str_ends_with($url, '/telephony_credentials')) {
                return Http::response(['data' => ['id' => 'cred_byo_1', 'sip_username' => 'byocredabc', 'sip_password' => 'SECRET_SIP_PASSWORD']], 201);
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

    /** A connected Telnyx channel with every voice step verified and voice turned on. */
    private function readyVoiceChannel($workspace, array $o = []): AgencyProspectingChannel
    {
        $channel = $this->byoChannel($workspace, ['key' => $o['key'] ?? self::AGENCY_KEY]);
        $channel->forceFill([
            'voice_enabled' => true,
            'voice_connection_id' => $o['conn'] ?? self::BYO_CONN,
            'voice_webrtc_connection_id' => $o['webrtc'] ?? self::BYO_WEBRTC,
            'voice_number' => $o['number'] ?? self::BYO_NUMBER,
            'voice_connection_verified_at' => now(),
            'voice_webrtc_verified_at' => now(),
            'voice_number_verified_at' => now(),
        ])->save();

        return $channel->fresh();
    }

    private function url(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.calls.' . $name, [$this->workspace->uid, $this->business->uid, ...$extra]);
    }

    private function placeBrowser(string $key = 'req-key-0001', array $extra = [])
    {
        return $this->postJson($this->url('store'), ['contact_uid' => $this->contact->uid, 'idempotency_key' => $key, 'channel' => 'browser'] + $extra);
    }

    /** @return list<Request> */
    private function sent(callable $filter): array
    {
        return array_values(array_filter(array_map(fn ($pair) => $pair[0], Http::recorded()->all()), $filter));
    }

    private function agentDial(): Request
    {
        return $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls') && str_starts_with((string) $r['to'], 'sip:'))[0];
    }

    private function byoWebhook(AgencyProspectingChannel $channel, string $eventId, string $type, string $callId, ?string $clientState = null, ?string $secret = null, ?string $token = null)
    {
        $payload = ['call_control_id' => $callId] + ($clientState !== null ? ['client_state' => $clientState] : []);
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'occurred_at' => now()->toIso8601String(), 'payload' => $payload]]);
        $ts = (string) time();
        $sig = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $secret ?? $this->byoSecretKeys[(int) $channel->id]));

        return $this->call('POST', route('voice.webhooks.agency_telnyx', [
            $channel->uid, $token ?? \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($channel->uid),
        ]), [], [], [], [
            'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function assertNoPlatformCredentialUsed(): void
    {
        $this->assertSame([], $this->sent(fn (Request $r) => $r->hasHeader('Authorization') && str_contains($r->header('Authorization')[0], self::PLATFORM_KEY)));
        $this->assertSame([], $this->sent(fn (Request $r) => in_array($r['connection_id'] ?? null, [self::PLATFORM_CONN, self::PLATFORM_WEBRTC], true)));
    }

    // ------------------------------------------------------------------ the happy path, on the agency's account

    public function test_a_byo_browser_call_uses_the_agencys_key_voice_connection_webrtc_connection_and_number(): void
    {
        $session = $this->postJson($this->url('browser-session'))->assertOk();
        $this->assertSame(self::JWT, $session->json('data.login_token'));
        $this->assertStringNotContainsString(self::AGENCY_KEY, $session->getContent());
        $this->assertStringNotContainsString('SECRET_SIP_PASSWORD', $session->getContent());

        $cred = $this->sent(fn (Request $r) => str_ends_with($r->url(), '/telephony_credentials'))[0];
        $this->assertSame(self::BYO_WEBRTC, $cred['connection_id']);
        $this->assertSame('Bearer ' . self::AGENCY_KEY, $cred->header('Authorization')[0]);
        $this->assertSame((int) $this->channel->id, (int) BusinessVoiceBrowserCredential::firstOrFail()->agency_prospecting_channel_id);

        $response = $this->placeBrowser()->assertStatus(202)->assertJsonPath('data.audio.browser', true);
        $this->assertStringNotContainsString(self::AGENCY_KEY, $response->getContent());

        $dial = $this->agentDial();
        $this->assertSame(self::BYO_CONN, $dial['connection_id']);
        $this->assertSame(self::BYO_NUMBER, $dial['from']);
        $this->assertSame('Bearer ' . self::AGENCY_KEY, $dial->header('Authorization')[0]);
        $this->assertSame(route('voice.webhooks.agency_telnyx', [$this->channel->uid, \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($this->channel->uid)]), $dial['webhook_url']);

        $call = BusinessVoiceCall::firstOrFail();
        $this->assertSame((int) $this->channel->id, (int) $call->agency_prospecting_channel_id);
        $this->assertNull($call->business_messaging_number_id);
        $this->assertNoPlatformCredentialUsed();
    }

    public function test_signed_agency_webhooks_drive_dial_bridge_status_and_hangup_on_the_agencys_account(): void
    {
        $this->postJson($this->url('browser-session'))->assertOk();
        $this->placeBrowser()->assertStatus(202);
        $call = BusinessVoiceCall::firstOrFail();
        $state = $this->agentDial()['client_state'];

        $this->byoWebhook($this->channel, 'ev-1', 'call.answered', 'agent-1', $state)->assertOk();
        $contactDial = $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls') && str_starts_with((string) $r['to'], '+'))[0];
        $this->assertSame(self::BYO_CONN, $contactDial['connection_id']);
        $this->assertSame(self::BYO_NUMBER, $contactDial['from']);
        $this->assertSame('Bearer ' . self::AGENCY_KEY, $contactDial->header('Authorization')[0]);

        $this->byoWebhook($this->channel, 'ev-2', 'call.answered', 'contact-2', $state)->assertOk();
        $bridge = $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls/agent-1/actions/bridge'));
        $this->assertCount(1, $bridge);
        $this->assertSame('Bearer ' . self::AGENCY_KEY, $bridge[0]->header('Authorization')[0]);
        $this->assertSame('answered', $call->fresh()->status);

        $this->postJson($this->url('hangup', [$call->uid]))->assertOk()->assertJsonPath('data.status', 'ended');
        $hangups = $this->sent(fn (Request $r) => str_ends_with($r->url(), '/actions/hangup'));
        $this->assertNotEmpty($hangups);
        foreach ($hangups as $request) {
            $this->assertSame('Bearer ' . self::AGENCY_KEY, $request->header('Authorization')[0]);
        }

        $this->assertNotNull($this->channel->fresh()->voice_webhook_verified_at);
        $this->assertNoPlatformCredentialUsed();
    }

    public function test_a_replayed_agency_event_is_applied_once(): void
    {
        $this->postJson($this->url('browser-session'))->assertOk();
        $this->placeBrowser()->assertStatus(202);
        $state = $this->agentDial()['client_state'];

        $this->byoWebhook($this->channel, 'ev-dup', 'call.answered', 'agent-1', $state)->assertOk();
        $this->byoWebhook($this->channel, 'ev-dup', 'call.answered', 'agent-1', $state)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertCount(1, $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls') && str_starts_with((string) $r['to'], '+')));
    }

    public function test_switching_the_webrtc_connection_never_reuses_a_credential_from_the_old_one(): void
    {
        $this->postJson($this->url('browser-session'))->assertOk();
        $old = BusinessVoiceBrowserCredential::firstOrFail();
        $this->assertSame(self::BYO_WEBRTC, $old->webrtc_connection_id);

        // Connection A -> B, bypassing the setup screen's own purge: the binding alone must protect the session.
        $this->channel->forceFill(['voice_webrtc_connection_id' => 'byo_webrtc_conn_B'])->save();

        // The stale credential is not accepted for dialling the browser...
        $this->placeBrowser('req-key-0003')->assertStatus(409)->assertJsonPath('error.code', 'browser_session_required');
        $this->assertSame([], $this->sent(fn (Request $r) => str_ends_with($r->url(), '/calls')));

        // ...and a new session mints a fresh credential on B (the old Telnyx credential is not reused).
        $this->postJson($this->url('browser-session'))->assertOk();
        $creds = $this->sent(fn (Request $r) => str_ends_with($r->url(), '/telephony_credentials'));
        $this->assertCount(2, $creds);
        $this->assertSame('byo_webrtc_conn_B', $creds[1]['connection_id']);
        $row = BusinessVoiceBrowserCredential::firstOrFail();
        $this->assertSame('byo_webrtc_conn_B', $row->webrtc_connection_id);
        $this->assertSame(1, BusinessVoiceBrowserCredential::count());

        $this->placeBrowser('req-key-0004')->assertStatus(202);
        $this->assertSame(self::BYO_CONN, $this->agentDial()['connection_id']);
    }

    // ------------------------------------------------------------------ tenant isolation

    public function test_a_foreign_agencys_signed_event_cannot_touch_this_agencys_call(): void
    {
        $this->postJson($this->url('browser-session'))->assertOk();
        $this->placeBrowser()->assertStatus(202);
        $call = BusinessVoiceCall::firstOrFail();
        $state = $this->agentDial()['client_state'];

        ['customer' => $otherCustomer, 'workspace' => $otherWorkspace] = $this->createIndependentWorkspaceBusiness();
        $foreign = $this->readyVoiceChannel($otherWorkspace, ['key' => 'foreign_agency_key', 'conn' => 'foreign_cc', 'webrtc' => 'foreign_wr', 'number' => '+14155550199']);

        // Validly signed by the OTHER agency, naming this call's leg id AND its secret client_state: still not applied.
        $before = count(Http::recorded());
        $this->byoWebhook($foreign, 'ev-x', 'call.answered', 'agent-1', $state)->assertOk()->assertJsonPath('status', 'ignored');
        $this->byoWebhook($foreign, 'ev-y', 'call.hangup', 'agent-1')->assertOk();
        $this->assertCount($before, Http::recorded(), 'No Telnyx command may follow a foreign event.');
        $this->assertSame('requested', $call->fresh()->status);

        // This agency's channel URL with the other agency's signature is rejected outright.
        $this->byoWebhook($this->channel, 'ev-z', 'call.answered', 'agent-1', $state, $this->byoSecretKeys[(int) $foreign->id])->assertStatus(403);
        $this->assertSame('requested', $call->fresh()->status);
    }

    public function test_the_platform_webhook_never_applies_an_agency_call(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config(['services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $this->postJson($this->url('browser-session'))->assertOk();
        $this->placeBrowser()->assertStatus(202);
        $call = BusinessVoiceCall::firstOrFail();
        $state = $this->agentDial()['client_state'];

        $body = json_encode(['data' => ['id' => 'ev-p', 'event_type' => 'call.answered', 'occurred_at' => now()->toIso8601String(), 'payload' => ['call_control_id' => 'agent-1', 'client_state' => $state]]]);
        $ts = (string) time();
        $this->call('POST', route('webhooks.telnyx_voice'), [], [], [], [
            'HTTP_TELNYX_SIGNATURE_ED25519' => base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, sodium_crypto_sign_secretkey($pair))),
            'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame('requested', $call->fresh()->status);
    }

    public function test_the_agency_webhook_rejects_a_wrong_token_unknown_uid_and_bad_signature(): void
    {
        $this->byoWebhook($this->channel, 'e1', 'call.answered', 'agent-1', null, null, 'wrong-token')->assertNotFound();
        $this->assertSame(404, $this->call('POST', route('voice.webhooks.agency_telnyx', ['nope', \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel('nope')]))->getStatusCode());
        $this->byoWebhook($this->channel, 'e2', 'call.answered', 'agent-1', null, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))->assertStatus(403);
    }

    public function test_a_calling_number_token_from_another_business_is_refused(): void
    {
        $this->postJson($this->url('browser-session'))->assertOk();

        $this->placeBrowser('req-key-0002', ['from' => str_repeat('a', 32)])->assertStatus(422)->assertJsonPath('error.code', 'number_not_available');
        $this->assertSame(0, BusinessVoiceCall::count());
    }

    // ------------------------------------------------------------------ never a silent fallback

    /** @dataProvider blockedStates */
    public function test_an_unusable_byo_connection_blocks_calling_with_an_actionable_error_and_no_fallback(string $state): void
    {
        $this->postJson($this->url('browser-session'))->assertOk();
        Http::fake(); // from here, any Telnyx request at all is a failure of the block
        $sentBefore = count(Http::recorded());
        match ($state) {
            'unverified_number' => $this->channel->forceFill(['voice_number_verified_at' => null])->save(),
            'unverified_voice_connection' => $this->channel->forceFill(['voice_connection_verified_at' => null])->save(),
            'unverified_webrtc' => $this->channel->forceFill(['voice_webrtc_verified_at' => null])->save(),
            'no_webhook_key' => $this->channel->forceFill(['webhook_public_key' => null])->save(),
            'voice_off_by_error' => $this->channel->forceFill(['voice_last_error_code' => 'x'])->save(),
            'disconnected' => $this->channel->forceFill(['status' => AgencyProspectingChannel::STATUS_DISCONNECTED])->save(),
            'account_unverified' => $this->channel->forceFill(['credentials_verified_at' => null])->save(),
        };

        $response = $this->placeBrowser()->assertStatus(409)->assertJsonPath('error.code', 'byo_voice_unavailable');
        $this->assertStringNotContainsString(self::AGENCY_KEY, $response->getContent());
        $this->assertNotSame('', (string) $response->json('error.message'));
        $this->postJson($this->url('browser-session'))->assertStatus(409)->assertJsonPath('error.code', 'byo_voice_unavailable');

        $this->assertCount($sentBefore, Http::recorded(), 'A blocked BYO call must send nothing to Telnyx, platform or agency.');
        $this->assertSame(0, BusinessVoiceCall::count());
    }

    public static function blockedStates(): array
    {
        return [
            'unverified number' => ['unverified_number'], 'unverified voice connection' => ['unverified_voice_connection'],
            'unverified webrtc connection' => ['unverified_webrtc'], 'no webhook key' => ['no_webhook_key'],
            'telnyx error recorded' => ['voice_off_by_error'], 'disconnected' => ['disconnected'], 'account unverified' => ['account_unverified'],
        ];
    }

    public function test_the_missing_step_is_named_in_the_error(): void
    {
        $this->channel->forceFill(['voice_webrtc_connection_id' => null, 'voice_webrtc_verified_at' => null])->save();

        $this->placeBrowser()->assertStatus(409)
            ->assertJsonPath('error.code', 'byo_voice_unavailable')
            ->assertJsonFragment(['message' => 'Voice setup for your Telnyx account is incomplete. Missing or unverified: WebRTC (browser) credential connection. Finish it in Phone & Messaging channels.']);
    }

    // ------------------------------------------------------------------ the two modes stay separate

    public function test_numbers_offer_only_the_agencys_verified_voice_number_and_nothing_when_it_is_unusable(): void
    {
        $this->getJson($this->url('numbers'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.primary', true);

        $this->channel->forceFill(['voice_number_verified_at' => null])->save();
        $this->getJson($this->url('numbers'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_with_voice_off_the_business_uses_platform_managed_calling_unchanged(): void
    {
        $this->channel->forceFill(['voice_enabled' => false])->save();
        $number = $this->attachNumber($this->attachIdentity($this->business), $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Active, 'num_ref_1');
        \DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'number_type' => 'local', 'voice_connection_id' => self::PLATFORM_CONN, 'voice_assigned_at' => now(),
        ]);

        $this->postJson($this->url('browser-session'))->assertOk();
        $this->assertSame(self::PLATFORM_WEBRTC, $this->sent(fn (Request $r) => str_ends_with($r->url(), '/telephony_credentials'))[0]['connection_id']);
        $this->assertSame(0, (int) BusinessVoiceBrowserCredential::firstOrFail()->agency_prospecting_channel_id);

        $this->placeBrowser()->assertStatus(202);
        $dial = $this->agentDial();
        $this->assertSame(self::PLATFORM_CONN, $dial['connection_id']);
        $this->assertSame('Bearer ' . self::PLATFORM_KEY, $dial->header('Authorization')[0]);
        $this->assertNull(BusinessVoiceCall::firstOrFail()->agency_prospecting_channel_id);
        $this->assertSame([], $this->sent(fn (Request $r) => str_contains(json_encode($r->header('Authorization')), self::AGENCY_KEY)));
    }

    public function test_a_failed_telnyx_dial_shows_a_useful_error_without_leaking_credentials(): void
    {
        $this->postJson($this->url('browser-session'))->assertOk();
        Http::fake(['api.telnyx.com/v2/calls' => Http::response(['errors' => [['code' => '10010', 'detail' => 'Bearer ' . self::AGENCY_KEY . ' invalid']]], 401)]);

        $response = $this->placeBrowser()->assertStatus(502);
        $this->assertSame('failed', $response->json('data.status'));
        $this->assertStringNotContainsString(self::AGENCY_KEY, $response->getContent());
    }
}
