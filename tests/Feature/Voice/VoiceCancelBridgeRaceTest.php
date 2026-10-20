<?php

namespace Tests\Feature\Voice;

use App\Library\Voice\VoiceOutboundDialer;
use App\Library\Voice\VoiceProviderException;
use App\Models\Business;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Voice\Concerns\BuildsVoiceFixtures;
use Tests\TestCase;

/**
 * Cancel vs. bridge, correction round 2.
 *
 * PHP cannot run two requests at once inside a test, so every race is replayed DETERMINISTICALLY: the second
 * actor is injected at the exact instant the first one is paused — between the bridge's claim and its dispatch
 * (a Gate callback, which the bridge evaluates there) or while the transfer request is in flight (a one-shot hook
 * in the fake provider). The row-level serialization itself (SELECT ... FOR UPDATE) is what makes the two
 * interleavings below the only possible ones; each is asserted here with its exact provider traffic.
 */
class VoiceCancelBridgeRaceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsVoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoice();
    }

    private function startedOutbound(): array
    {
        [$business, $workspace, $user] = $this->signedInBusiness();
        $number = $this->managedVoiceNumber($business);
        $this->browserOnline($workspace, $business, $number);
        $contact = $this->contactFor($business);
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->json('call.uid');

        return [VoiceCall::where('uid', $uid)->firstOrFail(), $business, $workspace, $number];
    }

    private function legA(VoiceCall $call, array $extra = []): array
    {
        $call = $call->fresh();

        return array_merge([
            'call_session_id' => $call->provider_call_session_id, 'call_control_id' => $call->provider_call_control_id, 'call_leg_id' => $call->provider_leg_id,
            'direction' => 'outgoing', 'from' => $call->to_number, 'to' => 'sip:gencred1@sip.telnyx.com',
            'client_state' => base64_encode($call->uid),
        ], $extra);
    }

    private function legB(VoiceCall $call, array $extra = []): array
    {
        $call = $call->fresh();

        return array_merge([
            'call_session_id' => $call->provider_call_session_id, 'call_control_id' => 'pstn-leg-' . $call->id, 'call_leg_id' => 'pstn-lg-' . $call->id,
            'direction' => 'outgoing', 'from' => $call->from_number, 'to' => $call->to_number,
        ], $extra);
    }

    /** What the cancel button does server-side, as the dialer sees it. */
    private function cancelNow(VoiceCall $call): bool
    {
        $call = $call->fresh();

        return app(VoiceOutboundDialer::class)->cancel(
            $call,
            VoiceNumber::query()->findOrFail($call->voice_number_id),
            Business::query()->findOrFail($call->business_id),
        );
    }

    private function hangupTargets(): array
    {
        return array_column($this->telnyx->callsTo('hangupCall'), 'callControlId');
    }

    public function test_cancel_that_wins_before_dispatch_means_no_transfer_is_ever_sent(): void
    {
        [$call] = $this->startedOutbound();
        $fired = false;

        // The bridge has claimed the call and is authorizing it; the user's Cancel commits at exactly this point.
        Gate::after(function () use ($call, &$fired) {
            if (! $fired) {
                $fired = true;
                $this->cancelNow($call);
            }
        });

        $this->managedVoiceWebhook('a-1', 'call.answered', $this->legA($call))->assertOk();

        $row = $call->fresh();
        $this->assertTrue($fired);
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'), 'Cancel won: the customer must never be dialed.');
        $this->assertSame('canceled', $row->status);
        $this->assertNotNull($row->cancel_requested_at);
        $this->assertNull($row->bridge_dispatched_at);
        $this->assertSame([$call->fresh()->provider_call_control_id], $this->hangupTargets());
        $this->assertSame('done', $row->leg_teardown_status);
    }

    public function test_cancel_that_arrives_while_the_transfer_is_in_flight_tears_down_both_legs(): void
    {
        [$call] = $this->startedOutbound();
        $this->telnyx->beforeCall['transferToSip'] = fn () => $this->cancelNow($call);

        $this->managedVoiceWebhook('a-1', 'call.answered', $this->legA($call))->assertOk();

        $row = $call->fresh();
        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'), 'The transfer was already dispatched when Cancel arrived.');
        $this->assertNotNull($row->bridge_dispatched_at);
        $this->assertSame('canceled', $row->status);
        // Cancel hung our leg up, and the bridge noticed the cancel after the transfer returned and did it again.
        $this->assertContains($row->provider_call_control_id, $this->hangupTargets());
        $this->assertSame('done', $row->leg_teardown_status);

        // The customer leg Telnyx created from that transfer shows up late: it is hung up, never left ringing.
        $this->managedVoiceWebhook('b-1', 'call.initiated', $this->legB($call))->assertOk();

        $row = $call->fresh();
        $this->assertSame('pstn-leg-' . $call->id, $row->customer_call_control_id);
        $this->assertContains('pstn-leg-' . $call->id, $this->hangupTargets());
        $this->assertSame('canceled', $row->status, 'A late leg cannot reopen a canceled call.');
        $this->assertSame('done', $row->leg_teardown_status);
    }

    public function test_cancel_during_the_provider_dial_hangs_up_the_leg_that_dial_creates(): void
    {
        [$business, $workspace, , $number] = (function () {
            [$business, $workspace, $user] = $this->signedInBusiness();
            $number = $this->managedVoiceNumber($business);
            $this->browserOnline($workspace, $business, $number);

            return [$business, $workspace, $user, $number];
        })();
        $contact = $this->contactFor($business);

        $this->telnyx->beforeCall['dial'] = fn () => $this->cancelNow(VoiceCall::query()->latest('id')->firstOrFail());

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->assertSuccessful();

        $call = VoiceCall::query()->latest('id')->firstOrFail();
        $this->assertSame('canceled', $call->status);
        $this->assertContains($call->provider_call_control_id, $this->hangupTargets(), 'The leg created after the cancel is not left ringing.');
        $this->assertSame('done', $call->leg_teardown_status);
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
    }

    public function test_a_failed_hangup_is_recorded_logged_reported_and_retried_never_swallowed(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();
        $this->telnyx->hangupOutcomes = [new VoiceProviderException('hangup_failed', 'Telnyx could not end the call.')];
        Log::shouldReceive('warning')->once()->withArgs(fn ($m, $ctx) => $m === 'voice.leg_teardown_failed' && $ctx['reason'] === 'hangup_failed');
        Log::shouldReceive('info', 'debug', 'error', 'notice')->zeroOrMoreTimes();

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid . '/cancel')
            ->assertOk()
            ->assertJson(['status' => 'success', 'provider_hangup' => 'pending']);

        $row = $call->fresh();
        $this->assertSame('canceled', $row->status, 'The user sees the call canceled even while Telnyx is unreachable.');
        $this->assertSame('failed', $row->leg_teardown_status);
        $this->assertSame('hangup_failed', $row->leg_teardown_error);
        $this->assertSame(1, (int) $row->leg_teardown_attempts);

        // Too soon: the retry sweep leaves it alone, then picks it up and the (now healthy) provider confirms.
        $this->artisan('voice:retry-teardowns')->assertSuccessful();
        $this->assertCount(1, $this->telnyx->callsTo('hangupCall'));

        $this->travel(30)->seconds();
        $this->artisan('voice:retry-teardowns')->assertSuccessful();

        $row = $call->fresh();
        $this->assertCount(2, $this->telnyx->callsTo('hangupCall'));
        $this->assertSame('done', $row->leg_teardown_status);
        $this->assertNull($row->leg_teardown_error);
        $this->assertSame(2, (int) $row->leg_teardown_attempts);

        // Settled: a further sweep or a repeated cancel makes no more provider calls.
        $this->travel(60)->seconds();
        $this->artisan('voice:retry-teardowns')->assertSuccessful();
        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid . '/cancel')->assertOk()->assertJson(['provider_hangup' => 'confirmed']);
        $this->assertCount(2, $this->telnyx->callsTo('hangupCall'));
    }

    public function test_retries_are_bounded(): void
    {
        [$call] = $this->startedOutbound();
        $this->telnyx->hangupOutcomes = array_fill(0, 20, new VoiceProviderException('hangup_failed', 'x'));
        $this->cancelNow($call);

        for ($i = 0; $i < 10; $i++) {
            $this->travel(30)->seconds();
            $this->artisan('voice:retry-teardowns')->assertSuccessful();
        }

        $this->assertCount(VoiceOutboundDialer::TEARDOWN_MAX_ATTEMPTS, $this->telnyx->callsTo('hangupCall'));
        $this->assertSame('failed', $call->fresh()->leg_teardown_status, 'An exhausted teardown stays visibly failed for reconciliation.');
    }

    public function test_a_failed_bridge_whose_cleanup_hangup_also_fails_is_retried(): void
    {
        [$call] = $this->startedOutbound();
        $this->telnyx->failNext = new VoiceProviderException('transfer_failed', 'nope');
        $this->telnyx->hangupOutcomes = [new VoiceProviderException('hangup_failed', 'nope')];

        $this->managedVoiceWebhook('a-1', 'call.answered', $this->legA($call))->assertOk();

        $row = $call->fresh();
        $this->assertSame('bridge_failed', $row->hangup_cause);
        $this->assertSame('failed', $row->leg_teardown_status);

        $this->travel(30)->seconds();
        $this->artisan('voice:retry-teardowns')->assertSuccessful();
        $this->assertSame('done', $call->fresh()->leg_teardown_status);
    }

    public function test_late_and_duplicate_webhooks_after_a_cancel_cannot_bridge_or_change_the_outcome(): void
    {
        [$call] = $this->startedOutbound();
        $this->telnyx->hangupOutcomes = [new VoiceProviderException('hangup_failed', 'x')];
        $this->cancelNow($call);

        // Overtaking answer, a duplicate of it (new event id) and a byte-identical replay.
        $this->managedVoiceWebhook('a-1', 'call.answered', $this->legA($call))->assertOk();
        $this->managedVoiceWebhook('a-2', 'call.answered', $this->legA($call))->assertOk();
        $this->managedVoiceWebhook('a-2', 'call.answered', $this->legA($call))->assertOk();

        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
        $this->assertSame('canceled', $call->fresh()->status);
        $this->assertSame('failed', $call->fresh()->leg_teardown_status);

        // Telnyx's own hangup for our leg confirms it is gone: the owed teardown is settled without another call.
        $hangups = count($this->telnyx->callsTo('hangupCall'));
        $this->managedVoiceWebhook('h-1', 'call.hangup', $this->legA($call, ['hangup_cause' => 'originator_cancel', 'hangup_source' => 'caller']))->assertOk();
        $this->managedVoiceWebhook('h-1', 'call.hangup', $this->legA($call, ['hangup_cause' => 'originator_cancel', 'hangup_source' => 'caller']))->assertOk();

        $row = $call->fresh();
        $this->assertSame('canceled', $row->status);
        $this->assertSame('done', $row->leg_teardown_status);
        $this->assertSame($hangups, count($this->telnyx->callsTo('hangupCall')));
    }

    public function test_a_browser_ended_report_never_overwrites_a_cancel_and_one_before_the_bridge_owes_a_hangup(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();
        $url = $this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid;

        $this->cancelNow($call);
        $this->postJson("$url/client-event", ['event' => 'ended'])->assertOk()->assertJson(['call_status' => 'canceled']);
        $this->assertNull($call->fresh()->client_reported_ended_at);

        // A second call: the browser reports the end before the leg was answered; the answer then arrives late.
        [$call2, $business2, $workspace2] = $this->startedOutbound2($business, $workspace);
        $this->postJson($this->voiceUrl($workspace2, $business2, 'calls.start') . '/' . $call2->uid . '/client-event', ['event' => 'ended'])->assertOk();
        $this->assertSame('pending', $call2->fresh()->leg_teardown_status);

        $this->managedVoiceWebhook('a-9', 'call.answered', $this->legA($call2))->assertOk();
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));

        $this->artisan('voice:retry-teardowns')->assertSuccessful();
        $this->assertContains($call2->fresh()->provider_call_control_id, $this->hangupTargets());
        $this->assertSame('done', $call2->fresh()->leg_teardown_status);
    }

    private function startedOutbound2(Business $business, $workspace): array
    {
        $number = VoiceNumber::query()->where('business_id', $business->id)->firstOrFail();
        $contact = $this->contactFor($business, '12025550122', 'Noa', 'Reed');
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->assertSuccessful()->json('call.uid');

        return [VoiceCall::where('uid', $uid)->firstOrFail(), $business, $workspace];
    }

    public function test_bridge_that_wins_is_not_undone_by_a_later_replayed_answer_and_cancel_then_hangs_up(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();

        $this->managedVoiceWebhook('a-1', 'call.answered', $this->legA($call))->assertOk();
        $this->managedVoiceWebhook('a-2', 'call.answered', $this->legA($call))->assertOk();
        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'), 'Exactly one transfer however many answers arrive.');

        $this->postJson($this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid . '/cancel')->assertOk();

        $row = $call->fresh();
        $this->assertNotNull($row->cancel_requested_at);
        $this->assertContains($row->provider_call_control_id, $this->hangupTargets(), 'Cancel after the bridge still tears the leg down.');
        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'));
    }
}
