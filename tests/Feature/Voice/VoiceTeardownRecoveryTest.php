<?php

namespace Tests\Feature\Voice;

use App\Library\Voice\VoiceOutboundDialer;
use App\Library\Voice\VoiceProviderException;
use App\Models\Business;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Voice\Concerns\BuildsVoiceFixtures;
use Tests\TestCase;

/**
 * Correction round 3: teardown recovery, exhausted-retry alerting, missing-number cancel, the late-leg / teardown
 * state race, abandoned-bridge recovery and ambiguous transfers. Provider traffic is the in-memory fake.
 */
class VoiceTeardownRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use BuildsVoiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVoice();
    }

    private function startedOutbound(?array $ctx = null, string $phone = '12025550111'): array
    {
        if ($ctx === null) {
            [$business, $workspace] = $this->signedInBusiness();
            $number = $this->managedVoiceNumber($business);
            $this->browserOnline($workspace, $business, $number);
        } else {
            [$business, $workspace, $number] = $ctx;
        }

        $contact = $this->contactFor($business, $phone, 'Noa', 'Reed');
        $uid = $this->postJson($this->voiceUrl($workspace, $business, 'calls.start'), $this->startPayload($number, $contact))->assertSuccessful()->json('call.uid');

        return [VoiceCall::where('uid', $uid)->firstOrFail(), $business, $workspace, $number];
    }

    private function legA(VoiceCall $call, array $extra = []): array
    {
        $call = $call->fresh();

        return array_merge([
            'call_session_id' => $call->provider_call_session_id, 'call_control_id' => $call->provider_call_control_id, 'call_leg_id' => $call->provider_leg_id,
            'direction' => 'outgoing', 'from' => $call->to_number, 'to' => 'sip:gencred1@sip.telnyx.com', 'client_state' => base64_encode($call->uid),
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

    private function dialer(): VoiceOutboundDialer
    {
        return app(VoiceOutboundDialer::class);
    }

    private function hangupTargets(): array
    {
        return array_column($this->telnyx->callsTo('hangupCall'), 'callControlId');
    }

    private function cancelUrl(VoiceCall $call, $workspace, Business $business): string
    {
        return $this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid . '/cancel';
    }

    /* ------------------------------------------------------------------ 1. scheduler */

    public function test_the_teardown_command_is_in_the_application_scheduler_with_a_five_minute_overlap_lock(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'voice:retry-teardowns'));

        $this->assertNotNull($event, 'voice:retry-teardowns must be registered in App\Console\Kernel::schedule().');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt, 'The default 24-hour mutex would stop recovery for a day after one killed run.');
    }

    /* ------------------------------------------------------------------ 2. per-call isolation */

    public function test_an_unexpected_failure_on_the_oldest_call_does_not_starve_the_next_and_leaks_nothing(): void
    {
        [$first, $business, $workspace, $number] = $this->startedOutbound();
        $this->telnyx->hangupOutcomes = [new VoiceProviderException('hangup_failed', 'x')];
        $this->postJson($this->cancelUrl($first, $workspace, $business))->assertOk();

        [$second] = $this->startedOutbound([$business, $workspace, $number], '12025550122');
        $this->telnyx->hangupOutcomes = [new VoiceProviderException('hangup_failed', 'x')];
        $this->postJson($this->cancelUrl($second, $workspace, $business))->assertOk();

        $this->assertSame('failed', $first->fresh()->leg_teardown_status);
        $this->assertSame('failed', $second->fresh()->leg_teardown_status);

        // At the sweep the OLDEST call blows up with something unexpected (with a would-be secret in its message).
        $this->telnyx->hangupOutcomes = [new RuntimeException('Bearer sk-live-SECRET-TOKEN decrypt failed'), null];
        $this->travel(30)->seconds();
        $this->artisan('voice:retry-teardowns')->assertSuccessful();

        $a = $first->fresh();
        $b = $second->fresh();
        $this->assertSame('failed', $a->leg_teardown_status, 'Never claims success for the call that threw.');
        $this->assertSame('unexpected_error', $a->leg_teardown_error);
        $this->assertSame(2, (int) $a->leg_teardown_attempts);
        $this->assertSame('done', $b->leg_teardown_status, 'The next eligible call is still processed.');
        $this->assertStringNotContainsString('SECRET', json_encode([$a->getAttributes(), $b->getAttributes()]));
    }

    /* ------------------------------------------------------------------ 3. exhausted */

    public function test_exhausted_teardown_raises_one_error_log_with_safe_identifiers_and_stays_failed(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();
        $this->telnyx->hangupOutcomes = array_fill(0, 30, new VoiceProviderException('hangup_failed', 'x'));

        Log::shouldReceive('error')->once()->withArgs(function ($message, $context) use ($call) {
            return $message === 'voice.leg_teardown_exhausted'
                && $context['call'] === $call->uid
                && $context['provider_leg_may_remain'] === true
                && $context['attempts'] === VoiceOutboundDialer::TEARDOWN_MAX_ATTEMPTS
                && ! str_contains(json_encode($context), 'platform-key') && ! array_key_exists('api_key', $context);
        });
        Log::shouldReceive('warning', 'info', 'debug', 'notice')->zeroOrMoreTimes();

        $this->postJson($this->cancelUrl($call, $workspace, $business))->assertOk();

        for ($i = 0; $i < 12; $i++) {
            $this->travel(30)->seconds();
            $this->artisan('voice:retry-teardowns')->assertSuccessful();
        }

        $row = $call->fresh();
        $this->assertSame('failed', $row->leg_teardown_status);
        $this->assertNotNull($row->leg_teardown_exhausted_at);
        $this->assertSame('attention', $row->providerHangupState());
        $this->assertCount(VoiceOutboundDialer::TEARDOWN_MAX_ATTEMPTS, $this->telnyx->callsTo('hangupCall'), 'No attempt beyond the limit.');
    }

    /* ------------------------------------------------------------------ 4. missing number */

    public function test_canceling_when_the_number_is_gone_keeps_the_cancel_and_never_reports_confirmed(): void
    {
        [$call, $business, $workspace, $number] = $this->startedOutbound();
        VoiceNumber::query()->whereKey($number->id)->delete();

        $this->postJson($this->cancelUrl($call, $workspace, $business))
            ->assertOk()
            ->assertJson(['status' => 'success', 'provider_hangup' => 'attention'])
            ->assertJsonPath('message', fn ($m) => is_string($m) && str_contains($m, "couldn't confirm"));

        $row = $call->fresh();
        $this->assertSame('canceled', $row->status, 'The user\'s intent is preserved.');
        $this->assertSame('failed', $row->leg_teardown_status);
        $this->assertSame('number_unavailable', $row->leg_teardown_error);
        $this->assertSame([], $this->telnyx->callsTo('hangupCall'));

        // The status endpoint (refresh/navigation) says the same thing.
        $this->getJson($this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid)->assertJson(['provider_hangup' => 'attention']);
    }

    public function test_a_disconnected_voice_account_is_attention_and_another_tenants_number_is_never_used(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();
        config(['services.telnyx.voice_enabled' => false]);
        $this->postJson($this->cancelUrl($call, $workspace, $business))->assertOk()->assertJson(['provider_hangup' => 'attention']);
        $this->assertSame('voice_disabled', $call->fresh()->leg_teardown_error);
        $this->assertSame([], $this->telnyx->callsTo('hangupCall'));

        // A call row whose number belongs to a DIFFERENT Business: cleanup refuses rather than borrow its identity.
        config(['services.telnyx.voice_enabled' => true]);
        [$otherBusiness] = $this->signedInBusiness(\App\Enums\Entitlement\WorkspacePlanTier::Core, 'Other Studio');
        $foreign = $this->managedVoiceNumber($otherBusiness, '+14155550177');
        $call2 = VoiceCall::create([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'workspace_id' => $workspace->id, 'voice_number_id' => $foreign->id,
            'direction' => 'outbound', 'from_number' => '+14155550177', 'to_number' => '+12025550111', 'counterpart_number' => '+12025550111',
            'status' => 'ringing', 'status_rank' => 1, 'provider_call_control_id' => 'cc-x', 'initiated_at' => now(), 'leg_teardown_status' => 'pending',
        ]);

        $this->assertFalse($this->dialer()->teardown($call2));
        $this->assertSame('number_unavailable', $call2->fresh()->leg_teardown_error);
        $this->assertSame([], $this->telnyx->callsTo('hangupCall'));
    }

    public function test_a_byo_call_is_torn_down_with_the_agencys_own_key_never_the_platforms(): void
    {
        [$agencyBusiness, $agencyWorkspace] = $this->signedInBusiness(\App\Enums\Entitlement\WorkspacePlanTier::Agency, 'Agency Co');
        $byo = $this->byoVoiceNumber($agencyWorkspace, $agencyBusiness);
        $call = VoiceCall::create([
            'uid' => (string) Str::uuid(), 'business_id' => $agencyBusiness->id, 'workspace_id' => $agencyWorkspace->id, 'voice_number_id' => $byo->id,
            'direction' => 'outbound', 'from_number' => $byo->phone_number, 'to_number' => '+12025550111', 'counterpart_number' => '+12025550111',
            'status' => 'ringing', 'status_rank' => 1, 'provider_call_control_id' => 'cc-byo', 'customer_call_control_id' => 'cc-byo-b',
            'initiated_at' => now(), 'leg_teardown_status' => 'pending',
        ]);

        $this->assertTrue($this->dialer()->teardown($call));

        $this->assertSame(['agency-private-key', 'agency-private-key'], array_column($this->telnyx->callsTo('hangupCall'), 'apiKey'));
        $this->assertSame(['cc-byo', 'cc-byo-b'], $this->hangupTargets());
    }

    /* ------------------------------------------------------------------ 5. late leg vs teardown status */

    public function test_a_late_leg_recorded_while_a_teardown_is_in_flight_cannot_be_erased_by_it(): void
    {
        [$call] = $this->startedOutbound();
        $call->forceFill(['status' => 'canceled', 'status_rank' => 4, 'cancel_requested_at' => now(), 'ended_at' => now(), 'leg_teardown_status' => 'pending'])->save();

        // While our hangup of leg A is "on the wire", the reconciler commits the customer leg (a separate committed write).
        $this->telnyx->beforeCall['hangupCall'] = fn () => VoiceCall::query()->whereKey($call->id)->update(['customer_call_control_id' => 'late-B', 'leg_teardown_status' => 'pending']);

        $this->assertFalse($this->dialer()->teardown($call), 'Only leg A was confirmed.');

        $row = $call->fresh();
        $this->assertSame('pending', $row->leg_teardown_status, 'The older successful hangup must not mark the newer leg done.');
        $this->assertSame('late-B', $row->customer_call_control_id);
        $this->assertSame(1, (int) $row->leg_teardown_attempts);

        $this->assertTrue($this->dialer()->teardown($call));
        $this->assertContains('late-B', $this->hangupTargets());
        $this->assertSame('done', $call->fresh()->leg_teardown_status);
        $this->assertSame(2, (int) $call->fresh()->leg_teardown_attempts);
    }

    /* ------------------------------------------------------------------ 6. interrupted bridges */

    public function test_a_crash_after_the_claim_is_recovered_without_any_transfer(): void
    {
        [$call] = $this->startedOutbound();
        $call->forceFill(['status' => 'ringing', 'status_rank' => 1, 'bridge_requested_at' => now()->subSeconds(VoiceOutboundDialer::ABANDONED_CLAIM_SECONDS + 10)])->save();

        $this->artisan('voice:retry-teardowns')->assertSuccessful();

        $row = $call->fresh();
        $this->assertSame('failed', $row->status);
        $this->assertSame('bridge_abandoned', $row->hangup_cause);
        $this->assertSame([$row->provider_call_control_id], $this->hangupTargets());
        $this->assertSame('done', $row->leg_teardown_status);
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));

        // Idempotent: another sweep does nothing more.
        $this->artisan('voice:retry-teardowns')->assertSuccessful();
        $this->assertCount(1, $this->telnyx->callsTo('hangupCall'));
    }

    public function test_a_crash_after_dispatch_is_closed_as_unresolved_never_retransferred_and_a_late_leg_is_hung_up(): void
    {
        [$call] = $this->startedOutbound();
        $old = now()->subSeconds(VoiceOutboundDialer::UNRESOLVED_DISPATCH_SECONDS + 10);
        $call->forceFill(['status' => 'ringing', 'status_rank' => 1, 'bridge_requested_at' => $old, 'bridge_dispatched_at' => $old])->save();

        $this->artisan('voice:retry-teardowns')->assertSuccessful();

        $row = $call->fresh();
        $this->assertSame('bridge_unresolved', $row->hangup_cause);
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'), 'An uncertain transfer is never repeated.');

        // The transfer had in fact been accepted: its customer leg appears late and is hung up. A replay does not repeat it.
        $this->managedVoiceWebhook('b-1', 'call.initiated', $this->legB($call))->assertOk();
        $this->assertContains('pstn-leg-' . $call->id, $this->hangupTargets());
        $before = count($this->telnyx->callsTo('hangupCall'));
        $this->managedVoiceWebhook('b-2', 'call.initiated', $this->legB($call))->assertOk();
        $this->managedVoiceWebhook('b-1', 'call.initiated', $this->legB($call))->assertOk();
        $this->assertCount($before, $this->telnyx->callsTo('hangupCall'));
        $this->assertSame([], $this->telnyx->callsTo('transferToSip'));
    }

    public function test_a_young_bridge_and_a_valid_ongoing_call_are_never_terminated(): void
    {
        [$young] = $this->startedOutbound();
        $young->forceFill(['status' => 'ringing', 'status_rank' => 1, 'bridge_requested_at' => now()->subSeconds(20), 'bridge_dispatched_at' => now()->subSeconds(10)])->save();

        $long = now()->subHours(2);
        $answered = VoiceCall::create([
            'uid' => (string) Str::uuid(), 'business_id' => $young->business_id, 'workspace_id' => $young->workspace_id, 'voice_number_id' => $young->voice_number_id,
            'direction' => 'outbound', 'from_number' => $young->from_number, 'to_number' => '+12025550133', 'counterpart_number' => '+12025550133',
            'status' => 'answered', 'status_rank' => 2, 'provider_call_control_id' => 'cc-long', 'customer_call_control_id' => 'cc-long-b',
            'initiated_at' => $long, 'answered_at' => $long, 'bridge_requested_at' => $long, 'bridge_dispatched_at' => $long,
        ]);
        $ringingB = VoiceCall::create([
            'uid' => (string) Str::uuid(), 'business_id' => $young->business_id, 'workspace_id' => $young->workspace_id, 'voice_number_id' => $young->voice_number_id,
            'direction' => 'outbound', 'from_number' => $young->from_number, 'to_number' => '+12025550144', 'counterpart_number' => '+12025550144',
            'status' => 'ringing', 'status_rank' => 1, 'provider_call_control_id' => 'cc-r', 'customer_call_control_id' => 'cc-r-b',
            'initiated_at' => $long, 'bridge_requested_at' => $long, 'bridge_dispatched_at' => $long,
        ]);

        $this->artisan('voice:retry-teardowns')->assertSuccessful();

        $this->assertSame('ringing', $young->fresh()->status);
        $this->assertSame('answered', $answered->fresh()->status);
        $this->assertSame('ringing', $ringingB->fresh()->status, 'A known customer leg is the provider\'s to end, not ours to guess.');
        $this->assertSame([], $this->telnyx->callsTo('hangupCall'));
    }

    /* ------------------------------------------------------------------ 8. ambiguous transfer */

    public function test_a_transfer_that_timed_out_after_telnyx_accepted_it_is_not_repeated_and_the_late_leg_is_cleaned_up(): void
    {
        [$call] = $this->startedOutbound();
        $this->telnyx->failNext = VoiceProviderException::unavailable(); // the API call "timed out"

        $this->managedVoiceWebhook('a-1', 'call.answered', $this->legA($call))->assertOk();

        $row = $call->fresh();
        $this->assertSame('bridge_uncertain', $row->hangup_cause, 'Recorded as ambiguous, not as a definitive refusal.');
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->bridge_dispatched_at);
        $this->assertNotNull($row->provider_call_session_id);
        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'));
        $this->assertSame([$row->provider_call_control_id], $this->hangupTargets());

        // Replayed / duplicated answers never create a second transfer.
        $this->managedVoiceWebhook('a-2', 'call.answered', $this->legA($call))->assertOk();
        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'));

        // Telnyx had accepted the transfer: the customer leg shows up late. It is hung up, once.
        $this->managedVoiceWebhook('b-1', 'call.initiated', $this->legB($call))->assertOk();
        $this->managedVoiceWebhook('b-2', 'call.answered', $this->legB($call))->assertOk();
        $this->managedVoiceWebhook('b-1', 'call.initiated', $this->legB($call))->assertOk();

        $this->assertSame(['pstn-leg-' . $call->id], array_values(array_filter($this->hangupTargets(), fn ($t) => $t !== $row->provider_call_control_id)));
        $this->assertSame('done', $call->fresh()->leg_teardown_status);
        $this->assertCount(1, $this->telnyx->callsTo('transferToSip'));
    }

    public function test_a_definitive_provider_refusal_is_recorded_as_bridge_failed(): void
    {
        [$call] = $this->startedOutbound();
        $this->telnyx->failNext = new VoiceProviderException('transfer_failed', 'no');

        $this->managedVoiceWebhook('a-1', 'call.answered', $this->legA($call))->assertOk();

        $this->assertSame('bridge_failed', $call->fresh()->hangup_cause);
    }

    /* ------------------------------------------------------------------ 7. status endpoint */

    public function test_the_status_endpoint_reports_pending_confirmed_and_attention_distinctly(): void
    {
        [$call, $business, $workspace] = $this->startedOutbound();
        $url = $this->voiceUrl($workspace, $business, 'calls.start') . '/' . $call->uid;

        $this->telnyx->hangupOutcomes = [new VoiceProviderException('hangup_failed', 'x')];
        $this->postJson("$url/cancel")->assertOk()->assertJson(['provider_hangup' => 'pending']);
        $this->getJson($url)->assertJson(['call_status' => 'canceled', 'provider_hangup' => 'pending']);

        $this->travel(30)->seconds();
        $this->artisan('voice:retry-teardowns')->assertSuccessful();
        $this->getJson($url)->assertJson(['provider_hangup' => 'confirmed']);

        $call->forceFill(['leg_teardown_status' => 'failed', 'leg_teardown_error' => 'hangup_failed', 'leg_teardown_exhausted_at' => now()])->save();
        $this->getJson($url)->assertJson(['provider_hangup' => 'attention']);
    }
}
