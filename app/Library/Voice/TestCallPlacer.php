<?php

namespace App\Library\Voice;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\E164Normalizer;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessVoiceCall;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
        // Without the webhook verification key every event is acknowledged and discarded, losing all call results.
        $webhookKey = trim((string) config('services.telnyx.webhook_public_key'));

        if (! (bool) config('messaging.voice.test_call_enabled')) {
            throw new TestCallRefusedException('disabled', 'Voice test calls are not enabled.');
        }
        if (config('services.telnyx.mode') !== 'live') {
            throw new TestCallRefusedException('not_live', 'Telnyx is not in live mode.');
        }
        if ($apiKey === '' || $connectionId === '' || $webhookKey === '') {
            throw new TestCallRefusedException('not_configured', 'The Telnyx API key, webhook public key or Voice Connection id is not configured.');
        }

        $destination = E164Normalizer::normalize($to);
        $allowlist = array_values(array_filter(array_map(
            fn (string $n): ?string => E164Normalizer::normalize(trim($n)),
            explode(',', (string) config('messaging.voice.test_call_allowlist')),
        )));
        if ($destination === null || ! in_array($destination, $allowlist, true)) {
            throw new TestCallRefusedException('destination_not_allowlisted', 'The destination is not on the test-call allowlist.');
        }

        $webhookUrl = (string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice'));
        if (! str_starts_with($webhookUrl, 'https://')) {
            throw new TestCallRefusedException('webhook_not_https', 'The voice webhook URL must be https.');
        }

        $number = $this->resolveNumber($business);
        $this->assertAssignedToVoiceConnection($apiKey, $number, $connectionId);

        // Unpredictable per-call token, sent to Telnyx (base64) as `client_state`; only its hash is stored.
        $token = bin2hex(random_bytes(32));

        $call = BusinessVoiceCall::create([
            'client_state_hash' => hash('sha256', $token),
            'business_id' => $business->id,
            'business_messaging_number_id' => $number->id,
            'to_hash' => BusinessVoiceCall::hashDestination($destination),
            'status' => BusinessVoiceCall::STATUS_REQUESTED,
        ]);

        return app(TelnyxCallDialer::class)->dial(
            $call, $apiKey, $connectionId, $number->phone_number, $destination, $webhookUrl, $token,
            ringTimeoutSecs: 30, // bound a test call's cost: ring at most 30s,
            timeLimitSecs: 60,   // talk at most 60s.
        );
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

    private function client(string $apiKey): PendingRequest
    {
        return Http::withToken($apiKey)->timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson();
    }
}
