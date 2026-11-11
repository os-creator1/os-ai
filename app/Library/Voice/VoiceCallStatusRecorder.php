<?php

namespace App\Library\Voice;

use App\Models\BusinessVoiceCall;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies one signature-verified Telnyx `call.*` event to its BusinessVoiceCall, exactly once per event id.
 *
 * Idempotent: the event id is inserted under a UNIQUE index inside the same transaction as the status change, so a
 * redelivery is a no-op. Forward-only: a late `initiated` can never undo `answered`/`ended`. An event for a call this
 * platform did not place (unknown call_control_id) is ignored and creates nothing.
 */
final class VoiceCallStatusRecorder
{
    public const APPLIED = 'applied';
    public const DUPLICATE = 'duplicate';
    public const IGNORED = 'ignored';

    private const RANK = [
        BusinessVoiceCall::STATUS_REQUESTED => 0,
        // `failed` ranks below real call progress: a transport timeout leaves the outcome unknown, so a later
        // carrier event for that call may still correct it. A definitive HTTP rejection never gets events.
        BusinessVoiceCall::STATUS_FAILED => 1,
        BusinessVoiceCall::STATUS_INITIATED => 2,
        BusinessVoiceCall::STATUS_ANSWERED => 3,
        BusinessVoiceCall::STATUS_ENDED => 4,
    ];

    private const EVENT_STATUS = [
        'call.initiated' => BusinessVoiceCall::STATUS_INITIATED,
        'call.answered' => BusinessVoiceCall::STATUS_ANSWERED,
        'call.hangup' => BusinessVoiceCall::STATUS_ENDED,
    ];

    /**
     * @param array<string, mixed> $payload     the event's `payload` object
     * @param string|null          $occurredAt  the event envelope's `occurred_at` (when the event happened)
     */
    public function apply(string $eventId, string $eventType, string $callControlId, array $payload, ?string $occurredAt = null): string
    {
        $target = self::EVENT_STATUS[$eventType] ?? null;
        if ($target === null || $callControlId === '') {
            return self::IGNORED;
        }

        return DB::transaction(function () use ($eventId, $eventType, $callControlId, $payload, $occurredAt, $target): string {
            $call = BusinessVoiceCall::query()->where('telnyx_call_control_id', $callControlId)->lockForUpdate()->first()
                ?? $this->adoptByClientState($callControlId, $payload);
            if ($call === null) {
                return self::IGNORED;
            }

            try {
                $applied = self::RANK[$target] > self::RANK[$call->status];
                $call->getConnection()->table('business_voice_call_events')->insert([
                    'provider_event_id' => $eventId,
                    'business_voice_call_id' => $call->id,
                    'event_type' => $eventType,
                    'applied' => $applied,
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return self::DUPLICATE;
            }

            if (! $applied) {
                return self::IGNORED;
            }

            // Telnyx's payload `start_time` is when the CALL began, not when it was answered, so the answer moment
            // is the event's own `occurred_at`; only hangup carries a distinct `end_time`.
            $at = match ($target) {
                BusinessVoiceCall::STATUS_ENDED => $this->parse($payload['end_time'] ?? null) ?? $this->parse($occurredAt),
                BusinessVoiceCall::STATUS_ANSWERED => $this->parse($occurredAt),
                default => $this->parse($occurredAt) ?? $this->parse($payload['start_time'] ?? null),
            } ?? now();
            $call->status = $target;
            $call->failure_code = null;

            if ($target === BusinessVoiceCall::STATUS_INITIATED) {
                $call->initiated_at ??= $at;
            } elseif ($target === BusinessVoiceCall::STATUS_ANSWERED) {
                $call->answered_at = $at;
            } else {
                $call->ended_at = $at;
                $cause = $payload['hangup_cause'] ?? null;
                $call->hangup_cause = is_string($cause) ? substr($cause, 0, 64) : null;
                // Billable-style duration: answered → ended only. A call never answered has no duration.
                $call->duration_seconds = $call->answered_at !== null
                    ? (int) max(0, $call->answered_at->diffInSeconds($at, false))
                    : null;
            }

            $call->save();

            return self::APPLIED;
        });
    }

    /**
     * An event can outrun our own save of the call id that comes back from POST /calls. The random per-call token we
     * sent as `client_state` (base64) is echoed in every event; only a row whose stored SHA-256 matches AND that has no
     * call id yet adopts it. A missing, malformed or non-matching token never associates anything.
     *
     * @param array<string, mixed> $payload
     */
    private function adoptByClientState(string $callControlId, array $payload): ?BusinessVoiceCall
    {
        $state = $payload['client_state'] ?? null;
        $token = is_string($state) ? base64_decode($state, true) : false;
        if ($token === false || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $call = BusinessVoiceCall::query()
            ->where('client_state_hash', hash('sha256', $token))
            ->whereNull('telnyx_call_control_id')
            ->lockForUpdate()
            ->first();
        if ($call !== null) {
            $call->telnyx_call_control_id = $callControlId;
        }

        return $call;
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
