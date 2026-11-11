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
        BusinessVoiceCall::STATUS_INITIATED => 1,
        BusinessVoiceCall::STATUS_ANSWERED => 2,
        BusinessVoiceCall::STATUS_ENDED => 3,
        BusinessVoiceCall::STATUS_FAILED => 3,
    ];

    private const EVENT_STATUS = [
        'call.initiated' => BusinessVoiceCall::STATUS_INITIATED,
        'call.answered' => BusinessVoiceCall::STATUS_ANSWERED,
        'call.hangup' => BusinessVoiceCall::STATUS_ENDED,
    ];

    /** @param array<string, mixed> $payload */
    public function apply(string $eventId, string $eventType, string $callControlId, array $payload): string
    {
        $target = self::EVENT_STATUS[$eventType] ?? null;
        if ($target === null || $callControlId === '') {
            return self::IGNORED;
        }

        return DB::transaction(function () use ($eventId, $eventType, $callControlId, $payload, $target): string {
            $call = BusinessVoiceCall::query()->where('telnyx_call_control_id', $callControlId)->lockForUpdate()->first();
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

            $at = $this->time($payload, $target === BusinessVoiceCall::STATUS_ENDED ? 'end_time' : 'start_time') ?? now();
            $call->status = $target;

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

    /** @param array<string, mixed> $payload */
    private function time(array $payload, string $key): ?Carbon
    {
        $value = $payload[$key] ?? null;
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
