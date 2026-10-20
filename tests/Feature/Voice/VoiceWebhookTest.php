<?php

namespace Tests\Feature\Voice;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Voice\Concerns\BuildsVoiceFixtures;
use Tests\TestCase;

/**
 * Telnyx call events become the one canonical call record — only when genuinely signed, once, in the right
 * order, and only for the account that sent them. Every payload here is hand-built and signed with a
 * test-only key; no provider is contacted.
 */
class VoiceWebhookTest extends TestCase
{
    use RefreshDatabase;
    use BuildsVoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoice();
    }

    /** An outgoing call the user has started from the browser, waiting for Telnyx to confirm. */
    private function startedOutbound(): array
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $this->browserOnline($workspace, $business, $number);
        $contact = $this->contactFor($business);
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->json('call.uid');

        return [VoiceCall::where('uid', $uid)->firstOrFail(), $business, $workspace, $user, $number, $contact];
    }

    /** An event about OUR leg: the dial MotionGrove placed to the user's browser identity. */
    private function legA(VoiceCall $call, array $extra = []): array
    {
        $call = $call->fresh();

        return array_merge([
            'call_session_id' => $call->provider_call_session_id, 'call_control_id' => $call->provider_call_control_id, 'call_leg_id' => $call->provider_leg_id,
            'direction' => 'outgoing', 'from' => $call->to_number, 'to' => 'sip:gencred1@sip.telnyx.com',
            'client_state' => base64_encode($call->uid),
        ], $extra);
    }

    /** An event about the CUSTOMER's leg, created by MotionGrove's bridge, on the same session. */
    private function legB(VoiceCall $call, array $extra = []): array
    {
        $call = $call->fresh();

        return array_merge([
            'call_session_id' => $call->provider_call_session_id, 'call_control_id' => 'pstn-leg-' . $call->id, 'call_leg_id' => 'pstn-lg-' . $call->id,
            'direction' => 'outgoing', 'from' => $call->from_number, 'to' => $call->to_number,
        ], $extra);
    }

    /** Drives a call to the bridged state: our leg answered (so MotionGrove bridges), customer leg ringing. */
    private function bridged(VoiceCall $call): void
    {
        $this->managedVoiceWebhook('a-' . $call->id, 'call.answered', $this->legA($call))->assertOk();
    }

    /* --------------------------------------------------------------- verification */

    public function test_unsigned_badly_signed_and_stale_events_change_nothing(): void
    {
        [$call] = $this->startedOutbound();
        $payload = $this->legA($call);
        $url = route('voice.webhooks.telnyx_managed');
        $body = json_encode(['data' => ['id' => 'e1', 'event_type' => 'call.answered', 'payload' => $payload]]);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(403);

        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->managedVoiceWebhook('e2', 'call.answered', $payload, $other)->assertStatus(403);
        $this->managedVoiceWebhook('e3', 'call.answered', $payload, null, now()->subMinutes(30)->getTimestamp())->assertStatus(403);

        $this->assertSame(0, VoiceCallEvent::count());
        $this->assertSame('initiating', $call->fresh()->status);
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'), 'An unverified event can never cause a bridge.');
    }

    public function test_the_managed_webhook_is_inert_until_managed_voice_is_enabled(): void
    {
        [$call] = $this->startedOutbound();
        config(['services.telnyx.voice_enabled' => false]);

        $this->managedVoiceWebhook('e1', 'call.answered', $this->legA($call))->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(0, VoiceCallEvent::count());
    }

    public function test_voice_webhooks_need_no_csrf_token_but_still_need_a_valid_signature(): void
    {
        [$call] = $this->startedOutbound();
        $this->app['env'] = 'local'; // switch Laravel's CSRF check on

        $this->managedVoiceWebhook('c1', 'call.answered', $this->legA($call))->assertOk();
        $this->managedVoiceWebhook('c2', 'call.answered', $this->legA($call), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))->assertStatus(403);
    }

    public function test_a_byo_webhook_needs_the_agencys_own_key_and_token_not_the_platforms(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);
        $channel = \App\Models\AgencyProspectingChannel::findOrFail($byo->agency_channel_id);
        $payload = ['call_session_id' => 's9', 'call_control_id' => 'c9', 'direction' => 'incoming', 'from' => '+12025559000', 'to' => $byo->phone_number];

        $this->byoVoiceWebhook($channel, 'b1', 'call.initiated', $payload, $this->managedSecretKey)->assertStatus(403);

        $url = route('prospecting.webhooks.agency_telnyx_voice', [$channel->uid, 'wrong-token']);
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(404);

        $this->byoVoiceWebhook($channel, 'b2', 'call.initiated', $payload)->assertOk();
        $this->assertSame(1, VoiceCall::count());
    }

    /* ----------------------------------------- server-mediated outgoing call: legs and bridge */

    public function test_our_browser_leg_answering_bridges_to_the_validated_destination_only_and_is_not_the_customer_answering(): void
    {
        [$call, $business, , $user] = $this->startedOutbound();
        $t0 = now();

        $this->managedVoiceWebhook('e1', 'call.initiated', $this->legA($call))->assertOk()->assertJson(['status' => 'ignored']);
        $this->managedVoiceWebhook('e2', 'call.answered', $this->legA($call) + ['_at' => $t0->toIso8601String()])->assertOk()->assertJson(['status' => 'applied']);

        $transfer = $this->telnyx->callsTo('transferToSip');
        $this->assertCount(1, $transfer);
        $this->assertSame($call->provider_call_control_id, $transfer[0]['callControlId']);
        $this->assertSame('+12025550111', $transfer[0]['sipUri'], 'the destination recorded when the request was validated');
        $this->assertSame('+14155550199', $transfer[0]['from'], 'the approved caller ID');
        $this->assertSame('platform-key-never-leaks', $transfer[0]['apiKey']);
        $this->assertSame('ringing', $call->fresh()->status, 'only our own leg answered');
        $this->assertNull($call->fresh()->answered_at);

        $this->managedVoiceWebhook('e3', 'call.answered', $this->legB($call) + ['_at' => $t0->copy()->addSeconds(4)->toIso8601String()])->assertOk();
        $this->assertSame('answered', $call->fresh()->status);
        $this->assertNotNull($call->fresh()->answered_at);

        $this->managedVoiceWebhook('e4', 'call.hangup', $this->legB($call) + ['hangup_cause' => 'normal_clearing', 'hangup_source' => 'callee', 'end_time' => $t0->copy()->addSeconds(94)->toIso8601String(), '_at' => $t0->copy()->addSeconds(94)->toIso8601String()])->assertOk();

        $done = $call->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertSame(90, $done->duration_seconds);
        $this->assertSame((int) $user->id, (int) $done->user_id);
        $this->assertSame((int) $business->id, (int) $done->business_id);
    }

    public function test_a_replayed_answer_bridges_only_once(): void
    {
        [$call] = $this->startedOutbound();

        $this->managedVoiceWebhook('e2', 'call.answered', $this->legA($call))->assertOk();
        $this->managedVoiceWebhook('e2', 'call.answered', $this->legA($call))->assertJson(['status' => 'duplicate']);
        $this->managedVoiceWebhook('e2b', 'call.answered', $this->legA($call))->assertOk();

        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'));
    }

    public function test_the_browser_saying_ended_is_upgraded_not_trusted(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();
        $this->bridged($call);
        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid . '/client-event', ['event' => 'ended'])->assertOk();
        $this->assertSame('unconfirmed', $call->fresh()->status);

        $this->managedVoiceWebhook('e5', 'call.hangup', $this->legB($call) + ['hangup_cause' => 'user_busy'])->assertOk();

        $this->assertSame('busy', $call->fresh()->status);
    }

    #[DataProvider('outboundOutcomes')]
    public function test_outgoing_outcomes_follow_the_provider_cause(string $cause, ?string $source, string $expected): void
    {
        [$call] = $this->startedOutbound();
        $this->bridged($call);
        $this->managedVoiceWebhook('e5', 'call.hangup', $this->legB($call) + array_filter(['hangup_cause' => $cause, 'hangup_source' => $source]))->assertOk();

        $this->assertSame($expected, $call->fresh()->status);
        $this->assertSame(0, $call->fresh()->duration_seconds);
    }

    public static function outboundOutcomes(): array
    {
        return [
            'busy' => ['user_busy', 'callee', 'busy'],
            'no answer' => ['no_answer', 'callee', 'no_answer'],
            'timeout' => ['timeout', null, 'no_answer'],
            'canceled by us' => ['originator_cancel', 'caller', 'canceled'],
            'declined' => ['call_rejected', 'callee', 'declined'],
            'unreachable number' => ['unallocated_number', null, 'failed'],
        ];
    }

    public function test_a_browser_that_never_answered_its_leg_is_a_failure_not_a_no_answer(): void
    {
        [$call] = $this->startedOutbound();

        $this->managedVoiceWebhook('h1', 'call.hangup', $this->legA($call) + ['hangup_cause' => 'no_answer'])->assertOk();

        $this->assertSame('failed', $call->fresh()->status);
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
    }

    /* ----------------------------------------- 10. duplicates, replays, stale, out of order */

    public function test_a_repeated_event_creates_nothing_and_changes_nothing(): void
    {
        [$call] = $this->startedOutbound();

        $this->managedVoiceWebhook('ans-1', 'call.answered', $this->legB($call))->assertOk();
        $this->managedVoiceWebhook('ans-1', 'call.answered', $this->legB($call))->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, VoiceCall::count());
        $this->assertSame(1, VoiceCallEvent::count());
    }

    public function test_a_replay_of_the_final_event_with_a_new_id_does_not_change_the_outcome(): void
    {
        [$call] = $this->startedOutbound();
        $this->bridged($call);
        $this->managedVoiceWebhook('e2', 'call.answered', $this->legB($call))->assertOk();
        $this->managedVoiceWebhook('e3', 'call.hangup', $this->legB($call) + ['hangup_cause' => 'normal_clearing'])->assertOk();
        $finished = $call->fresh();

        $this->managedVoiceWebhook('e4', 'call.hangup', $this->legB($call) + ['hangup_cause' => 'call_rejected', 'end_time' => now()->addHour()->toIso8601String()])->assertJson(['status' => 'ignored']);

        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame($finished->duration_seconds, $call->fresh()->duration_seconds);
        $this->assertSame($finished->ended_at->toIso8601String(), $call->fresh()->ended_at->toIso8601String());
    }

    public function test_a_late_answer_event_never_reopens_a_finished_call(): void
    {
        [$call] = $this->startedOutbound();
        $this->bridged($call);
        $this->managedVoiceWebhook('e2', 'call.answered', $this->legB($call))->assertOk();
        $this->managedVoiceWebhook('e3', 'call.hangup', $this->legB($call) + ['hangup_cause' => 'normal_clearing'])->assertOk();

        $this->managedVoiceWebhook('late-a', 'call.answered', $this->legA($call), null)->assertJson(['status' => 'ignored']);
        $this->managedVoiceWebhook('late-b', 'call.answered', $this->legB($call))->assertJson(['status' => 'ignored']);

        $this->assertSame('completed', $call->fresh()->status);
    }

    public function test_a_hangup_that_overtakes_the_customer_answer_is_corrected_when_the_answer_arrives(): void
    {
        [$call] = $this->startedOutbound();
        $t0 = now();
        $this->bridged($call);

        $this->managedVoiceWebhook('e3', 'call.hangup', $this->legB($call) + ['hangup_cause' => 'normal_clearing', 'hangup_source' => 'callee', 'end_time' => $t0->copy()->addSeconds(60)->toIso8601String(), '_at' => $t0->copy()->addSeconds(60)->toIso8601String()])->assertOk();
        $this->assertSame('failed', $call->fresh()->status);

        $this->managedVoiceWebhook('e2', 'call.answered', $this->legB($call) + ['_at' => $t0->copy()->addSeconds(10)->toIso8601String()])->assertOk()->assertJson(['status' => 'applied']);

        $done = $call->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertSame(50, $done->duration_seconds);
    }

    public function test_an_unmatched_event_is_recorded_and_ignored(): void
    {
        $this->startedOutbound();

        $this->managedVoiceWebhook('x1', 'call.hangup', ['call_session_id' => 'unknown', 'direction' => 'outgoing', 'to' => '+12025550000'])
            ->assertOk()->assertJson(['status' => 'unmatched']);

        $this->assertSame(1, VoiceCall::count());
        $this->assertSame('unmatched', VoiceCallEvent::where('provider_event_id', 'x1')->value('applied'));
    }

    public function test_other_event_types_are_acknowledged_without_effect(): void
    {
        [$call] = $this->startedOutbound();

        $this->managedVoiceWebhook('rec', 'call.recording.saved', $this->legA($call))->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame('initiating', $call->fresh()->status);
    }

    /* ------------------------------ 1. adversarial: authority is re-checked at the moment of bridging */

    public function test_an_expired_permission_stops_the_bridge_and_hangs_up_our_leg(): void
    {
        [$call, $business, $workspace, $user] = $this->startedOutbound();
        // Between "Call" and the browser answering, the user's access is removed.
        \App\Models\Customer::query()->where('user_id', $user->id)->update(['permissions' => json_encode([])]);
        \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $workspace->id)->update(['owner_user_id' => \App\Models\User::query()->where('is_admin', true)->value('id')]);

        $this->bridged($call);

        $this->assertSame([], $this->telnyx->callsTo('transferToSip'), 'No bridge, so no call reaches the phone network.');
        $this->assertCount(1, $this->telnyx->callsTo('hangupCall'));
        $this->assertSame('failed', $call->fresh()->status);
        $this->assertContains($call->fresh()->hangup_cause, ['authorization_revoked', 'number_no_longer_callable']);
    }

    public function test_a_number_that_stops_being_callable_before_the_bridge_is_not_used(): void
    {
        [$call, , , , $number] = $this->startedOutbound();
        \App\Models\BusinessMessagingNumber::query()->whereKey($number->messaging_number_id)->update(['status' => \App\Enums\Messaging\BusinessMessagingNumberStatus::Suspended->value]);

        $this->bridged($call);

        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
        $this->assertSame('number_no_longer_callable', $call->fresh()->hangup_cause);
    }

    public function test_a_destination_the_policy_no_longer_allows_is_not_bridged(): void
    {
        [$call] = $this->startedOutbound();
        config(['services.telnyx.voice_allowed_regions' => 'GB']);

        $this->bridged($call);

        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
        $this->assertSame('destination_not_allowed', $call->fresh()->hangup_cause);
    }

    public function test_an_answer_long_after_the_call_was_requested_is_not_bridged(): void
    {
        [$call] = $this->startedOutbound();
        VoiceCall::query()->update(['initiated_at' => now()->subMinutes(5)]);

        $this->bridged($call);

        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
        $this->assertSame('authorization_expired', $call->fresh()->hangup_cause);
    }

    public function test_a_provider_failure_while_bridging_closes_the_call_and_releases_our_leg(): void
    {
        [$call] = $this->startedOutbound();
        $this->telnyx->failNext = new \App\Library\Voice\VoiceProviderException('transfer_failed', 'nope');

        $this->bridged($call);

        $this->assertSame('bridge_failed', $call->fresh()->hangup_cause);
        $this->assertCount(1, $this->telnyx->callsTo('hangupCall'));
    }

    /* ------------------------------------------------------------------- inbound calls */

    private function inboundSetup(bool $online = true): array
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business, '+14155550199', true, $user->id);
        $this->postJson($this->voiceUrl($workspace, $business, 'token'), ['number_uid' => $number->uid])->assertOk();

        if ($online) {
            $this->postJson($this->voiceUrl($workspace, $business, 'heartbeat'), ['number_uid' => $number->uid])->assertOk();
        }

        return [$business, $workspace, $user, $number];
    }

    private function incoming(string $session = 'in-1', string $from = '+12025550111', string $to = '+14155550199'): array
    {
        return ['call_session_id' => $session, 'call_control_id' => 'cc-' . $session, 'call_leg_id' => 'lg-' . $session, 'direction' => 'incoming', 'from' => $from, 'to' => $to];
    }

    public function test_an_incoming_call_rings_the_assigned_online_user_and_links_the_contact(): void
    {
        [$business, , $user] = $this->inboundSetup();
        $contact = $this->contactFor($business);

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming())->assertOk()->assertJson(['status' => 'applied']);

        $call = VoiceCall::firstOrFail();
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('ringing', $call->status);
        $this->assertSame((int) $contact->id, (int) $call->contact_id);
        $this->assertSame((int) $user->id, (int) $call->user_id);
        $this->assertSame((int) $business->id, (int) $call->business_id);
        $this->assertSame('+12025550111', $call->from_number);

        $transfer = $this->telnyx->callsTo('transferToSip');
        $this->assertCount(1, $transfer);
        $this->assertSame('cc-in-1', $transfer[0]['callControlId']);
        $this->assertStringStartsWith('sip:gencred', $transfer[0]['sipUri']);
        $this->assertSame('platform-key-never-leaks', $transfer[0]['apiKey']);
        $this->assertSame([], $this->telnyx->callsTo('rejectCall'), 'It is never auto-answered or turned away while the user is online.');
    }

    public function test_an_unknown_caller_is_recorded_by_number_only(): void
    {
        $this->inboundSetup();

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming('in-2', '+12025558888'))->assertOk();

        $call = VoiceCall::firstOrFail();
        $this->assertNull($call->contact_id);
        $this->assertSame('+12025558888', $call->counterpart_number);
    }

    public function test_an_offline_assigned_user_means_a_missed_call_with_the_real_reason(): void
    {
        $this->inboundSetup(online: false);

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming())->assertOk();

        $call = VoiceCall::firstOrFail();
        $this->assertSame('missed', $call->status);
        $this->assertSame('assigned_user_offline', $call->hangup_cause);
        $this->assertCount(1, $this->telnyx->callsTo('rejectCall'));
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
    }

    public function test_a_stale_heartbeat_counts_as_offline(): void
    {
        $this->inboundSetup();
        \App\Models\VoiceRegistration::query()->update(['last_seen_at' => now()->subMinutes(10)]);

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming())->assertOk();

        $this->assertSame('assigned_user_offline', VoiceCall::firstOrFail()->hangup_cause);
    }

    public function test_a_number_with_nobody_assigned_turns_the_call_away_and_records_it(): void
    {
        [$business, $workspace, $user, $number] = $this->inboundSetup();
        $number->forceFill(['inbound_user_id' => null])->save();

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming())->assertOk();

        $this->assertSame('no_assigned_user', VoiceCall::firstOrFail()->hangup_cause);
        $this->assertSame('missed', VoiceCall::firstOrFail()->status);
    }

    public function test_a_failed_routing_attempt_is_a_recorded_miss_not_a_silent_ring(): void
    {
        $this->inboundSetup();
        $this->telnyx->failNext = new \App\Library\Voice\VoiceProviderException('transfer_failed', 'nope');

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming())->assertOk();

        $this->assertSame('routing_failed', VoiceCall::firstOrFail()->hangup_cause);
        $this->assertCount(1, $this->telnyx->callsTo('rejectCall'));
    }

    public function test_answered_declined_and_caller_gave_up_are_recorded_accurately(): void
    {
        $this->inboundSetup();
        $t0 = now();

        // Answered by the user, then the caller hangs up.
        $this->managedVoiceWebhook('a1', 'call.initiated', $this->incoming('s-a'))->assertOk();
        $this->managedVoiceWebhook('a2', 'call.answered', $this->incoming('s-a') + ['_at' => $t0->toIso8601String()])->assertOk();
        $this->managedVoiceWebhook('a3', 'call.hangup', $this->incoming('s-a') + ['hangup_cause' => 'normal_clearing', 'end_time' => $t0->copy()->addSeconds(30)->toIso8601String(), '_at' => $t0->copy()->addSeconds(30)->toIso8601String()])->assertOk();
        // Declined.
        $this->managedVoiceWebhook('d1', 'call.initiated', $this->incoming('s-d'))->assertOk();
        $this->managedVoiceWebhook('d2', 'call.hangup', $this->incoming('s-d') + ['hangup_cause' => 'call_rejected', 'hangup_source' => 'callee'])->assertOk();
        // Caller gave up while it rang.
        $this->managedVoiceWebhook('m1', 'call.initiated', $this->incoming('s-m'))->assertOk();
        $this->managedVoiceWebhook('m2', 'call.hangup', $this->incoming('s-m') + ['hangup_cause' => 'originator_cancel', 'hangup_source' => 'caller'])->assertOk();

        $this->assertSame(['completed', 'declined', 'missed'], VoiceCall::orderBy('id')->pluck('status')->all());
        $this->assertSame(30, VoiceCall::orderBy('id')->first()->duration_seconds);
    }

    public function test_a_replayed_incoming_event_rings_only_once(): void
    {
        $this->inboundSetup();

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming())->assertOk();
        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming())->assertJson(['status' => 'duplicate']);
        $this->managedVoiceWebhook('i2', 'call.initiated', $this->incoming())->assertOk();

        $this->assertSame(1, VoiceCall::count());
        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'));
    }

    public function test_a_hangup_arriving_before_the_initiation_still_makes_one_correct_record(): void
    {
        $this->inboundSetup();

        $this->managedVoiceWebhook('m2', 'call.hangup', $this->incoming('s-o') + ['hangup_cause' => 'originator_cancel'])->assertOk();
        $this->managedVoiceWebhook('m1', 'call.initiated', $this->incoming('s-o'))->assertOk();

        $this->assertSame(1, VoiceCall::count());
        $this->assertSame('missed', VoiceCall::firstOrFail()->status);
    }

    /* ------------------------------------------------ routing scope: managed vs BYO vs tenants */

    public function test_the_managed_webhook_never_attributes_an_agencys_own_number(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);

        $this->managedVoiceWebhook('i1', 'call.initiated', $this->incoming('s-b', '+12025550111', $byo->phone_number))->assertJson(['status' => 'unmatched']);

        $this->assertSame(0, VoiceCall::count());
    }

    public function test_one_agencys_webhook_cannot_attribute_calls_to_another_agencys_number(): void
    {
        [$bizA, $wsA] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency A');
        $numA = $this->byoVoiceNumber($wsA, $bizA, '12025550142');
        [, $bizB, $wsB] = $this->tenant(WorkspacePlanTier::Agency, 'Agency B', 'Agency B WS');
        $numB = $this->byoVoiceNumber($wsB->fresh(), $bizB->fresh(), '12025550143');
        $channelB = \App\Models\AgencyProspectingChannel::findOrFail($numB->agency_channel_id);

        // B's validly signed webhook claims a call to A's number.
        $this->byoVoiceWebhook($channelB, 'x1', 'call.initiated', $this->incoming('s-x', '+12025550111', $numA->phone_number))->assertJson(['status' => 'unmatched']);

        $this->assertSame(0, VoiceCall::count());
    }

    public function test_a_byo_inbound_call_rings_through_the_agencys_own_account(): void
    {
        [$agencyBusiness, $agencyWorkspace, $user] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness, '12025550142', true, $user->id);
        $this->postJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'token'), ['number_uid' => $byo->uid])->assertOk();
        $this->postJson($this->voiceUrl($agencyWorkspace, $agencyBusiness, 'heartbeat'), ['number_uid' => $byo->uid])->assertOk();
        $channel = \App\Models\AgencyProspectingChannel::findOrFail($byo->agency_channel_id);

        $this->byoVoiceWebhook($channel, 'b1', 'call.initiated', $this->incoming('s-byo', '+12025550111', '+12025550142'))->assertOk();

        $this->assertSame('ringing', VoiceCall::firstOrFail()->status);
        $this->assertSame('agency-private-key', $this->telnyx->callsTo('transferToSip')[0]['apiKey']);
    }

    public function test_a_disconnected_agency_channel_no_longer_accepts_voice_webhooks(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);
        $channel = \App\Models\AgencyProspectingChannel::findOrFail($byo->agency_channel_id);
        $channel->forceFill(['status' => 'disconnected'])->save();

        $this->byoVoiceWebhook($channel, 'b1', 'call.initiated', $this->incoming('s', '+12025550111', $byo->phone_number))->assertStatus(404);
        $this->assertSame(0, VoiceCall::count());
    }

    public function test_the_provider_outcome_mapping_is_a_pure_function(): void
    {
        $map = fn (string $dir, bool $answered, ?string $cause, ?string $src = null) => \App\Library\Voice\VoiceCallReconciler::outcome($dir, $answered, $cause, $src)->value;

        $this->assertSame('completed', $map('outbound', true, 'anything'));
        $this->assertSame('busy', $map('inbound', false, 'user_busy'));
        $this->assertSame('declined', $map('inbound', false, 'call_rejected'));
        $this->assertSame('missed', $map('inbound', false, 'normal_clearing'));
        $this->assertSame('failed', $map('outbound', false, null));
    }

    public function test_the_ledger_ids_are_unique_so_a_race_cannot_double_apply(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        VoiceCallEvent::create(['provider_event_id' => 'dup', 'event_type' => 'call.hangup', 'applied' => 'applied']);
        VoiceCallEvent::create(['provider_event_id' => 'dup', 'event_type' => 'call.hangup', 'applied' => 'applied']);
    }
}
