<?php

namespace App\Library\Voice;

use App\Models\BusinessVoiceCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place that sends `POST /v2/calls` (Telnyx Call Control Dial) for an already-persisted BusinessVoiceCall row
 * and records the API outcome. Shared by the admin test call and customer outbound calls so both get the same
 * unguessable client_state token, cost bounds and race-safe persistence. Every GATE (enablement, ownership,
 * allowlists, limits) belongs to the caller and runs before this; nothing here decides who may call.
 *
 * Logs carry ids and provider codes only — never a phone number or credential.
 */
final class TelnyxCallDialer
{
    private const API_BASE = 'https://api.telnyx.com/v2';
    private const TIMEOUT_SECONDS = 20;

    /** @param string $token the per-call secret whose SHA-256 is already stored on the row as client_state_hash */
    public function dial(
        BusinessVoiceCall $call,
        string $apiKey,
        string $connectionId,
        string $from,
        string $to,
        string $webhookUrl,
        string $token,
        int $ringTimeoutSecs,
        int $timeLimitSecs,
        string $idColumn = 'telnyx_call_control_id',
    ): BusinessVoiceCall {
        try {
            $response = Http::withToken($apiKey)->timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson()->post(self::API_BASE . '/calls', [
                'connection_id' => $connectionId,
                'to' => $to,
                'from' => $from,
                'webhook_url' => $webhookUrl,
                // Echoed in every event so a webhook that outruns this response can still find its row.
                'client_state' => base64_encode($token),
                'timeout_secs' => $ringTimeoutSecs,
                'time_limit_secs' => $timeLimitSecs,
            ]);
        } catch (ConnectionException) {
            // Outcome unknown: Telnyx may still have placed the call. Recorded as failed/unconfirmed, never retried here.
            return $this->fail($call, 'transport_error');
        }

        $callControlId = (string) $response->json('data.call_control_id', '');
        if (! $response->successful() || $callControlId === '') {
            Log::warning('Telnyx outbound call rejected', [
                'business_id' => $call->business_id,
                'voice_call_id' => $call->id,
                'http_status' => $response->status(),
                'provider_code' => $response->json('errors.0.code'),
            ]);

            return $this->fail($call, $response->successful() ? 'no_call_control_id' : 'http_' . $response->status());
        }

        // A webhook may already have adopted the row and moved it on, so never write from the stale in-memory model:
        // set the id only if still empty, and move requested -> initiated only if still requested.
        BusinessVoiceCall::query()->whereKey($call->id)->whereNull($idColumn)->update([$idColumn => $callControlId]);

        // Dialling the browser (agent) leg is not the contact ringing: only the contact/server leg advances the status.
        if ($idColumn === 'telnyx_call_control_id') {
            BusinessVoiceCall::query()->whereKey($call->id)->where('status', BusinessVoiceCall::STATUS_REQUESTED)
                ->update(['status' => BusinessVoiceCall::STATUS_INITIATED, 'initiated_at' => now()]);
        }

        return $call->refresh();
    }

    private function fail(BusinessVoiceCall $call, string $code): BusinessVoiceCall
    {
        // Only a still-requested row: never overwrite progress a webhook already recorded.
        BusinessVoiceCall::query()->whereKey($call->id)->where('status', BusinessVoiceCall::STATUS_REQUESTED)
            ->update(['status' => BusinessVoiceCall::STATUS_FAILED, 'failure_code' => $code, 'ended_at' => now()]);

        return $call->refresh();
    }
}
