<?php

namespace App\Library\Voice;

use App\Library\Messaging\E164Normalizer;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessVoiceCall;
use App\Models\Contacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Drives a browser call's two Call Control legs from signature-verified Telnyx webhooks.
 *
 *   1. CustomerCallPlacer dials the agent's browser (SIP URI of a telephony credential)      -> leg "agent"
 *   2. the browser auto-answers -> call.answered(agent)  => this class dials the contact       -> leg "contact"
 *   3. the contact answers      -> call.answered(contact) => this class bridges the two legs   (two-way audio)
 *   4. either side hangs up     -> the other leg is hung up and the call is closed with its duration.
 *
 * Idempotent per Telnyx event id (the same unique events table the server-call recorder uses): a redelivery never
 * dials or bridges twice. Status is forward-only. Network commands run AFTER the row transaction commits, so no lock is
 * held across an HTTP call. An event is claimed by this class only when it belongs to a `channel = browser` row (found
 * by leg id, or adopted through the unguessable per-call client_state token); everything else falls through to the
 * ordinary VoiceCallStatusRecorder untouched.
 */
final class BrowserCallOrchestrator
{
    private const API_BASE = 'https://api.telnyx.com/v2';

    private const RANK = [
        BusinessVoiceCall::STATUS_REQUESTED => 0,
        BusinessVoiceCall::STATUS_FAILED => 1,
        BusinessVoiceCall::STATUS_INITIATED => 2,
        BusinessVoiceCall::STATUS_ANSWERED => 3,
        BusinessVoiceCall::STATUS_ENDED => 4,
    ];

    public function __construct(private readonly TelnyxCallDialer $dialer)
    {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string|null applied|duplicate|ignored, or null when the event is not about a browser call
     */
    public function apply(string $eventId, string $eventType, string $callControlId, array $payload, ?string $occurredAt = null): ?string
    {
        if ($callControlId === '' || ! in_array($eventType, ['call.initiated', 'call.answered', 'call.hangup'], true)) {
            return null;
        }

        $after = null;

        $result = DB::transaction(function () use ($eventId, $eventType, $callControlId, $payload, $occurredAt, &$after): ?string {
            $located = $this->locate($callControlId, $payload);
            if ($located === null) {
                return null;
            }
            [$call, $leg] = $located;

            $beforeStatus = $call->status;
            // A hangup carries its own end_time; every other event happened when the envelope says it did.
            $at = ($eventType === 'call.hangup' ? $this->parse($payload['end_time'] ?? null) : null) ?? $this->parse($occurredAt) ?? now();
            $after = $this->transition($call, $leg, $eventType, $payload, $at);
            $applied = $call->status !== $beforeStatus || $after !== null || $call->isDirty();

            try {
                $call->getConnection()->table('business_voice_call_events')->insert([
                    'provider_event_id' => $eventId,
                    'business_voice_call_id' => $call->id,
                    'event_type' => $eventType,
                    'applied' => $applied,
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $after = null;

                return VoiceCallStatusRecorder::DUPLICATE;
            }

            $call->save();

            return $applied ? VoiceCallStatusRecorder::APPLIED : VoiceCallStatusRecorder::IGNORED;
        });

        if ($after !== null) {
            $after();
        }

        return $result;
    }

    /** Operator/user hang-up from the CRM: end both legs and close the record. */
    public function userHangup(BusinessVoiceCall $call): BusinessVoiceCall
    {
        foreach ([$call->telnyx_call_control_id, $call->agent_call_control_id] as $legId) {
            if ($legId !== null && $legId !== '') {
                $this->command((string) $legId, 'hangup');
            }
        }

        DB::transaction(function () use ($call): void {
            $locked = BusinessVoiceCall::query()->whereKey($call->id)->lockForUpdate()->first();
            if ($locked === null || in_array($locked->status, [BusinessVoiceCall::STATUS_ENDED, BusinessVoiceCall::STATUS_FAILED], true)) {
                return;
            }
            $this->close($locked, now(), 'user_hangup');
            $locked->save();
        });

        return $call->refresh();
    }

    /**
     * Mutates the (locked) row for this event and returns an optional network step to run after commit.
     *
     * @param  array<string, mixed>  $payload
     */
    private function transition(BusinessVoiceCall $call, string $leg, string $eventType, array $payload, Carbon $at): ?\Closure
    {
        $status = $call->status;
        $terminal = in_array($status, [BusinessVoiceCall::STATUS_ENDED, BusinessVoiceCall::STATUS_FAILED], true);

        if ($leg === 'agent') {
            if ($eventType === 'call.answered' && $status === BusinessVoiceCall::STATUS_REQUESTED && $call->telnyx_call_control_id === null) {
                $callId = $call->id;
                $clientState = $payload['client_state'] ?? null;

                return fn () => $this->dialContact($callId, is_string($clientState) ? $clientState : '');
            }

            if ($eventType === 'call.hangup' && ! $terminal) {
                $contactLeg = $call->telnyx_call_control_id;
                if ($status === BusinessVoiceCall::STATUS_ANSWERED) {
                    $this->close($call, $at, $this->cause($payload));
                } else {
                    // The browser never connected the call (tab closed, microphone refused, no answer): it failed.
                    $call->status = BusinessVoiceCall::STATUS_FAILED;
                    $call->failure_code = $status === BusinessVoiceCall::STATUS_REQUESTED ? 'browser_not_answered' : 'browser_disconnected';
                    $call->ended_at = $at;
                    $call->hangup_cause = $this->cause($payload);
                }

                return $contactLeg !== null ? fn () => $this->command((string) $contactLeg, 'hangup') : null;
            }

            return null;
        }

        if ($leg !== 'contact') {
            return null;
        }

        if ($terminal) {
            // A contact leg that outlived its call (the browser dropped while it was being dialled) must not ring on or
            // answer into silence: any non-hangup event for it is cleaned up. Its id was adopted/saved by this event.
            if ($eventType !== 'call.hangup') {
                $leg = (string) $call->telnyx_call_control_id;

                // The answer can also be reported after the hangup was: keep the status, but record the answer time
                // and duration the earlier hangup could not compute (the same ordering the server-call recorder handles).
                if ($eventType === 'call.answered' && $status === BusinessVoiceCall::STATUS_ENDED && $call->answered_at === null) {
                    $call->answered_at = $at;
                    $call->duration_seconds = $call->ended_at !== null ? (int) max(0, $at->diffInSeconds($call->ended_at, false)) : null;
                }

                return $leg !== '' ? fn () => $this->command($leg, 'hangup') : null;
            }

            return null;
        }

        if ($eventType === 'call.initiated' && self::RANK[BusinessVoiceCall::STATUS_INITIATED] > self::RANK[$status]) {
            $call->status = BusinessVoiceCall::STATUS_INITIATED;
            $call->initiated_at ??= $at;

            return null;
        }

        if ($eventType === 'call.answered' && self::RANK[BusinessVoiceCall::STATUS_ANSWERED] > self::RANK[$status]) {
            $call->status = BusinessVoiceCall::STATUS_ANSWERED;
            $call->answered_at = $at;
            $call->failure_code = null;
            $callId = $call->id;
            $agent = (string) $call->agent_call_control_id;
            $contact = (string) $call->telnyx_call_control_id;

            return fn () => $this->bridge($callId, $agent, $contact);
        }

        if ($eventType === 'call.hangup') {
            $agentLeg = $call->agent_call_control_id;
            $this->close($call, $at, $this->cause($payload));

            return $agentLeg !== null ? fn () => $this->command((string) $agentLeg, 'hangup') : null;
        }

        return null;
    }

    /** Dial the contact once the browser leg has answered. Any failure hangs the browser leg up and fails the call. */
    private function dialContact(int $callId, string $clientStateB64): void
    {
        $call = BusinessVoiceCall::query()->find($callId);
        if ($call === null || $call->status !== BusinessVoiceCall::STATUS_REQUESTED || $call->telnyx_call_control_id !== null) {
            return;
        }

        $token = base64_decode($clientStateB64, true);
        $contact = $call->contact_id !== null ? Contacts::query()->find($call->contact_id) : null;
        $number = BusinessMessagingNumber::query()->find($call->business_messaging_number_id);
        // A contact's number, or (unlinked conversation call) the exact number recorded at placement. The to_hash check below
        // proves it is still the one the call was authorized for.
        $destination = $contact !== null
            ? E164Normalizer::normalize('+' . ltrim((string) $contact->phone, '+'))
            : ($call->contact_id === null && $call->caller_number !== null ? E164Normalizer::normalize((string) $call->caller_number) : null);

        // The token proves the event is for THIS call; the stored hash proves the destination was not changed since.
        if ($token === false || hash('sha256', $token) !== $call->client_state_hash
            || $destination === null || $number === null
            || BusinessVoiceCall::hashDestination($destination) !== $call->to_hash) {
            $this->failCall($call, 'contact_not_dialable');

            return;
        }

        $webhookUrl = (string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice'));
        $cfg = (array) config('messaging.voice.customer_calls');

        $result = $this->dialer->dial(
            $call, trim((string) config('services.telnyx.api_key')), trim((string) config('messaging.voice.connection_id')),
            $number->phone_number, $destination, $webhookUrl, $token,
            ringTimeoutSecs: (int) $cfg['ring_timeout_secs'],
            timeLimitSecs: max(1, min((int) $cfg['max_duration_secs'], 3600)),
        );

        // The call may have ended (browser dropped) while the dial was in flight: drop whichever legs now exist.
        if (in_array($result->status, [BusinessVoiceCall::STATUS_FAILED, BusinessVoiceCall::STATUS_ENDED], true)) {
            $this->command((string) $call->agent_call_control_id, 'hangup');
            if ($result->telnyx_call_control_id !== null) {
                $this->command((string) $result->telnyx_call_control_id, 'hangup');
            }
        }
    }

    private function bridge(int $callId, string $agentLeg, string $contactLeg): void
    {
        if ($this->command($agentLeg, 'bridge', ['call_control_id' => $contactLeg])) {
            return;
        }

        // Two legs that cannot be joined are worse than no call: drop both and say why.
        $this->command($agentLeg, 'hangup');
        $this->command($contactLeg, 'hangup');
        $call = BusinessVoiceCall::query()->find($callId);
        if ($call !== null) {
            $this->failCall($call, 'bridge_failed', true);
        }
    }

    private function failCall(BusinessVoiceCall $call, string $code, bool $evenIfAnswered = false): void
    {
        $statuses = [BusinessVoiceCall::STATUS_REQUESTED, BusinessVoiceCall::STATUS_INITIATED];
        if ($evenIfAnswered) {
            $statuses[] = BusinessVoiceCall::STATUS_ANSWERED;
        }

        BusinessVoiceCall::query()->whereKey($call->id)->whereIn('status', $statuses)
            ->update(['status' => BusinessVoiceCall::STATUS_FAILED, 'failure_code' => $code, 'ended_at' => now()]);

        if ($call->agent_call_control_id !== null) {
            $this->command((string) $call->agent_call_control_id, 'hangup');
        }
    }

    private function close(BusinessVoiceCall $call, Carbon $at, ?string $cause): void
    {
        $call->status = BusinessVoiceCall::STATUS_ENDED;
        $call->ended_at = $at;
        $call->hangup_cause = $cause;
        // Billable-style duration: answered -> ended only; a call the contact never answered has none.
        $call->duration_seconds = $call->answered_at !== null ? (int) max(0, $call->answered_at->diffInSeconds($at, false)) : null;
    }

    /** @param array<string, mixed> $body */
    private function command(string $callControlId, string $action, array $body = []): bool
    {
        if ($callControlId === '') {
            return false;
        }

        try {
            $response = Http::withToken(trim((string) config('services.telnyx.api_key')))->timeout(20)->acceptJson()->asJson()
                ->post(self::API_BASE . '/calls/' . rawurlencode($callControlId) . '/actions/' . $action, $body === [] ? new \stdClass() : $body);
        } catch (\Throwable) {
            Log::warning('Telnyx call action did not complete', ['action' => $action]);

            return false;
        }

        // A hangup for a leg that is already gone is not an error worth reporting.
        if (! $response->successful() && $action !== 'hangup') {
            Log::warning('Telnyx call action rejected', ['action' => $action, 'http_status' => $response->status(), 'provider_code' => $response->json('errors.0.code')]);
        }

        return $response->successful();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: BusinessVoiceCall, 1: string}|null the browser-channel row and which leg this event is about
     */
    private function locate(string $callControlId, array $payload): ?array
    {
        $call = BusinessVoiceCall::query()->where('channel', 'browser')
            ->where(fn ($q) => $q->where('agent_call_control_id', $callControlId)->orWhere('telnyx_call_control_id', $callControlId))
            ->lockForUpdate()->first();

        if ($call !== null) {
            return [$call, $call->agent_call_control_id === $callControlId ? 'agent' : 'contact'];
        }

        // An event can outrun our own save of a leg's id: the per-call token echoed as client_state identifies the row.
        $state = $payload['client_state'] ?? null;
        $token = is_string($state) ? base64_decode($state, true) : false;
        if ($token === false || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $call = BusinessVoiceCall::query()->where('channel', 'browser')->where('client_state_hash', hash('sha256', $token))->lockForUpdate()->first();
        if ($call === null) {
            return null;
        }

        if ($call->agent_call_control_id === null) {
            $call->agent_call_control_id = $callControlId;

            return [$call, 'agent'];
        }
        if ($call->telnyx_call_control_id === null) {
            $call->telnyx_call_control_id = $callControlId;

            return [$call, 'contact'];
        }

        return [$call, 'unknown']; // a third leg for this call: claimed, never applied
    }

    /** @param array<string, mixed> $payload */
    private function cause(array $payload): ?string
    {
        $cause = $payload['hangup_cause'] ?? null;

        return is_string($cause) ? substr($cause, 0, 64) : null;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
