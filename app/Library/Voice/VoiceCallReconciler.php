<?php

namespace App\Library\Voice;

use App\Enums\Voice\VoiceCallStatus;
use App\Library\Messaging\E164Normalizer;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\VoiceCall;
use App\Models\VoiceCallEvent;
use App\Models\VoiceNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns VERIFIED Telnyx call events into the one canonical call record. The signature has already been
 * checked by the controller; this class decides what the event means, safely:
 *
 *  - IDEMPOTENT: the provider event id is stored under a unique index inside the same transaction as
 *    the change, so a replay (or a racing duplicate) changes nothing.
 *  - ORDER-SAFE: a transition applies only to a strictly higher status rank, so a late "ringing"
 *    never reopens a finished call; a late "answered" that happened before the recorded end upgrades
 *    a wrongly-unanswered outcome to Completed.
 *  - SCOPED: an event is attributed only to a number of the account that sent it — the platform's
 *    managed numbers for the managed webhook, the one channel's numbers for that channel's webhook.
 *    The Business/Workspace always comes from our own number row, never from the payload.
 *  - An outgoing call is bound to its pre-recorded intent only when the event's destination matches the
 *    destination MotionGrove validated; otherwise it is left unmatched.
 */
final class VoiceCallReconciler
{
    private const HANDLED = ['call.initiated', 'call.answered', 'call.hangup'];

    /**
     * @param  array<string, mixed>  $body  the verified webhook JSON
     * @return array{result: string, call: ?VoiceCall, route: ?array{kind: string, call: VoiceCall, number?: VoiceNumber, control_id?: string}}
     */
    public function handle(array $body, ?AgencyProspectingChannel $channel): array
    {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $eventId = is_string($data['id'] ?? null) ? $data['id'] : '';
        $type = is_string($data['event_type'] ?? null) ? $data['event_type'] : '';
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        if ($eventId === '' || $type === '') {
            return ['result' => 'malformed', 'call' => null, 'route' => null];
        }

        $occurred = $this->time($data['occurred_at'] ?? null) ?? CarbonImmutable::now();

        try {
            return DB::transaction(function () use ($eventId, $type, $payload, $occurred, $channel): array {
                $ledger = VoiceCallEvent::query()->create([
                    'provider_event_id' => $eventId,
                    'event_type' => $type,
                    'occurred_at' => $occurred,
                    'applied' => 'ignored',
                    'created_at' => now(),
                ]);

                if (! in_array($type, self::HANDLED, true)) {
                    return ['result' => 'ignored', 'call' => null, 'route' => null];
                }

                $route = null;
                $call = $this->locate($payload, $channel, $type, $created, $bound);

                if ($call === null) {
                    $ledger->forceFill(['applied' => 'unmatched'])->save();

                    return ['result' => 'unmatched', 'call' => null, 'route' => null];
                }

                $call = VoiceCall::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
                $applied = $this->apply($call, $type, $payload, $occurred) || $bound;

                // Our own browser leg of an outgoing call was just answered: bridge it (once, re-authorized).
                if ($type === 'call.answered' && $this->isOriginatingLeg($call, $payload) && $call->direction === VoiceCall::DIRECTION_OUTBOUND
                    && ! $call->statusEnum()->isFinal() && $call->cancel_requested_at === null) {
                    $route = ['kind' => 'bridge', 'call' => $call];
                }

                // The customer leg of an outgoing call: remember it. If this call may no longer continue — the user
                // canceled, the browser ended, or a bridge failed/was abandoned (teardown is owed; the transfer may
                // still have been accepted) — the late leg must not be left ringing. A leg already recorded and
                // already torn down is not torn down again (duplicate / reordered events stay idempotent).
                if ($call->direction === VoiceCall::DIRECTION_OUTBOUND && in_array($type, ['call.initiated', 'call.answered'], true)
                    && ! $this->isOriginatingLeg($call, $payload) && $this->str($payload, 'call_control_id') !== null) {
                    $isNewLeg = $call->customer_call_control_id === null;
                    $call->customer_call_control_id ??= $this->str($payload, 'call_control_id');
                    $owed = $call->cancel_requested_at !== null || $call->client_reported_ended_at !== null || $call->leg_teardown_status !== null;

                    if ($owed && ($isNewLeg || $call->leg_teardown_status !== VoiceOutboundDialer::TEARDOWN_DONE)) {
                        $call->leg_teardown_status = VoiceOutboundDialer::TEARDOWN_PENDING;
                        $route = ['kind' => 'teardown', 'call' => $call];
                    }

                    $call->save();
                }

                if ($created && $call->direction === VoiceCall::DIRECTION_INBOUND && $type === 'call.initiated'
                    && ! $call->statusEnum()->isFinal() && is_string($payload['call_control_id'] ?? null)) {
                    $number = VoiceNumber::query()->whereKey((int) $call->voice_number_id)->first();
                    $route = $number !== null ? ['kind' => 'inbound', 'call' => $call, 'number' => $number, 'control_id' => $payload['call_control_id']] : null;
                }

                $ledger->forceFill(['voice_call_id' => $call->id, 'applied' => $applied ? 'applied' : 'ignored'])->save();

                return ['result' => $applied ? 'applied' : 'ignored', 'call' => $call, 'route' => $route];
            });
        } catch (UniqueConstraintViolationException) {
            // The same provider event id again — already handled.
            return ['result' => 'duplicate', 'call' => null, 'route' => null];
        }
    }

    /**
     * @param  bool  $created  set true when an inbound record was created by this event
     * @param  bool  $bound  set true when an outgoing intent was first tied to its Telnyx session by this event
     */
    private function locate(array $payload, ?AgencyProspectingChannel $channel, string $type, ?bool &$created, ?bool &$bound): ?VoiceCall
    {
        $created = false;
        $bound = false;
        $session = is_string($payload['call_session_id'] ?? null) ? $payload['call_session_id'] : null;

        if ($session !== null) {
            $call = VoiceCall::query()->where('provider_call_session_id', $session)->first();

            if ($call !== null) {
                return $this->inScope($call, $channel) ? $call : null;
            }
        }

        // An outgoing browser call: correlate by the intent id we put in client_state, then require the
        // destination to be the one we validated.
        $intent = $this->decodeClientState($payload['client_state'] ?? null);

        if ($intent !== null) {
            $call = VoiceCall::query()
                ->where('uid', $intent)
                ->where('direction', VoiceCall::DIRECTION_OUTBOUND)
                ->whereNull('provider_call_session_id')
                ->first();

            // The leg MotionGrove itself dialled to the user's browser: signed event, our own intent id.
            if ($call !== null && $this->inScope($call, $channel) && ($payload['direction'] ?? null) === 'outgoing') {
                if ($session !== null) {
                    $call->forceFill([
                        'provider_call_session_id' => $session,
                        'provider_call_control_id' => $this->str($payload, 'call_control_id'),
                        'provider_leg_id' => $this->str($payload, 'call_leg_id'),
                    ])->save();
                    $bound = true;
                }

                return $call;
            }

            return null;
        }

        return $this->createInbound($payload, $channel, $session, $created);
    }

    private function createInbound(array $payload, ?AgencyProspectingChannel $channel, ?string $session, ?bool &$created): ?VoiceCall
    {
        if (($payload['direction'] ?? null) !== 'incoming' || $session === null) {
            return null;
        }

        $to = E164Normalizer::normalize($this->str($payload, 'to'));
        $from = E164Normalizer::normalize($this->str($payload, 'from'));

        if ($to === null || $from === null) {
            return null; // e.g. a SIP-user leg or an anonymous caller: never invent a record
        }

        $number = VoiceNumber::query()
            ->where('phone_number', $to)
            ->when(
                $channel !== null,
                fn ($q) => $q->where('source', VoiceNumber::SOURCE_BYO)->where('agency_channel_id', (int) $channel->id),
                fn ($q) => $q->where('source', VoiceNumber::SOURCE_MANAGED),
            )
            ->first();

        $business = $number !== null ? Business::query()->find((int) $number->business_id) : null;

        if ($number === null || $business === null) {
            return null;
        }

        $contact = app(VoiceCallService::class)->contactForNumber($business, $from);

        try {
            $call = VoiceCall::query()->create([
                'uid' => (string) Str::uuid(),
                'business_id' => (int) $business->id,
                'workspace_id' => (int) $number->workspace_id,
                'location_id' => $contact?->location_id !== null ? (int) $contact->location_id : null,
                'contact_id' => $contact?->id,
                'voice_number_id' => (int) $number->id,
                'user_id' => $number->inbound_user_id !== null ? (int) $number->inbound_user_id : null,
                'direction' => VoiceCall::DIRECTION_INBOUND,
                'from_number' => $from,
                'to_number' => $to,
                'counterpart_number' => $from,
                'status' => VoiceCallStatus::Initiating->value,
                'status_rank' => VoiceCallStatus::Initiating->rank(),
                'provider_call_session_id' => $session,
                'provider_call_control_id' => $this->str($payload, 'call_control_id'),
                'provider_leg_id' => $this->str($payload, 'call_leg_id'),
                'initiated_at' => $this->time($payload['start_time'] ?? null) ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return VoiceCall::query()->where('provider_call_session_id', $session)->first();
        }

        $created = true;

        return $call;
    }

    private function apply(VoiceCall $call, string $type, array $payload, CarbonImmutable $occurred): bool
    {
        $current = $call->statusEnum();

        if ($type === 'call.initiated') {
            $target = $call->direction === VoiceCall::DIRECTION_INBOUND ? VoiceCallStatus::Ringing : VoiceCallStatus::Initiating;

            return $this->advance($call, $current, $target, $occurred, []);
        }

        if ($type === 'call.answered') {
            // Outgoing: the first answer is OUR dial leg (the person's browser) — that is not the customer
            // picking up. It only moves the call to "ringing the customer" while the bridge happens. The
            // customer answering is a call.answered on any OTHER leg of the session.
            if ($call->direction === VoiceCall::DIRECTION_OUTBOUND && $this->isOriginatingLeg($call, $payload)) {
                return $this->advance($call, $current, VoiceCallStatus::Ringing, $occurred, []);
            }

            // Answered AFTER we already recorded an unanswered end, but before that end happened: the
            // end arrived first. Correct it to Completed rather than leave a wrong outcome.
            if ($current->isFinal() && $current !== VoiceCallStatus::Completed && $call->answered_at === null
                && $call->ended_at !== null && $occurred->lessThanOrEqualTo($call->ended_at)) {
                $call->forceFill([
                    'status' => VoiceCallStatus::Completed->value,
                    'status_rank' => VoiceCallStatus::Completed->rank(),
                    'answered_at' => $occurred,
                    'duration_seconds' => max(0, $call->ended_at->getTimestamp() - $occurred->getTimestamp()),
                ])->save();

                return true;
            }

            return $this->advance($call, $current, VoiceCallStatus::Answered, $occurred, ['answered_at' => $call->answered_at ?? $occurred]);
        }

        // call.hangup
        // The provider itself confirms our leg is gone: any teardown we still owed for it is settled.
        if ($this->isOriginatingLeg($call, $payload) && in_array($call->leg_teardown_status, [VoiceOutboundDialer::TEARDOWN_PENDING, VoiceOutboundDialer::TEARDOWN_FAILED], true)
            && $call->customer_call_control_id === null) {
            $call->forceFill(['leg_teardown_status' => VoiceOutboundDialer::TEARDOWN_DONE, 'leg_teardown_error' => null])->save();
        }

        if ($current->isFinal() && $current !== VoiceCallStatus::Unconfirmed) {
            return false;
        }

        $cause = $this->str($payload, 'hangup_cause');
        $source = $this->str($payload, 'hangup_source');
        $ended = $this->time($payload['end_time'] ?? null) ?? $occurred;
        $answeredAt = $call->answered_at;
        // An outgoing call whose browser leg never got answered/bridged did not fail at the customer's end.
        $outcome = $call->direction === VoiceCall::DIRECTION_OUTBOUND && $call->bridge_requested_at === null && $answeredAt === null
            ? VoiceCallStatus::Failed
            : self::outcome($call->direction, $answeredAt !== null, $cause, $source);

        $call->forceFill([
            'status' => $outcome->value,
            'status_rank' => $outcome->rank(),
            'ended_at' => $ended,
            'duration_seconds' => $answeredAt !== null ? max(0, $ended->getTimestamp() - $answeredAt->getTimestamp()) : 0,
            'hangup_cause' => $cause !== null ? Str::limit($cause, 64, '') : null,
            'hangup_source' => $source !== null ? Str::limit($source, 24, '') : null,
            'last_event_at' => $occurred,
        ])->save();

        return true;
    }

    /** @param array<string, mixed> $extra */
    private function advance(VoiceCall $call, VoiceCallStatus $current, VoiceCallStatus $target, CarbonImmutable $occurred, array $extra): bool
    {
        // A strictly higher rank only. Same-rank repeats and anything older are no-ops.
        if ($target->rank() <= $current->rank()) {
            return false;
        }

        $call->forceFill($extra + [
            'status' => $target->value,
            'status_rank' => $target->rank(),
            'last_event_at' => $occurred,
        ])->save();

        return true;
    }

    /**
     * Pure mapping from what Telnyx reports to the outcome a person sees. A call that was answered is
     * Completed whatever the hangup cause; the rest is decided by cause and direction.
     */
    public static function outcome(string $direction, bool $answered, ?string $cause, ?string $source): VoiceCallStatus
    {
        if ($answered) {
            return VoiceCallStatus::Completed;
        }

        $cause = strtolower((string) $cause);

        if ($cause === 'user_busy') {
            return VoiceCallStatus::Busy;
        }

        if ($direction === VoiceCall::DIRECTION_INBOUND) {
            return $cause === 'call_rejected' ? VoiceCallStatus::Declined : VoiceCallStatus::Missed;
        }

        return match (true) {
            in_array($cause, ['no_answer', 'timeout', 'no_user_response'], true) => VoiceCallStatus::NoAnswer,
            $cause === 'originator_cancel' => VoiceCallStatus::Canceled,
            $cause === 'call_rejected' => VoiceCallStatus::Declined,
            $cause === 'normal_clearing' && $source === 'caller' => VoiceCallStatus::Canceled,
            default => VoiceCallStatus::Failed,
        };
    }

    private function inScope(VoiceCall $call, ?AgencyProspectingChannel $channel): bool
    {
        $number = VoiceNumber::query()->whereKey((int) $call->voice_number_id)->first();

        if ($number === null) {
            return false;
        }

        return $channel === null
            ? $number->source === VoiceNumber::SOURCE_MANAGED
            : $number->source === VoiceNumber::SOURCE_BYO && (int) $number->agency_channel_id === (int) $channel->id;
    }

    /** Is this event about the leg MotionGrove dialled (outgoing) or received (incoming) first? */
    private function isOriginatingLeg(VoiceCall $call, array $payload): bool
    {
        $control = $this->str($payload, 'call_control_id');

        return $control !== null && $call->provider_call_control_id !== null && hash_equals((string) $call->provider_call_control_id, $control);
    }

    private function decodeClientState(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = base64_decode($raw, true);

        return is_string($decoded) && Str::isUuid($decoded) ? $decoded : null;
    }

    private function str(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) && $payload[$key] !== '' ? $payload[$key] : null;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
