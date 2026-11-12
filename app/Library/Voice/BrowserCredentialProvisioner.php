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
    public function loginSession(Business $business, int $userId): array
    {
        $credential = $this->ensureCredential($business, $userId);
        $token = $this->mintToken($credential);

        if ($token === null) {
            // The credential may have been deleted or expired on Telnyx: provision a fresh one once.
            $credential->delete();
            $token = $this->mintToken($this->ensureCredential($business, $userId));
        }

        if ($token === null) {
            throw new BrowserCallsUnavailableException('token_unavailable', 'Could not start a calling session.');
        }

        return ['login_token' => $token, 'expires_in' => 3600];
    }

    /** The SIP URI the server dials to ring this user's browser. Null until a session has been provisioned. */
    public function sipUriFor(Business $business, int $userId): ?string
    {
        $credential = BusinessVoiceBrowserCredential::query()
            ->where('business_id', $business->id)->where('user_id', $userId)
            ->where('expires_at', '>', now())->first();

        return $credential === null ? null : 'sip:' . $credential->sip_username . '@' . (string) config('messaging.voice.browser.sip_domain');
    }

    public static function assertEnabled(): void
    {
        if (! (bool) config('messaging.voice.browser.enabled')
            || trim((string) config('messaging.voice.browser.credential_connection_id')) === ''
            || trim((string) config('services.telnyx.api_key')) === ''
            || config('services.telnyx.mode') !== 'live') {
            throw new BrowserCallsUnavailableException('browser_calling_unavailable', 'Browser calling is not available right now.');
        }
    }

    private function ensureCredential(Business $business, int $userId): BusinessVoiceBrowserCredential
    {
        self::assertEnabled();

        $existing = BusinessVoiceBrowserCredential::query()->where('business_id', $business->id)->where('user_id', $userId)->first();
        if ($existing !== null && $existing->expires_at->gt(now()->addDay())) {
            return $existing;
        }
        $existing?->delete();

        $ttl = max(2, (int) config('messaging.voice.browser.credential_ttl_days'));
        $expiresAt = now()->addDays($ttl);

        $response = $this->client()->post(self::API_BASE . '/telephony_credentials', [
            'connection_id' => (string) config('messaging.voice.browser.credential_connection_id'),
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
            'telnyx_credential_id' => $id,
            'sip_username' => $sipUsername,
            'expires_at' => $expiresAt,
        ]);
    }

    /** A JWT login token for the credential, or null when Telnyx refuses (credential gone/expired). */
    private function mintToken(BusinessVoiceBrowserCredential $credential): ?string
    {
        $response = $this->client()->post(self::API_BASE . '/telephony_credentials/' . rawurlencode($credential->telnyx_credential_id) . '/token');
        $token = trim((string) $response->body());

        // Telnyx answers with the bare JWT; accept a JSON-wrapped one too, and nothing that is not JWT-shaped.
        if (str_starts_with($token, '{')) {
            $token = trim((string) $response->json('data', ''));
        }

        return $response->successful() && preg_match('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $token) === 1 ? $token : null;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken(trim((string) config('services.telnyx.api_key')))->timeout(self::TIMEOUT_SECONDS)->acceptJson()->asJson();
    }
}
