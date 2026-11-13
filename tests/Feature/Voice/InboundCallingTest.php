<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Library\Voice\Inbound\InboundCallRouter;
use App\Models\BusinessInboundCallSetting;
use App\Models\BusinessVoiceBrowserCredential;
use App\Models\BusinessVoiceCall;
use App\Models\BusinessVoiceCallLeg;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/** Inbound browser calls + forwarding, with Telnyx fully mocked (no network, no live calls). */
class InboundCallingTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;
    use RefreshDatabase;

    private const CONN = 'conn_callcontrol_1';
    private const CALLER = '14155552671';
    private const FORWARD = '+16505550142';

    private $customer;
    private $business;
    private $workspace;
    private $number;
    private $contact;
    private string $secret;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);

        [$this->customer, $this->business, $this->workspace] = $this->tenant(WorkspacePlanTier::Growth);
        config([
            'messaging.voice.customer_calls.enabled' => true,
            'messaging.voice.browser.enabled' => true,
            'messaging.voice.browser.credential_connection_id' => 'conn_webrtc_1',
            'messaging.voice.inbound.enabled' => true,
            'messaging.voice.connection_id' => self::CONN,
            'messaging.voice.webhook_url' => 'https://app.example.test/webhooks/telnyx-voice',
            'services.telnyx.mode' => 'live',
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'services.telnyx.webhook_public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
        ]);

        $identity = $this->attachIdentity($this->business);
        $this->number = $this->attachNumber($identity, $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Active, 'num_ref_1');
        \DB::table('business_messaging_numbers')->where('id', $this->number->id)->update([
            'number_type' => 'local', 'voice_connection_id' => self::CONN, 'voice_assigned_at' => now(),
        ]);
        $this->number->refresh();
        $group = ContactGroups::create(['customer_id' => $this->customer->user_id, 'name' => 'g', 'status' => true]);
        $this->contact = Contacts::create(['customer_id' => $this->customer->user_id, 'business_id' => $this->business->id, 'group_id' => $group->id, 'phone' => self::CALLER, 'status' => 'subscribe']);
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

                return Http::response(['data' => ['call_control_id' => (str_starts_with((string) $r['to'], 'sip:') ? 'browser-' : 'phone-') . $this->seq]], 200);
            }

            return Http::response([], 404);
        }]);
    }

    // ------------------------------------------------------------------ helpers

    private function setting(string $mode, bool $verified = false, int $ring = 20): BusinessInboundCallSetting
    {
        $hash = BusinessInboundCallSetting::hashNumber($this->business->id, self::FORWARD);

        return BusinessInboundCallSetting::create([
            'business_id' => $this->business->id, 'mode' => $mode, 'browser_ring_secs' => $ring,
            'forward_number' => self::FORWARD, 'forward_number_hash' => $hash,
            'forward_verified_hash' => $verified ? $hash : null, 'forward_verified_at' => $verified ? now() : null,
        ]);
    }

    private function online(): void
    {
        $this->postJson(route('customer.workspaces.businesses.calls.browser-session', [$this->workspace->uid, $this->business->uid]))->assertOk();
        $this->postJson(route('customer.workspaces.businesses.calls.presence', [$this->workspace->uid, $this->business->uid]))->assertOk();
    }

    private function url(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.calls.' . $name, [$this->workspace->uid, $this->business->uid, ...$extra]);
    }

    private function hook(string $eventId, string $type, string $callId, array $extra = [], ?string $clientState = null, bool $sign = true)
    {
        $payload = ['call_control_id' => $callId] + $extra + ($clientState !== null ? ['client_state' => $clientState] : []);
        $body = json_encode(['data' => ['id' => $eventId, 'event_type' => $type, 'occurred_at' => now()->toIso8601String(), 'payload' => $payload]]);
        $ts = (string) time();
        $sig = base64_encode(sodium_crypto_sign_detached($ts . '|' . $body, $sign ? $this->secret : str_repeat('x', 64)));

        return $this->call('POST', route('webhooks.telnyx_voice'), [], [], [], [
            'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts, 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function ring(string $eventId = 'evt-in-1', string $caller = '+' . self::CALLER, string $ccid = 'caller-1', ?string $to = null)
    {
        return $this->hook($eventId, 'call.initiated', $ccid, [
            'direction' => 'incoming', 'from' => $caller, 'to' => $to ?? $this->number->phone_number, 'connection_id' => self::CONN,
        ]);
    }

    /** @return list<Request> */
    private function sent(callable $filter): array
    {
        return array_values(array_filter(array_map(fn ($pair) => $pair[0], Http::recorded()->all()), $filter));
    }

    private function actions(string $action): array
    {
        return $this->sent(fn (Request $r) => str_ends_with($r->url(), '/actions/' . $action));
    }

    private function dials(string $kind): array
    {
        return $this->sent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calls')
            && (str_starts_with((string) $r['to'], 'sip:') === ($kind === 'browser')));
    }

    private function inboundCall(): BusinessVoiceCall
    {
        return BusinessVoiceCall::query()->where('kind', 'inbound')->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ gates

    public function test_with_the_flag_off_nothing_happens_and_no_command_is_sent(): void
    {
        $this->setting('browser');
        config(['messaging.voice.inbound.enabled' => false]);

        $this->ring()->assertOk();

        $this->assertSame(0, BusinessVoiceCall::query()->where('kind', 'inbound')->count());
        $this->assertSame([], $this->sent(fn (Request $r) => str_contains($r->url(), '/actions/')));
    }

    public function test_two_unrelated_businesses_are_routed_with_no_allowlist_and_never_across_tenants(): void
    {
        $this->setting('browser');
        $this->online();
        $this->assertSame([], (array) config('messaging.voice.inbound.pilot_business_ids', []), 'There is no per-Business allowlist.');

        // A second, unrelated tenant with its own number and routing.
        [$customer2, $business2, $workspace2] = $this->tenant(WorkspacePlanTier::Growth);
        $identity2 = $this->attachIdentity($business2);
        $number2 = $this->attachNumber($identity2, $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Active, 'num_ref_2');
        \DB::table('business_messaging_numbers')->where('id', $number2->id)->update(['number_type' => 'local', 'voice_connection_id' => self::CONN, 'voice_assigned_at' => now()]);
        BusinessInboundCallSetting::create(['business_id' => $business2->id, 'mode' => 'browser', 'browser_ring_secs' => 20]);

        $this->ring('evt-1', '+' . self::CALLER, 'caller-1')->assertOk()->assertJsonPath('status', 'applied');
        $this->ring('evt-2', '+14155559999', 'caller-2', $number2->fresh()->phone_number)->assertOk()->assertJsonPath('status', 'applied');

        $first = BusinessVoiceCall::where('telnyx_call_control_id', 'caller-1')->firstOrFail();
        $second = BusinessVoiceCall::where('telnyx_call_control_id', 'caller-2')->firstOrFail();
        $this->assertSame($this->business->id, (int) $first->business_id);
        $this->assertSame($business2->id, (int) $second->business_id);
        $this->assertSame(0, BusinessVoiceCallLeg::where('business_voice_call_id', $second->id)->count(), 'Business 2 has no available browser; business 1\'s user is never rung for it.');
        $this->assertSame('missed', $second->refresh()->outcome);

        // The first business's signed-in user can neither see nor answer the second business's call.
        $this->postJson(route('customer.workspaces.businesses.calls.answer', [$this->workspace->uid, $this->business->uid, $second->uid]))->assertStatus(404);
    }

    public function test_an_unsigned_inbound_webhook_is_rejected_before_anything_is_processed(): void
    {
        $this->setting('browser');
        $this->hook('evt-x', 'call.initiated', 'caller-1', ['direction' => 'incoming', 'from' => '+14155552671', 'to' => $this->number->phone_number], null, false)->assertStatus(403);

        $this->assertSame(0, BusinessVoiceCall::query()->where('kind', 'inbound')->count());
    }

    public function test_a_number_that_belongs_to_no_business_is_ignored_and_a_mode_of_off_rejects_the_caller(): void
    {
        $this->setting('browser');
        $this->ring('evt-a', '+' . self::CALLER, 'caller-9', '+19995550100')->assertOk();
        $this->assertSame(0, BusinessVoiceCall::query()->where('kind', 'inbound')->count());

        BusinessInboundCallSetting::query()->update(['mode' => 'off']);
        $this->ring('evt-b')->assertOk();
        $this->assertCount(1, $this->actions('reject'));
        $this->assertSame(0, BusinessVoiceCall::query()->where('kind', 'inbound')->count());
    }

    // ------------------------------------------------------------------ browser routing

    public function test_browser_mode_rings_the_available_browser_and_identifies_the_caller(): void
    {
        $this->setting('browser');
        $this->online();

        $this->ring()->assertOk()->assertJsonPath('status', 'applied');

        $dial = $this->dials('browser');
        $this->assertCount(1, $dial);
        $this->assertSame('sip:gencredabc@sip.telnyx.com', $dial[0]['to']);
        $this->assertSame($this->number->phone_number, $dial[0]['from']);
        $this->assertLessThanOrEqual(30, $dial[0]['timeout_secs']);

        $call = $this->inboundCall();
        $this->assertSame($this->contact->id, $call->contact_id, 'The caller is matched to the CRM contact.');
        $this->assertSame('inbound', $call->direction);

        $list = $this->getJson($this->url('incoming'))->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($call->uid, $list[0]['id']);
        $this->assertSame('+1 (415) 555-2671', $list[0]['caller']['number']);
        $this->assertSame($this->contact->uid, $list[0]['contact_uid']);
    }

    public function test_answering_claims_then_bridges_and_records_duration(): void
    {
        $this->setting('browser');
        $this->online();
        $this->ring();
        $call = $this->inboundCall();

        $this->postJson($this->url('answer', [$call->uid]))->assertOk();
        $this->hook('evt-2', 'call.answered', 'browser-' . $this->seq)->assertOk();

        $this->assertCount(1, $this->actions('answer'), 'The caller leg is answered only now.');
        $bridge = $this->actions('bridge');
        $this->assertCount(1, $bridge);
        $this->assertSame('caller-1', $bridge[0]['call_control_id']);
        $this->assertSame('answered', $call->refresh()->outcome);

        // A redelivered answer never bridges twice.
        $this->hook('evt-2', 'call.answered', 'browser-' . $this->seq)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertCount(1, $this->actions('bridge'));

        $call->forceFill(['answered_at' => now()->subSeconds(75)])->save();
        $this->hook('evt-3', 'call.hangup', 'caller-1', ['hangup_cause' => 'normal_clearing'])->assertOk();

        $call->refresh();
        $this->assertSame(BusinessVoiceCall::STATUS_ENDED, $call->status);
        $this->assertGreaterThanOrEqual(74, $call->duration_seconds);
        $this->assertSame('answered', $call->outcome);
        $this->getJson($this->url('show', [$call->uid]))->assertOk()->assertJsonPath('data.direction', 'inbound')->assertJsonPath('data.outcome', 'answered');
    }

    public function test_only_one_answer_can_win_and_an_unclaimed_leg_that_answers_is_hung_up(): void
    {
        $this->setting('browser');
        $this->online();
        $this->ring();
        $call = $this->inboundCall();
        // A second ringing browser (another user's / tab's leg) for the same call.
        BusinessVoiceCallLeg::create(['business_voice_call_id' => $call->id, 'kind' => 'browser', 'user_id' => 999999, 'call_control_id' => 'browser-other', 'client_state_hash' => str_repeat('a', 64)]);

        $this->postJson($this->url('answer', [$call->uid]))->assertOk();
        $this->postJson($this->url('answer', [$call->uid]))->assertStatus(409)->assertJsonPath('error.code', 'already_answered');
        $this->assertNotEmpty(array_filter($this->actions('hangup'), fn ($r) => str_contains($r->url(), 'browser-other')), 'The losing leg is hung up.');

        $this->hook('evt-late', 'call.answered', 'browser-other')->assertOk();
        $this->assertSame([], $this->actions('bridge'), 'A leg that did not win is never bridged.');
    }

    public function test_declining_with_nobody_else_records_declined_and_releases_the_caller(): void
    {
        $this->setting('browser');
        $this->online();
        $this->ring();
        $call = $this->inboundCall();

        $this->postJson($this->url('decline', [$call->uid]))->assertOk();

        $call->refresh();
        $this->assertSame('declined', $call->outcome);
        $this->assertSame(BusinessVoiceCall::STATUS_ENDED, $call->status);
        $reject = $this->actions('reject');
        $this->assertCount(1, $reject);
        $this->assertSame('CALL_REJECTED', $reject[0]['cause']);
    }

    public function test_nobody_available_or_nobody_answering_records_a_missed_call_and_never_leaves_the_caller_ringing(): void
    {
        $this->setting('browser');

        $this->ring(); // no browser online
        $call = $this->inboundCall();
        $this->assertSame('missed', $call->outcome);
        $this->assertSame([], $this->dials('browser'));
        $this->assertSame('USER_BUSY', $this->actions('reject')[0]['cause']);

        // Browser online but it rings out (Telnyx reports the unanswered leg's hangup).
        $this->online();
        $this->ring('evt-r2', '+' . self::CALLER, 'caller-2');
        $leg = 'browser-' . $this->seq;
        $this->hook('evt-r3', 'call.hangup', $leg, ['hangup_cause' => 'timeout'])->assertOk();
        $this->assertSame('missed', BusinessVoiceCall::query()->where('telnyx_call_control_id', 'caller-2')->firstOrFail()->outcome);
    }

    public function test_the_caller_hanging_up_while_ringing_is_a_missed_call_and_hangs_up_the_legs(): void
    {
        $this->setting('browser');
        $this->online();
        $this->ring();

        $this->hook('evt-h', 'call.hangup', 'caller-1', ['hangup_cause' => 'originator_cancel'])->assertOk();

        $call = $this->inboundCall();
        $this->assertSame('missed', $call->outcome);
        $this->assertSame('caller_hangup', $call->failure_code);
        $this->assertNotEmpty(array_filter($this->actions('hangup'), fn ($r) => str_contains($r->url(), 'browser-')));
    }

    // ------------------------------------------------------------------ forwarding

    public function test_forward_mode_dials_the_verified_phone_and_bridges_when_it_answers(): void
    {
        $this->setting('forward', true);

        $this->ring()->assertOk();

        $this->assertSame([], $this->dials('browser'));
        $phone = $this->dials('phone');
        $this->assertCount(1, $phone);
        $this->assertSame(self::FORWARD, $phone[0]['to']);
        $this->assertSame($this->number->phone_number, $phone[0]['from']);
        $this->assertSame(25, $phone[0]['timeout_secs'], 'Forward ringing is bounded.');

        $this->hook('evt-p', 'call.answered', 'phone-' . $this->seq)->assertOk();
        $this->assertCount(1, $this->actions('bridge'));
        $this->assertSame('forwarded', $this->inboundCall()->outcome);

        // Idempotent commands: each carries a deterministic command_id.
        $this->assertNotEmpty($this->actions('bridge')[0]['command_id']);
    }

    public function test_browser_first_then_forward_when_the_browser_rings_out(): void
    {
        $this->setting('browser_then_forward', true);
        $this->online();
        $this->ring();
        $this->assertCount(1, $this->dials('browser'));
        $this->assertSame([], $this->dials('phone'));

        $this->hook('evt-t', 'call.hangup', 'browser-' . $this->seq, ['hangup_cause' => 'timeout'])->assertOk();

        $this->assertCount(1, $this->dials('phone'));
        $this->assertSame('forwarding', $this->inboundCall()->phase);
        // The forward leg timing out ends the call as missed.
        $this->hook('evt-t2', 'call.hangup', 'phone-' . $this->seq, ['hangup_cause' => 'timeout'])->assertOk();
        $this->assertSame('missed', $this->inboundCall()->outcome);
        $this->assertCount(1, $this->dials('phone'), 'Forwarding is attempted once per call.');
    }

    public function test_declining_the_browser_in_browser_then_forward_mode_forwards(): void
    {
        $this->setting('browser_then_forward', true);
        $this->online();
        $this->ring();
        $this->postJson($this->url('decline', [$this->inboundCall()->uid]))->assertOk();

        $this->assertCount(1, $this->dials('phone'));
    }

    public function test_an_unverified_or_changed_forward_number_is_never_dialled(): void
    {
        $this->setting('forward', false);
        $this->ring();
        $this->assertSame([], $this->dials('phone'));
        $this->assertSame('missed', $this->inboundCall()->outcome);

        // A number that was verified and then replaced loses its verification.
        BusinessInboundCallSetting::firstOrFail()->forceFill([
            'forward_verified_hash' => BusinessInboundCallSetting::hashNumber($this->business->id, self::FORWARD), 'forward_verified_at' => now(),
            'forward_number' => '+16505550199', 'forward_number_hash' => BusinessInboundCallSetting::hashNumber($this->business->id, '+16505550199'),
        ])->save();
        $this->ring('evt-n', '+' . self::CALLER, 'caller-2');
        $this->assertSame([], $this->dials('phone'));
    }

    public function test_a_restricted_forward_destination_is_never_dialled_even_if_stored(): void
    {
        $this->setting('forward', true);
        $e = '+18005550100';
        BusinessInboundCallSetting::firstOrFail()->forceFill(['forward_number' => $e, 'forward_number_hash' => BusinessInboundCallSetting::hashNumber($this->business->id, $e), 'forward_verified_hash' => BusinessInboundCallSetting::hashNumber($this->business->id, $e)])->save();

        $this->ring();

        $this->assertSame([], $this->dials('phone'));
        $this->assertSame('missed', $this->inboundCall()->outcome);
    }

    public function test_the_daily_forwarding_cap_stops_forwarding(): void
    {
        $this->setting('forward', true);
        config(['messaging.voice.inbound.max_forward_attempts_per_day' => 1]);

        $this->ring();
        $this->hook('evt-e', 'call.hangup', 'phone-' . $this->seq, ['hangup_cause' => 'timeout']);
        $this->ring('evt-in-2', '+' . self::CALLER, 'caller-2');

        $this->assertCount(1, $this->dials('phone'));
        $this->assertSame('missed', BusinessVoiceCall::query()->where('telnyx_call_control_id', 'caller-2')->firstOrFail()->outcome);
    }

    public function test_a_superseded_verification_call_never_speaks_or_validates_a_newer_code(): void
    {
        // A -> B -> A: three verification calls; the first (and second) are superseded by the third.
        $this->post($this->calling('forward.verify'), ['phone' => '(650) 555-0142'])->assertSessionHasNoErrors();
        $callA1 = 'phone-' . $this->seq;
        $this->post($this->calling('forward.verify'), ['phone' => '(650) 555-0177'])->assertSessionHasNoErrors();
        $callB = 'phone-' . $this->seq;
        $codeB = BusinessInboundCallSetting::firstOrFail()->verify_code;
        $this->post($this->calling('forward.verify'), ['phone' => '(650) 555-0142'])->assertSessionHasNoErrors();
        $codeA2 = BusinessInboundCallSetting::firstOrFail()->verify_code;

        // A late answer from the first call (and from B) must not hear ANY code.
        $this->hook('late-a1', 'call.answered', $callA1)->assertOk();
        $this->hook('late-b', 'call.answered', $callB)->assertOk();
        $this->assertSame([], $this->actions('speak'), 'A superseded call never speaks a challenge.');

        // Only the current call speaks, and it speaks its own code.
        $this->hook('cur', 'call.answered', 'phone-' . $this->seq)->assertOk();
        $speak = $this->actions('speak');
        $this->assertCount(1, $speak);
        $this->assertStringContainsString(implode(', ', str_split($codeA2)), $speak[0]['payload']);
        if ($codeB !== $codeA2) {
            $this->assertStringNotContainsString(implode(', ', str_split($codeB)), $speak[0]['payload']);
            $this->post($this->calling('forward.confirm'), ['code' => $codeB])->assertSessionHasErrors('code');
        }
        $this->assertFalse(BusinessInboundCallSetting::firstOrFail()->forwardVerified());
    }

    public function test_an_inbound_participant_keeps_status_and_controls_but_restricted_contact_details_are_redacted(): void
    {
        $this->setting('browser');
        $this->online();
        $this->ring();
        $call = $this->inboundCall();
        // The matched Contact sits at a Location this user holds no grant for.
        $location = \App\Models\BusinessLocation::create(['business_id' => $this->business->id, 'name' => 'Restricted', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $this->contact->forceFill(['location_id' => $location->id])->saveQuietly();
        $this->mock(\App\Library\Workspace\LocationAccessGuard::class, fn ($m) => $m->shouldReceive('userCanAccessLocation')->andReturn(false));
        $this->postJson($this->url('answer', [$call->uid]))->assertOk();

        $show = $this->getJson($this->url('show', [$call->uid]))->assertOk();
        $show->assertJsonPath('data.contact_uid', null)->assertJsonPath('data.caller.name', null);
        $this->assertStringNotContainsString($this->contact->uid, $show->getContent());

        $this->postJson($this->url('hangup', [$call->uid]))->assertOk();
    }

    public function test_only_the_answering_user_can_end_an_answered_inbound_call(): void
    {
        $this->setting('browser');
        $this->online();
        $this->ring();
        $call = $this->inboundCall();
        // Another employee (user B) also rang; user A (someone else) answered.
        $call->forceFill(['answered_by_user_id' => 999999, 'claimed_leg_id' => 1])->save();

        $this->postJson($this->url('hangup', [$call->uid]))->assertStatus(404);
        $this->assertSame(BusinessVoiceCall::STATUS_INITIATED, $call->refresh()->status);
        $this->assertSame([], $this->actions('hangup'), 'No leg was hung up by the non-answering user.');

        // Declining their own ring is still allowed for a participant who has not answered.
        $call->forceFill(['answered_by_user_id' => null, 'claimed_leg_id' => null])->save();
        $this->postJson($this->url('decline', [$call->uid]))->assertOk();
    }

    // ------------------------------------------------------------------ safety

    public function test_loops_limits_and_replays_are_handled(): void
    {
        $this->setting('forward', true);

        // A call from one of our own numbers would loop.
        $this->ring('evt-l', $this->number->phone_number, 'caller-loop');
        $this->assertSame('loop_prevented', BusinessVoiceCall::query()->where('telnyx_call_control_id', 'caller-loop')->firstOrFail()->failure_code);
        $this->assertSame([], $this->dials('phone'));

        // Replay of the same webhook (same event, then same call under a new event id) creates one call.
        $this->ring('evt-ok', '+' . self::CALLER, 'caller-ok');
        $this->ring('evt-ok', '+' . self::CALLER, 'caller-ok');
        $this->ring('evt-ok-2', '+' . self::CALLER, 'caller-ok');
        $this->assertSame(1, BusinessVoiceCall::query()->where('telnyx_call_control_id', 'caller-ok')->count());
        $this->assertCount(1, $this->dials('phone'));

        // Concurrency cap.
        config(['messaging.voice.inbound.max_concurrent_per_business' => 1]);
        $this->ring('evt-c', '+' . self::CALLER, 'caller-over');
        $this->assertSame('limit_reached', BusinessVoiceCall::query()->where('telnyx_call_control_id', 'caller-over')->firstOrFail()->failure_code);
    }

    public function test_the_sweeper_closes_calls_that_outlive_their_ring_bound(): void
    {
        $this->setting('browser_then_forward', true);
        $this->online();
        $this->ring();
        $this->inboundCall()->forceFill(['ring_deadline_at' => now()->subMinute()])->save();

        $this->assertSame(1, app(InboundCallRouter::class)->expireStale());
        $this->assertCount(1, $this->dials('phone'), 'Past the browser deadline it forwards.');

        $this->inboundCall()->forceFill(['ring_deadline_at' => now()->subMinute()])->save();
        app(InboundCallRouter::class)->expireStale();
        $this->assertSame('missed', $this->inboundCall()->outcome);
        $this->assertSame(BusinessVoiceCall::STATUS_ENDED, $this->inboundCall()->status);
    }

    public function test_another_user_cannot_see_answer_or_hang_up_a_call_they_are_not_party_to(): void
    {
        $this->setting('browser');
        $this->online();
        $this->ring();
        $call = $this->inboundCall();
        BusinessVoiceCallLeg::query()->where('business_voice_call_id', $call->id)->update(['user_id' => 999999]);

        $this->postJson($this->url('answer', [$call->uid]))->assertStatus(404);
        $this->postJson($this->url('decline', [$call->uid]))->assertStatus(404);
        $this->getJson($this->url('show', [$call->uid]))->assertStatus(404);
        $this->postJson($this->url('hangup', [$call->uid]))->assertStatus(404);
        $this->assertSame([], $this->getJson($this->url('incoming'))->json('data'));
    }

    public function test_presence_is_required_and_a_stale_browser_is_not_rung(): void
    {
        $this->setting('browser');
        $this->online();
        BusinessVoiceBrowserCredential::query()->update(['presence_at' => now()->subMinutes(5)]);

        $this->ring();

        $this->assertSame([], $this->dials('browser'));
        $this->assertSame('missed', $this->inboundCall()->outcome);
    }

    // ------------------------------------------------------------------ settings

    private function calling(string $name): string
    {
        return route('customer.workspaces.businesses.calling.' . $name, [$this->workspace->uid, $this->business->uid]);
    }

    public function test_the_owner_can_change_routing_but_not_to_forwarding_without_a_verified_number_or_while_the_platform_is_off(): void
    {
        $this->get($this->calling('show'))->assertOk()->assertSee('When someone calls')->assertDontSee('pilot businesses', false);

        $this->put($this->calling('update'), ['mode' => 'browser', 'browser_ring_secs' => 25])->assertSessionHasNoErrors();
        $this->assertSame('browser', BusinessInboundCallSetting::firstOrFail()->mode);

        $this->put($this->calling('update'), ['mode' => 'forward', 'browser_ring_secs' => 25])->assertSessionHasErrors('mode');
        $this->put($this->calling('update'), ['mode' => 'browser', 'browser_ring_secs' => 3])->assertSessionHasErrors('browser_ring_secs');

        // The one global emergency switch: choosing a mode is refused, the page explains, and turning calling off still works.
        config(['messaging.voice.inbound.enabled' => false]);
        $this->get($this->calling('show'))->assertOk()->assertSee('temporarily unavailable');
        $this->put($this->calling('update'), ['mode' => 'browser_then_forward', 'browser_ring_secs' => 20])->assertSessionHasErrors('mode');
        $this->assertSame('browser', BusinessInboundCallSetting::firstOrFail()->mode, 'Nothing changed.');
        $this->put($this->calling('update'), ['mode' => 'off', 'browser_ring_secs' => 20])->assertSessionHasNoErrors();
        $this->assertSame('off', BusinessInboundCallSetting::firstOrFail()->mode);
    }

    public function test_every_eligible_business_can_open_and_configure_calling_and_strangers_cannot(): void
    {
        [$customer2, $business2, $workspace2] = $this->tenant(WorkspacePlanTier::Growth);
        $url2 = fn (string $n) => route('customer.workspaces.businesses.calling.' . $n, [$workspace2->uid, $business2->uid]);

        // Business 1's owner configures business 1 and is a stranger to business 2.
        $this->put($this->calling('update'), ['mode' => 'browser', 'browser_ring_secs' => 20])->assertSessionHasNoErrors();
        $this->get($url2('show'))->assertStatus(404);
        $this->put($url2('update'), ['mode' => 'browser', 'browser_ring_secs' => 20])->assertStatus(404);
        $this->assertSame(0, BusinessInboundCallSetting::where('business_id', $business2->id)->count());

        // Business 2's owner (never allowlisted anywhere) configures business 2 and is a stranger to business 1.
        $this->authenticateAs($customer2);
        $this->get($url2('show'))->assertOk()->assertSee('When someone calls');
        $this->put($url2('update'), ['mode' => 'browser', 'browser_ring_secs' => 30])->assertSessionHasNoErrors();
        $this->assertSame(30, (int) BusinessInboundCallSetting::where('business_id', $business2->id)->value('browser_ring_secs'));
        $this->get($this->calling('show'))->assertStatus(404);
        $this->put($this->calling('update'), ['mode' => 'off', 'browser_ring_secs' => 20])->assertStatus(404);
        $this->assertSame('browser', BusinessInboundCallSetting::where('business_id', $this->business->id)->value('mode'));
    }

    public function test_the_calling_menu_entry_is_never_hidden_even_when_telephony_is_unavailable(): void
    {
        $settings = route('customer.workspaces.businesses.settings.show', [$this->workspace->uid, $this->business->uid]);
        $this->get($settings)->assertOk()->assertSee(route('customer.workspaces.businesses.calling.show', [$this->workspace->uid, $this->business->uid]), false);

        config(['messaging.voice.inbound.enabled' => false, 'services.telnyx.api_key' => '']);
        $this->get($settings)->assertOk()->assertSee(route('customer.workspaces.businesses.calling.show', [$this->workspace->uid, $this->business->uid]), false);
    }

    public function test_verifying_a_forward_number_speaks_a_code_and_the_code_enables_forwarding(): void
    {
        $this->post($this->calling('forward.verify'), ['phone' => '(650) 555-0142'])->assertSessionHasNoErrors();
        $verifyDial = $this->dials('phone');
        $this->assertCount(1, $verifyDial);
        $this->assertSame(self::FORWARD, $verifyDial[0]['to']);

        $this->assertSame(1, BusinessVoiceCall::where('kind', 'forward_verify')->count());
        $setting = BusinessInboundCallSetting::firstOrFail();
        $this->assertFalse($setting->forwardVerified());

        $this->hook('v1', 'call.answered', 'phone-' . $this->seq)->assertOk();
        $speak = $this->actions('speak');
        $this->assertCount(1, $speak);
        $code = $setting->verify_code;
        $this->assertStringContainsString(implode(', ', str_split($code)), $speak[0]['payload']);

        $this->post($this->calling('forward.confirm'), ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($setting->refresh()->forwardVerified());
        $this->post($this->calling('forward.confirm'), ['code' => $code])->assertSessionHasNoErrors();
        $this->assertTrue($setting->refresh()->forwardVerified());

        $this->put($this->calling('update'), ['mode' => 'forward', 'browser_ring_secs' => 20])->assertSessionHasNoErrors();
        $this->assertSame('forward', $setting->refresh()->mode);
        $this->assertSame('ended', 'ended');
    }

    public function test_toll_free_international_and_platform_numbers_cannot_be_verified(): void
    {
        foreach (['(800) 555-0100', '+44 20 7946 0958', '(900) 555-0100', '555'] as $bad) {
            $this->post($this->calling('forward.verify'), ['phone' => $bad])->assertSessionHasErrors('phone', null, 'default', 'case ' . $bad);
        }
        $this->post($this->calling('forward.verify'), ['phone' => $this->number->phone_number])->assertSessionHasErrors('phone');
        $this->assertSame([], $this->dials('phone'));
    }

    public function test_the_saved_forward_number_is_never_rendered(): void
    {
        $this->setting('forward', true);
        $html = $this->get($this->calling('show'))->assertOk()->getContent();
        $this->assertStringContainsString('0142', $html);
        $this->assertStringNotContainsString('6505550142', $html);
    }

    public function test_a_member_without_the_calling_permission_cannot_change_routing(): void
    {
        $this->setting('browser');
        $other = $this->tenant(WorkspacePlanTier::Growth);
        // The signed-in user is a different customer who owns another Workspace: this Business is a foreign tenant -> 404.
        $this->authenticateAs($other[0]);
        $this->put($this->calling('update'), ['mode' => 'off', 'browser_ring_secs' => 20])->assertStatus(404);
        $this->get($this->calling('show'))->assertStatus(404);
        $this->assertSame('browser', BusinessInboundCallSetting::firstOrFail()->mode);
    }

    public function test_view_as_cannot_change_routing(): void
    {
        $names = \App\Library\ViewAs\ViewAsProhibitedActions::EXACT;
        foreach (['update', 'forward.verify', 'forward.confirm', 'forward.remove'] as $route) {
            $this->assertContains('customer.workspaces.businesses.calling.' . $route, $names);
        }
    }

    public function test_the_softphone_is_only_rendered_for_inbound_when_the_server_allows_it(): void
    {
        $page = route('customer.workspaces.businesses.settings.show', [$this->workspace->uid, $this->business->uid]);

        $this->get($page)->assertOk()->assertDontSee('id="mg-softphone"', false);

        $this->setting('browser');
        $html = $this->get($page)->assertOk()->assertSee('id="mg-softphone"', false)->assertSee('presenceUrl', false)->getContent();
        $this->assertSame(1, substr_count($html, 'id="mg-softphone"'));

        config(['messaging.voice.inbound.enabled' => false]);
        $this->get($page)->assertOk()->assertDontSee('id="mg-softphone"', false);
    }
}
