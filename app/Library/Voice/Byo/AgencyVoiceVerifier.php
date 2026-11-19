<?php

namespace App\Library\Voice\Byo;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only check of an Agency's own Telnyx Voice setup, run only when the agency explicitly asks (the "Verify voice setup"
 * action). GET requests only: it never assigns a number, edits a connection, places a call or buys anything. Each step is
 * reported separately so the UI can say precisely what is missing.
 *
 * Telnyx's own answers are the only thing that can mark a step verified: the Call Control application and the WebRTC
 * credential connection must exist IN THIS ACCOUNT (the agency's key can see no other), the number must be owned by this
 * account and already assigned to that Call Control application. Failure details never include the key or a provider body.
 */
final class AgencyVoiceVerifier
{
    private const BASE = 'https://api.telnyx.com/v2';

    /**
     * @return array{voice_connection: ?string, webrtc_connection: ?string, number: ?string, fatal: ?string}
     *   per step: null = verified, otherwise a customer-readable problem. `fatal` = the account itself could not be queried.
     */
    public function verify(string $apiKey, string $voiceConnectionId, string $webrtcConnectionId, string $numberDigits): array
    {
        $out = ['voice_connection' => null, 'webrtc_connection' => null, 'number' => null, 'fatal' => null];

        try {
            $app = Http::withToken($apiKey)->timeout(15)->acceptJson()->get(self::BASE . '/call_control_applications/' . rawurlencode($voiceConnectionId));
            if (in_array($app->status(), [401, 403], true)) {
                $out['fatal'] = 'Telnyx rejected the saved access key. Reconnect your account with a valid key.';

                return $out;
            }
            if ($app->status() === 404) {
                $out['voice_connection'] = 'That Voice (Call Control) connection ID was not found in this Telnyx account. Create a Call Control application and paste its ID.';
            } elseif (! $app->successful()) {
                $out['fatal'] = 'Telnyx could not be reached right now. Try again in a few minutes.';

                return $out;
            } elseif ($app->json('data.active') === false) {
                $out['voice_connection'] = 'That Call Control application is switched off in Telnyx. Turn it on.';
            }

            $cred = Http::withToken($apiKey)->timeout(15)->acceptJson()->get(self::BASE . '/credential_connections/' . rawurlencode($webrtcConnectionId));
            if ($cred->status() === 404) {
                $out['webrtc_connection'] = 'That WebRTC credential connection ID was not found in this Telnyx account. Create a Credential Connection and paste its ID.';
            } elseif (! $cred->successful()) {
                $out['fatal'] = 'Telnyx could not be reached right now. Try again in a few minutes.';

                return $out;
            } elseif ($cred->json('data.active') === false) {
                $out['webrtc_connection'] = 'That credential connection is switched off in Telnyx. Turn it on.';
            } elseif (trim((string) $cred->json('data.outbound.outbound_voice_profile_id', '')) !== '') {
                // The browser login must not be able to place PSTN calls on its own: calls exist only when the server dials.
                $out['webrtc_connection'] = 'Remove the Outbound Voice Profile from that credential connection in Telnyx, then verify again.';
            }

            $numbers = Http::withToken($apiKey)->timeout(15)->acceptJson()
                ->get(self::BASE . '/phone_numbers', ['filter' => ['phone_number' => '+' . $numberDigits]]);
            if (! $numbers->successful()) {
                $out['fatal'] = 'Telnyx could not be reached right now. Try again in a few minutes.';

                return $out;
            }
            $row = $numbers->json('data.0');
            if (! is_array($row) || preg_replace('/\D/', '', (string) ($row['phone_number'] ?? '')) !== $numberDigits) {
                $out['number'] = 'That number is not a number in this Telnyx account.';
            } elseif (is_string($row['status'] ?? null) && strtolower($row['status']) !== 'active') {
                $out['number'] = 'That number is not active in your Telnyx account yet.';
            } elseif ((string) ($row['connection_id'] ?? '') === '') {
                $out['number'] = 'That number is not assigned to a connection in Telnyx. Assign it to your Call Control application (Telnyx Voice settings) and verify again.';
            } elseif ((string) $row['connection_id'] !== $voiceConnectionId) {
                $out['number'] = 'That number is assigned to a different connection in Telnyx than the Call Control application you entered.';
            }
        } catch (Throwable) {
            $out['fatal'] = 'Telnyx could not be reached right now. Try again in a few minutes.';
        }

        return $out;
    }
}
