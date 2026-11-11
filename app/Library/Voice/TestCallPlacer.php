<?php

namespace App\Library\Voice;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\E164Normalizer;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessVoiceCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Places ONE admin-initiated Telnyx Call Control test call from a Business's own active local number.
 *
 * Every gate is checked before the first outbound request: explicit enablement, live mode, credentials, Voice
 * Connection id, an exact destination allowlist, and a number that is active, primary, local, owned by this Business and
 * — proven live with a GET — assigned to the configured Voice Connection (messaging verification does not imply voice).
 *
 * Reuses the platform Telnyx credential (config services.telnyx.*). No wallet charge: voice billing is not built yet.
 * Logs carry ids and provider codes only — never a phone number or credential.
 */
final class TestCallPlacer
{
    private const API_BASE = 'https://api.telnyx.com/v2';
    private const TIMEOUT_SECONDS = 20;

    public function __construct(private readonly BusinessMessagingIdentityResolver $identities)
    {
    }

    /** @throws TestCallRefusedException when refused before any call; otherwise returns the persisted call (initiated or failed). */
    public function place(Business $business, string $to): BusinessVoiceCall
    {
        $apiKey = trim((string) config('services.telnyx.api_key'));
        $connectionId = trim((string) config('messaging.voice.connection_id'));

        if (! (bool) config('messaging.voice.test_call_enabled')) {
            throw new TestCallRefusedException('disabled', 'Voice test calls are not enabled.');
        }
        if (config('services.telnyx.mode') !== 'live') {
            throw new TestCallRefusedException('not_live', 'Telnyx is not in live mode.');
        }
        if ($apiKey === '' || $connectionId === '') {
            throw new TestCallRefusedException('not_configured', 'The Telnyx API key or Voice Connection id is not configured.');
        }

        $destination = E164Normalizer::normalize($to);
        $allowlist = array_values(array_filter(array_map(
            fn (string $n): ?string => E164Normalizer::normalize(trim($n)),
            explode(',', (string) config('messaging.voice.test_call_allowlist')),
        )));
        if ($destination === null || ! in_array($destination, $allowlist, true)) {
            throw new TestCallRefusedException('destination_not_allowlisted', 'The destination is not on the test-call allowlist.');
        }

        $number = $this->resolveNumber($business);
        $this->assertAssignedToVoiceConnection($apiKey, $number, $connectionId);

        $call = BusinessVoiceCall::create([
            'business_id' => $business->id,
            'business_messaging_number_id' => $number->id,
            'to_hash' => BusinessVoiceCall::hashDestination($destination),
            'status' => BusinessVoiceCall::STATUS_REQUESTED,
        ]);

        try {
            $response = $this->client($apiKey)->post(self::API_BASE . '/calls', [
                'connection_id' => $connectionId,
                'to' => $destination,
                'from' => $number->phone_number,
                'webhook_url' => (string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice')),
            ]);
        } catch (ConnectionException) {
            // Outcome unknown: Telnyx may still have placed the call. Recorded as failed/unconfirmed, never retried here.
            return $this->fail($call, 'transport_error');
        }

        $callControlId = (string) $response->json('data.call_control_id', '');
        if (! $response->successful() || $callControlId === '') {
            Log::warning('Telnyx outbound test call rejected', [
                'business_id' => $business->id,
                'voice_call_id' => $call->id,
                'http_status' => $response->status(),
                'provider_code' => $response->json('errors.0.code'),
            ]);

            return $this->fail($call, $response->successful() ? 'no_call_control_id' : 'http_' . $response->status());
        }

        // A webhook may beat this write; apply() only moves forward, so keep any later status.
        $call->telnyx_call_control_id = $callControlId;
        if ($call->status === BusinessVoiceCall::STATUS_REQUESTED) {
            $call->status = BusinessVoiceCall::STATUS_INITIATED;
            $call->initiated_at = now();
        }
        $call->save();

        return $call;
    }

    private function resolveNumber(Business $business): BusinessMessagingNumber
    {
        $identity = $this->identities->resolveForBusiness($business);
        if ($identity === null) {
            throw new TestCallRefusedException('no_active_identity', 'The Business has no active messaging identity.');
        }

        try {
            $number = $this->identities->resolvePrimaryNumber($identity);
        } catch (\Throwable) {
            throw new TestCallRefusedException('no_active_number', 'The Business has no single active primary number.');
        }

        if ($number->number_type !== PhoneNumberType::Local || trim((string) $number->provider_number_reference) === '') {
            throw new TestCallRefusedException('number_not_eligible', 'The active number is not a provisioned local Telnyx number.');
        }

        return $number;
    }

    /** Messaging verification does not enable voice: prove it against Telnyx before dialing. */
    private function assertAssignedToVoiceConnection(string $apiKey, BusinessMessagingNumber $number, string $connectionId): void
    {
        try {
            $response = $this->client($apiKey)->get(self::API_BASE . '/phone_numbers/' . rawurlencode((string) $number->provider_number_reference));
        } catch (\Throwable) {
            throw new TestCallRefusedException('capability_check_failed', 'Could not confirm the number\'s voice assignment with Telnyx.');
        }

        if (! $response->successful()
            || (string) $response->json('data.id') !== (string) $number->provider_number_reference
            || $response->json('data.phone_number') !== $number->phone_number
            || strtolower((string) $response->json('data.status')) !== 'active') {
            throw new TestCallRefusedException('capability_check_failed', 'Telnyx did not confirm the number as active.');
        }

        if ((string) $response->json('data.connection_id') !== $connectionId) {
            throw new TestCallRefusedException('number_not_voice_assigned', 'The number is not assigned to the configured Voice Connection.');
        }
    }

    private function fail(BusinessVoiceCall $call, string $code): BusinessVoiceCall
    {
        $call->status = BusinessVoiceCall::STATUS_FAILED;
        $call->failure_code = $code;
        $call->ended_at = now();
        $call->save();

        return $call;
    }

    private function client(string $apiKey): PendingRequest
    {
        return Http::withToken($apiKey)->timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson();
    }
}
