<?php

namespace App\Library\Voice\Inbound;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends Telnyx Call Control actions. Every command carries a deterministic `command_id` so a redelivered webhook, a
 * retried job or a double click cannot make Telnyx act twice (Telnyx drops a repeated command_id for the same call).
 * Logs carry the action and provider code only — never a number or credential.
 */
final class TelnyxCallCommands
{
    private const API_BASE = 'https://api.telnyx.com/v2';

    /** @param array<string, mixed> $body */
    public function send(string $callControlId, string $action, array $body = [], string $idempotencyKey = ''): bool
    {
        if ($callControlId === '') {
            return false;
        }

        $hash = hash('sha256', $callControlId . '|' . $action . '|' . $idempotencyKey);
        $body['command_id'] = sprintf('%s-%s-%s-%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 12, 4), substr($hash, 16, 4), substr($hash, 20, 12));

        try {
            $response = Http::withToken(trim((string) config('services.telnyx.api_key')))->timeout(20)->acceptJson()->asJson()
                ->post(self::API_BASE . '/calls/' . rawurlencode($callControlId) . '/actions/' . $action, $body);
        } catch (\Throwable) {
            Log::warning('Telnyx inbound call action did not complete', ['action' => $action]);

            return false;
        }

        // A hangup/reject for a leg that is already gone is not worth reporting.
        if (! $response->successful() && ! in_array($action, ['hangup', 'reject'], true)) {
            Log::warning('Telnyx inbound call action rejected', ['action' => $action, 'http_status' => $response->status(), 'provider_code' => $response->json('errors.0.code')]);
        }

        return $response->successful();
    }
}
