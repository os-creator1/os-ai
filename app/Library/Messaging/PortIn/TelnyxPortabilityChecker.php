<?php

namespace App\Library\Messaging\PortIn;

use App\Library\Messaging\E164Normalizer;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Telnyx's official portability check (POST /v2/portability_checks): read-only, creates nothing, costs nothing.
 * Reports `portable`, `fast_portable`, `messaging_capable` and `not_portable_reason` per number. Refuses to run
 * without a configured API key — it never guesses an answer.
 */
final class TelnyxPortabilityChecker
{
    /** @return array<string, array{portable: bool, fast_portable: ?bool, messaging_capable: ?bool, reason: ?string}> keyed by E.164 */
    public function check(array $numbers): array
    {
        $key = (string) (config('services.telnyx.api_key') ?? '');
        if ($key === '') {
            throw new RuntimeException('TELNYX_API_KEY is not configured; portability cannot be checked.');
        }

        $e164 = array_values(array_filter(array_map(function ($n) {
            $digits = preg_replace('/\D+/', '', (string) $n);

            // A bare 10-digit number is read as North American, the only region this check is used for.
            return E164Normalizer::normalize(strlen($digits) === 10 ? '+1'.$digits : (string) $n);
        }, $numbers)));
        if ($e164 === []) {
            return [];
        }

        $response = Http::withToken($key)->acceptJson()->timeout(20)->post('https://api.telnyx.com/v2/portability_checks', ['phone_numbers' => $e164]);
        if (! $response->successful()) {
            throw new RuntimeException('Telnyx portability check failed with HTTP '.$response->status().'.');
        }

        $out = [];
        foreach ((array) $response->json('data', []) as $row) {
            $number = (string) ($row['phone_number'] ?? '');
            $out[$number] = [
                'portable' => (bool) ($row['portable'] ?? false),
                'fast_portable' => isset($row['fast_portable']) ? (bool) $row['fast_portable'] : null,
                'messaging_capable' => isset($row['messaging_capable']) ? (bool) $row['messaging_capable'] : null,
                'reason' => $row['not_portable_reason'] ?? null,
            ];
        }

        return $out;
    }
}
