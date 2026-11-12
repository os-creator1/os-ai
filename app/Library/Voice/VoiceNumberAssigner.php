<?php

namespace App\Library\Voice;

use App\Enums\Messaging\PhoneNumberType;
use App\Models\BusinessMessagingNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Assigns a freshly purchased LOCAL number to the platform's Telnyx Voice Connection (config
 * messaging.voice.connection_id), so it can place calls without a manual portal step.
 *
 * Never blocks or undoes the purchase: the number is already bought and charged when this runs, so every failure is
 * recorded on the number row (voice_assignment_failure_reason) and logged, never thrown. Voice is recorded as assigned
 * (voice_assigned_at) ONLY when Telnyx itself reports the configured connection on the number — a 2xx alone is not
 * enough. Idempotent: an already-confirmed number with the same connection makes no request, and re-running the PATCH
 * with the same connection is harmless at Telnyx. Messaging resources (profile, 10DLC) are never touched.
 *
 * Single writer of the three voice_* columns on business_messaging_numbers.
 */
final class VoiceNumberAssigner
{
    private const API_BASE = 'https://api.telnyx.com/v2';
    private const TIMEOUT_SECONDS = 20;

    public function assign(BusinessMessagingNumber $number): BusinessMessagingNumber
    {
        $connectionId = trim((string) config('messaging.voice.connection_id'));

        // Not eligible / not configured: voice is simply not part of this setup — record nothing.
        if ($connectionId === ''
            || $number->number_type !== PhoneNumberType::Local
            || ! $number->isActive()
            || trim((string) $number->provider_number_reference) === '') {
            return $number;
        }

        if ($number->voice_assigned_at !== null && $number->voice_connection_id === $connectionId) {
            return $number; // already confirmed against this connection
        }

        $apiKey = trim((string) config('services.telnyx.api_key'));
        if ($apiKey === '') {
            return $this->record($number, null, 'not_configured');
        }

        $url = self::API_BASE . '/phone_numbers/' . rawurlencode((string) $number->provider_number_reference);

        try {
            $client = Http::withToken($apiKey)->timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson();
            $response = $client->patch($url, ['connection_id' => $connectionId]);

            if (! $response->successful()) {
                Log::warning('Telnyx voice connection assignment rejected', [
                    'number_id' => $number->id,
                    'http_status' => $response->status(),
                    'provider_code' => $response->json('errors.0.code'),
                ]);

                return $this->record($number, null, 'http_' . $response->status());
            }

            $confirmed = (string) $response->json('data.id') === (string) $number->provider_number_reference
                && (string) $response->json('data.connection_id') === $connectionId;

            if (! $confirmed) {
                // The PATCH answered 2xx without proving the assignment: verify with a read before believing it.
                $check = $client->get($url);
                $confirmed = $check->successful()
                    && (string) $check->json('data.id') === (string) $number->provider_number_reference
                    && (string) $check->json('data.connection_id') === $connectionId;
            }
        } catch (\Throwable) {
            return $this->record($number, null, 'transport_error');
        }

        return $confirmed ? $this->record($number, $connectionId, null) : $this->record($number, null, 'unconfirmed');
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('messaging.voice.assignment_retry.max_attempts'));
    }

    /** Minutes to wait after the given number of failed attempts: base, doubling, capped. */
    public static function backoffMinutes(int $attempts): int
    {
        $base = max(1, (int) config('messaging.voice.assignment_retry.base_backoff_minutes'));
        $cap = max($base, (int) config('messaging.voice.assignment_retry.max_backoff_minutes'));

        return (int) min($cap, $base * (2 ** max(0, $attempts - 1)));
    }

    private function record(BusinessMessagingNumber $number, ?string $confirmedConnectionId, ?string $failureReason): BusinessMessagingNumber
    {
        $attempts = (int) $number->voice_assignment_attempts + 1;
        $confirmed = $confirmedConnectionId !== null;

        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'voice_connection_id' => $confirmedConnectionId,
            'voice_assigned_at' => $confirmed ? now() : null,
            'voice_assignment_failure_reason' => $failureReason,
            'voice_assignment_attempts' => $attempts,
            // Success ends retrying; a failure schedules the next try, or none once attempts are exhausted.
            'voice_assignment_next_attempt_at' => $confirmed || $attempts >= self::maxAttempts() ? null : now()->addMinutes(self::backoffMinutes($attempts)),
            'updated_at' => now(),
        ]);

        return $number->fresh();
    }
}
