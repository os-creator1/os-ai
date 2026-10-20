<?php

namespace App\Library\Voice;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Telnyx v2 REST calls browser calling needs, through Laravel's Http facade (never raw curl), so
 * tests use Http::fake(). Endpoints used: /telephony_credentials (+ /{id}/token), /phone_numbers,
 * /credential_connections/{id}, and the Call Control actions transfer / reject.
 *
 * No key, token or response body is ever included in an exception message or log line.
 */
final class TelnyxVoiceClient implements VoiceProviderClient
{
    private const BASE = 'https://api.telnyx.com/v2';

    private const TIMEOUT = 15;

    public function createTelephonyCredential(string $apiKey, string $connectionId, string $name, string $tag): array
    {
        $response = $this->send($apiKey, 'post', '/telephony_credentials', [
            'connection_id' => $connectionId,
            'name' => $name,
            'tag' => $tag,
        ]);

        $this->assertOk($response, 'credential_create_failed', 'Telnyx could not create a calling identity for your account. Check the voice connection ID.');

        $id = $response->json('data.id');
        $sip = $response->json('data.sip_username');

        if (! is_string($id) || $id === '' || ! is_string($sip) || $sip === '') {
            throw new VoiceProviderException('credential_create_failed', 'Telnyx returned an unexpected answer when creating a calling identity.');
        }

        return ['id' => $id, 'sip_username' => $sip];
    }

    public function mintToken(string $apiKey, string $credentialId): string
    {
        $response = $this->send($apiKey, 'post', '/telephony_credentials/' . rawurlencode($credentialId) . '/token');

        $this->assertOk($response, 'token_failed', 'Telnyx did not issue a calling token. Try again, or reconnect the voice connection.');

        // Telnyx answers this endpoint with the JWT as the plain-text body.
        $token = trim($response->body());

        if ($token === '' || substr_count($token, '.') < 2) {
            throw new VoiceProviderException('token_failed', 'Telnyx returned an unusable calling token.');
        }

        return $token;
    }

    public function deleteTelephonyCredential(string $apiKey, string $credentialId): void
    {
        $response = $this->send($apiKey, 'delete', '/telephony_credentials/' . rawurlencode($credentialId));

        if ($response->status() !== 404) {
            $this->assertOk($response, 'credential_delete_failed', 'Telnyx could not remove the calling identity.');
        }
    }

    public function inspectNumber(string $apiKey, string $e164): array
    {
        $response = $this->send($apiKey, 'get', '/phone_numbers', ['filter' => ['phone_number' => $e164]]);

        $this->assertOk($response, 'number_lookup_failed', 'Telnyx could not look up this number.');

        $row = $response->json('data.0');

        if (! is_array($row)) {
            return ['owned' => false, 'status' => null, 'connection_id' => null];
        }

        return [
            'owned' => true,
            'status' => is_string($row['status'] ?? null) ? strtolower($row['status']) : null,
            'connection_id' => is_string($row['connection_id'] ?? null) && $row['connection_id'] !== '' ? $row['connection_id'] : null,
        ];
    }

    public function inspectCredentialConnection(string $apiKey, string $connectionId): array
    {
        $response = $this->send($apiKey, 'get', '/credential_connections/' . rawurlencode($connectionId));

        if ($response->status() === 404) {
            return ['exists' => false, 'active' => false, 'outbound_known' => false, 'outbound_profile_id' => null];
        }

        $this->assertOk($response, 'connection_lookup_failed', 'Telnyx could not look up this voice connection.');

        return ['exists' => true, 'active' => $response->json('data.active') !== false] + $this->outboundFacts($response);
    }

    public function inspectCallControlApp(string $apiKey, string $appId): array
    {
        $response = $this->send($apiKey, 'get', '/call_control_applications/' . rawurlencode($appId));

        if ($response->status() === 404) {
            return ['exists' => false, 'active' => false, 'webhook_url' => null, 'outbound_known' => false, 'outbound_profile_id' => null];
        }

        $this->assertOk($response, 'app_lookup_failed', 'Telnyx could not look up this Call Control application.');
        $url = $response->json('data.webhook_event_url');

        return [
            'exists' => true,
            'active' => $response->json('data.active') !== false,
            'webhook_url' => is_string($url) && $url !== '' ? $url : null,
        ] + $this->outboundFacts($response);
    }

    public function dial(string $apiKey, string $appId, string $to, string $from, string $clientState, int $timeoutSeconds): array
    {
        $response = $this->send($apiKey, 'post', '/calls', [
            'connection_id' => $appId,
            'to' => $to,
            'from' => $from,
            'client_state' => $clientState,
            'timeout_secs' => $timeoutSeconds,
        ]);

        $this->assertOk($response, 'dial_failed', 'Telnyx could not start the call. Check the Call Control application and its outbound voice profile.');
        $id = $response->json('data.call_control_id');

        if (! is_string($id) || $id === '') {
            throw new VoiceProviderException('dial_failed', 'Telnyx returned an unexpected answer when starting the call.');
        }

        $session = $response->json('data.call_session_id');
        $leg = $response->json('data.call_leg_id');

        return [
            'call_control_id' => $id,
            'call_session_id' => is_string($session) && $session !== '' ? $session : null,
            'call_leg_id' => is_string($leg) && $leg !== '' ? $leg : null,
        ];
    }

    public function hangupCall(string $apiKey, string $callControlId): void
    {
        $response = $this->send($apiKey, 'post', '/calls/' . rawurlencode($callControlId) . '/actions/hangup');

        // Already gone (404/422) is the outcome we wanted.
        if (! in_array($response->status(), [404, 422], true)) {
            $this->assertOk($response, 'hangup_failed', 'Telnyx could not end the call.');
        }
    }

    /** @return array{outbound_known: bool, outbound_profile_id: ?string} */
    private function outboundFacts(Response $response): array
    {
        $outbound = $response->json('data.outbound');

        if (! is_array($outbound) || ! array_key_exists('outbound_voice_profile_id', $outbound)) {
            return ['outbound_known' => false, 'outbound_profile_id' => null];
        }

        $id = $outbound['outbound_voice_profile_id'];

        return ['outbound_known' => true, 'outbound_profile_id' => is_string($id) && $id !== '' ? $id : null];
    }

    public function transferToSip(string $apiKey, string $callControlId, string $sipUri, string $from): void
    {
        $response = $this->send($apiKey, 'post', '/calls/' . rawurlencode($callControlId) . '/actions/transfer', [
            'to' => $sipUri,
            'from' => $from,
            'timeout_secs' => 30,
        ]);

        $this->assertOk($response, 'transfer_failed', 'Telnyx could not route the incoming call to the browser.');
    }

    public function rejectCall(string $apiKey, string $callControlId, string $cause): void
    {
        $response = $this->send($apiKey, 'post', '/calls/' . rawurlencode($callControlId) . '/actions/reject', ['cause' => $cause]);

        $this->assertOk($response, 'reject_failed', 'Telnyx could not reject the incoming call.');
    }

    private function send(string $apiKey, string $method, string $path, array $data = []): Response
    {
        try {
            $request = Http::withToken($apiKey)->timeout(self::TIMEOUT)->acceptJson();

            return match ($method) {
                'get' => $request->get(self::BASE . $path, $data),
                'delete' => $request->delete(self::BASE . $path),
                default => $request->asJson()->post(self::BASE . $path, $data),
            };
        } catch (Throwable) {
            throw VoiceProviderException::unavailable();
        }
    }

    private function assertOk(Response $response, string $reason, string $message): void
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw VoiceProviderException::invalidKey();
        }

        if (! $response->successful()) {
            throw $response->serverError()
                ? VoiceProviderException::unavailable()
                : new VoiceProviderException($reason, $message);
        }
    }
}
