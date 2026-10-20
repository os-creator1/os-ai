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
        $contact = $this->contactFor($business);
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->json('call.uid');

        return [VoiceCall::where('uid', $uid)->firstOrFail(), $business, $workspace, $user, $number, $contact];
    }

    private function outboundLeg(VoiceCall $call, string $session = 'sess-1', array $extra = []): array
    {
        return array_merge([
            'call_session_id' => $session, 'call_control_id' => 'ccid-' . $session, 'call_leg_id' => 'leg-' . $session,
            'direction' => 'incoming', 'from' => 'sip:user@sip.telnyx.com', 'to' => $call->to_number,
            'client_state' => base64_encode($call->uid),
        ], $extra);
    }

    /* --------------------------------------------------------------- verification */

    public function test_unsigned_badly_signed_and_stale_events_change_nothing(): void
    {
        [$call] = $this->startedOutbound();
        $payload = $this->outboundLeg($call);
        $url = route('voice.webhooks.telnyx_managed');
        $body = json_encode(['data' => ['id' => 'e1', 'event_type' => 'call.initiated', 'payload' => $payload]]);

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(403);

        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->managedVoiceWebhook('e2', 'call.initiated', $payload, $other)->assertStatus(403);
        $this->managedVoiceWebhook('e3', 'call.initiated', $payload, null, now()->subMinutes(30)->getTimestamp())->assertStatus(403);

        $this->assertSame(0, VoiceCallEvent::count());
        $this->assertNull($call->fresh()->provider_call_session_id);
    }

    public function test_the_managed_webhook_is_inert_until_managed_voice_is_enabled(): void
    {
        [$call] = $this->startedOutbound();
        config(['services.telnyx.voice_enabled' => false]);

        $this->managedVoiceWebhook('e1', 'call.initiated', $this->outboundLeg($call))->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(0, VoiceCallEvent::count());
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

    /* -------------------------------------------------------- outgoing call lifecycle */

    public function test_a_confirmed_outgoing_call_completes_with_the_real_duration(): void
    {
        [$call, $business, , $user] = $this->startedOutbound();
        $t0 = now();

        $this->managedVoiceWebhook('e1', 'call.initiated', $this->outboundLeg($call) + ['_at' => $t0->toIso8601String()])->assertOk()->assertJson(['status' => 'applied']);
        $this->assertSame('sess-1', $call->fresh()->provider_call_session_id);
        $this->assertSame('initiating', $call->fresh()->status);

        $this->managedVoiceWebhook('e2', 'call.answered', $this->outboundLeg($call) + ['_at' => $t0->copy()->addSeconds(4)->toIso8601String()])->assertOk();
        $this->assertSame('answered', $call->fresh()->status);

        $this->managedVoiceWebhook('e3', 'call.hangup', $this->outboundLeg($call) + [
            'hangup_cause' => 'normal_clearing', 'hangup_source' => 'callee',
            'end_time' => $t0->copy()->addSeconds(94)->toIso8601String(), '_at' => $t0->copy()->addSeconds(94)->toIso8601String(),
        ])->assertOk();

        $done = $call->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertSame(90, $done->duration_seconds);
        $this->assertNotNull($done->answered_at);
        $this->assertNotNull($done->ended_at);
        $this->assertSame('ccid-sess-1', $done->provider_call_control_id);
        $this->assertSame((int) $user->id, (int) $done->user_id);
        $this->assertSame((int) $business->id, (int) $done->business_id);
    }

    public function test_the_browser_saying_ended_is_upgraded_not_trusted(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();
        $this->managedVoiceWebhook('e1', 'call.initiated', $this->outboundLeg($call))->assertOk();
        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid . '/client-event', ['event' => 'ended'])->assertOk();
        $this->assertSame('unconfirmed', $call->fresh()->status);

        $this->managedVoiceWebhook('e2', 'call.hangup', $this->outboundLeg($call) + ['hangup_cause' => 'user_busy'])->assertOk();

        $this->assertSame('busy', $call->fresh()->status);
    }

    #[DataProvider('outboundOutcomes')]
    public function test_outgoing_outcomes_follow_the_provider_cause(string $cause, ?string $source, string $expected): void
    {
        [$call] = $this->startedOutbound();
        $this->managedVoiceWebhook('e1', 'call.initiated', $this->outboundLeg($call))->assertOk();
        $this->managedVoiceWebhook('e2', 'call.hangup', $this->outboundLeg($call) + array_filter(['hangup_cause' => $cause, 'hangup_source' => $source]))->assertOk();

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

    /* ----------------------------------------- 10. duplicates, replays, stale, out of order */

    public function test_a_repeated_event_creates_nothing_and_changes_nothing(): void
    {
        [$call] = $this->startedOutbound();
        $leg = $this->outboundLeg($call);

        $this->managedVoiceWebhook('same-id', 'call.initiated', $leg)->assertJson(['status' => 'applied']);
        $this->managedVoiceWebhook('same-id', 'call.initiated', $leg)->assertOk()->assertJson(['status' => 'duplicate']);
        $this->managedVoiceWebhook('ans-1', 'call.answered', $leg)->assertOk();
        $this->managedVoiceWebhook('ans-1', 'call.answered', $leg)->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, VoiceCall::count());
        $this->assertSame(2, VoiceCallEvent::count());
    }

    public function test_a_replay_of_the_final_event_with_a_new_id_does_not_change_the_outcome(): void
    {
        [$call] = $this->startedOutbound();
        $leg = $this->outboundLeg($call);
        $this->managedVoiceWebhook('e1', 'call.initiated', $leg)->assertOk();
        $this->managedVoiceWebhook('e2', 'call.answered', $leg)->assertOk();
        $this->managedVoiceWebhook('e3', 'call.hangup', $leg + ['hangup_cause' => 'normal_clearing'])->assertOk();
        $finished = $call->fresh();

        $this->managedVoiceWebhook('e4', 'call.hangup', $leg + ['hangup_cause' => 'call_rejected', 'end_time' => now()->addHour()->toIso8601String()])->assertJson(['status' => 'ignored']);

        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame($finished->duration_seconds, $call->fresh()->duration_seconds);
        $this->assertSame($finished->ended_at->toIso8601String(), $call->fresh()->ended_at->toIso8601String());
    }

    public function test_a_late_ringing_or_initiated_event_never_reopens_a_finished_call(): void
    {
        [$call] = $this->startedOutbound();
        $leg = $this->outboundLeg($call);
        $this->managedVoiceWebhook('e1', 'call.initiated', $leg)->assertOk();
        $this->managedVoiceWebhook('e2', 'call.answered', $leg)->assertOk();
        $this->managedVoiceWebhook('e3', 'call.hangup', $leg + ['hangup_cause' => 'normal_clearing'])->assertOk();

        $this->managedVoiceWebhook('late-init', 'call.initiated', $leg, null)->assertJson(['status' => 'ignored']);
        $this->managedVoiceWebhook('late-ans', 'call.answered', $leg)->assertJson(['status' => 'ignored']);

        $this->assertSame('completed', $call->fresh()->status);
    }

    public function test_a_hangup_that_overtakes_the_answer_is_corrected_when_the_answer_arrives(): void
    {
        [$call] = $this->startedOutbound();
        $leg = $this->outboundLeg($call);
        $t0 = now();
        $this->managedVoiceWebhook('e1', 'call.initiated', $leg)->assertOk();

        // The hangup is processed first (no answered_at yet) …
        $this->managedVoiceWebhook('e3', 'call.hangup', $leg + ['hangup_cause' => 'normal_clearing', 'hangup_source' => 'callee', 'end_time' => $t0->copy()->addSeconds(60)->toIso8601String(), '_at' => $t0->copy()->addSeconds(60)->toIso8601String()])->assertOk();
        $this->assertSame('failed', $call->fresh()->status);

        // … then the earlier answer shows up.
        $this->managedVoiceWebhook('e2', 'call.answered', $leg + ['_at' => $t0->copy()->addSeconds(10)->toIso8601String()])->assertOk()->assertJson(['status' => 'applied']);

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

    public function test_a_call_to_a_different_destination_than_the_one_validated_is_not_attributed(): void
    {
        [$call] = $this->startedOutbound();

        $this->managedVoiceWebhook('e1', 'call.initiated', $this->outboundLeg($call, 'sess-x', ['to' => '+12025559999']))->assertJson(['status' => 'unmatched']);

        $this->assertNull($call->fresh()->provider_call_session_id);
    }

    public function test_other_event_types_are_acknowledged_without_effect(): void
    {
        [$call] = $this->startedOutbound();

        $this->managedVoiceWebhook('rec', 'call.recording.saved', $this->outboundLeg($call))->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame('initiating', $call->fresh()->status);
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
