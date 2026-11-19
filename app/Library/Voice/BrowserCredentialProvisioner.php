<?php

namespace App\Library\Voice;

use App\Models\Business;
use App\Models\BusinessVoiceBrowserCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side provisioning of Telnyx browser telephony credentials.
 *
 * One telephony credential per (Business, user) is created on the configured WebRTC Credential Connection and reused
 * until it nears expiry. The browser only ever receives a short-lived JWT login token minted for that credential
 * (`POST /telephony_credentials/{id}/token`); the platform API key, the credential's SIP password and any permanent
 * secret stay on the server. The credential connection has no outbound voice profile (a documented prerequisite), so
 * even a valid token cannot place a PSTN call by itself: calls exist only when this platform dials the browser leg.
 */
final class BrowserCredentialProvisioner
{
    private const API_BASE = 'https://api.telnyx.com/v2';
    private const TIMEOUT_SECONDS = 20;

    /** @return array{login_token: string, expires_in: int} */
    public function loginSession(Business $business, int $userId, ?VoiceTransport $byo = null): array
    {
        $credential = $this->ensureCredential($business, $userId, $byo);
        $token = $this->mintToken($credential, $byo);

        if ($token === null) {
            // The credential may have been deleted or expired on Telnyx: provision a fresh one once.
            $credential->delete();
            $token = $this->mintToken($this->ensureCredential($business, $userId, $byo), $byo);
        }

        if ($token === null) {
            throw new BrowserCallsUnavailableException('token_unavailable', 'Could not start a calling session.');
        }

        return ['login_token' => $token, 'expires_in' => 3600];
    }

    /** The SIP URI the server dials to ring this user's browser. Null until a session has been provisioned. */
    public function sipUriFor(Business $business, int $userId, ?int $channelId = null, ?string $webrtcConnectionId = null): ?string
    {
        $credential = BusinessVoiceBrowserCredential::query()
            ->where('business_id', $business->id)->where('user_id', $userId)
            ->where('agency_prospecting_channel_id', (int) $channelId)
            // An agency credential only counts while minted on the channel's CURRENT WebRTC connection.
            ->when($channelId !== null, fn ($q) => $q->where('webrtc_connection_id', (string) $webrtcConnectionId))
            ->where('expires_at', '>', now())->first();

        return $credential === null ? null : 'sip:' . $credential->sip_username . '@' . (string) config('messaging.voice.browser.sip_domain');
    }

    /** @param VoiceTransport|null $byo the agency's own account, or null for the platform's */
    public static function assertEnabled(?VoiceTransport $byo = null): void
    {
        $unavailable = $byo !== null
            ? $byo->webrtcConnectionId === '' || $byo->apiKey === ''
            : trim((string) config('messaging.voice.browser.credential_connection_id')) === ''
                || trim((string) config('services.telnyx.api_key')) === ''
                || config('services.telnyx.mode') !== 'live';

        if (! (bool) config('messaging.voice.browser.enabled') || $unavailable) {
            throw new BrowserCallsUnavailableException('browser_calling_unavailable', 'Browser calling is not available right now.');
        }
    }

    private function ensureCredential(Business $business, int $userId, ?VoiceTransport $byo): BusinessVoiceBrowserCredential
    {
        self::assertEnabled($byo);

        $channelId = (int) $byo?->channelId;
        $existing = BusinessVoiceBrowserCredential::query()->where('business_id', $business->id)->where('user_id', $userId)
            ->where('agency_prospecting_channel_id', $channelId)->first();
        $sameConnection = $byo === null || $existing?->webrtc_connection_id === $byo->webrtcConnectionId;
        if ($existing !== null && $sameConnection && $existing->expires_at->gt(now()->addDay())) {
            return $existing;
        }
        $existing?->delete();

        $ttl = max(2, (int) config('messaging.voice.browser.credential_ttl_days'));
        $expiresAt = now()->addDays($ttl);

        $response = $this->client($byo)->post(self::API_BASE . '/telephony_credentials', [
            'connection_id' => $byo?->webrtcConnectionId ?? (string) config('messaging.voice.browser.credential_connection_id'),
            'name' => 'motiongrove-b' . $business->id . '-u' . $userId,
            'tag' => 'motiongrove-browser-calling',
            'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        $id = (string) $response->json('data.id', '');
        $sipUsername = (string) $response->json('data.sip_username', '');
        if (! $response->successful() || $id === '' || $sipUsername === '') {
            Log::warning('Telnyx telephony credential creation rejected', [
                'business_id' => $business->id,
                'http_status' => $response->status(),
                'provider_code' => $response->json('errors.0.code'),
            ]);

            throw new BrowserCallsUnavailableException('credential_unavailable', 'Could not start a calling session.');
        }

        return BusinessVoiceBrowserCredential::create([
            'business_id' => $business->id,
            'user_id' => $userId,
            'agency_prospecting_channel_id' => $channelId,
            'webrtc_connection_id' => $byo?->webrtcConnectionId,
            'telnyx_credential_id' => $id,
            'sip_username' => $sipUsername,
            'expires_at' => $expiresAt,
        ]);
    }

    /** A JWT login token for the credential, or null when Telnyx refuses (credential gone/expired). */
    private function mintToken(BusinessVoiceBrowserCredential $credential, ?VoiceTransport $byo): ?string
    {
        $response = $this->client($byo)->post(self::API_BASE . '/telephony_credentials/' . rawurlencode($credential->telnyx_credential_id) . '/token');
        $token = trim((string) $response->body());

        // Telnyx answers with the bare JWT; accept a JSON-wrapped one too, and nothing that is not JWT-shaped.
        if (str_starts_with($token, '{')) {
            $token = trim((string) $response->json('data', ''));
        }

        return $response->successful() && preg_match('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token) === 1 ? $token : null;
    }

    private function client(?VoiceTransport $byo): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($byo?->apiKey ?? trim((string) config('services.telnyx.api_key')))->timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson();
    }
}
