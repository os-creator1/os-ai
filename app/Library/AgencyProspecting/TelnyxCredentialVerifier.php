<?php

namespace App\Library\AgencyProspecting;

use App\Library\Messaging\TelnyxMessagingAdapter;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only, non-billable check of an Agency's own Telnyx credentials, run
 * BEFORE a BYO channel is created, re-keyed or enabled: the API key must be
 * accepted, the Messaging Profile must exist in that same Telnyx account, and
 * the sender number must be a number that account owns. Only GET requests —
 * it never sends a message, buys a number or changes anything at Telnyx.
 *
 * Returns a stable reason code plus a customer-readable message, so the UI can
 * say exactly what is wrong instead of a generic failure.
 */
final class TelnyxCredentialVerifier
{
    private const BASE = 'https://api.telnyx.com/v2';

    /**
     * @return array{ok: bool, reason: ?string, message: ?string, sms_confirmed: bool}
     */
    public function verify(string $apiKey, string $messagingProfileId, string $senderDigits, bool $strict = false): array
    {
        try {
            $profile = Http::withToken($apiKey)->timeout(15)
                ->get(self::BASE . '/messaging_profiles/' . rawurlencode($messagingProfileId));

            if ($early = $this->accessFailure($profile)) {
                return $early;
            }

            if ($profile->status() === 404) {
                return $this->fail('profile_not_found', 'That Messaging profile ID was not found in this Telnyx account.');
            }

            if (! $profile->successful()) {
                return $this->fail('provider_unavailable', 'Telnyx could not be reached right now. Try again in a few minutes.');
            }

            $numbers = Http::withToken($apiKey)->timeout(15)
                ->get(self::BASE . '/phone_numbers', ['filter' => ['phone_number' => '+' . $senderDigits]]);

            if ($early = $this->accessFailure($numbers)) {
                return $early;
            }

            if (! $numbers->successful()) {
                return $this->fail('provider_unavailable', 'Telnyx could not be reached right now. Try again in a few minutes.');
            }

            if (empty($numbers->json('data'))) {
                return $this->fail('number_not_owned', 'That sender number is not a number in this Telnyx account.');
            }

            // Same `status` field TelnyxProvisioningAdapter reads on a retrieved number.
            $status = $numbers->json('data.0.status');

            if (is_string($status) && strtolower($status) !== 'active') {
                return $this->fail('number_not_active', 'That number is not active in your Telnyx account yet.');
            }

            $smsConfirmed = false;
            $numberId = $numbers->json('data.0.id');

            if (is_string($numberId) && $numberId !== '') {
                // The number's own messaging settings (GET /phone_numbers/{id}/messaging). Each field
                // is used only when Telnyx actually returned it: a missing field is "not confirmed",
                // never a guessed pass or fail.
                $settings = Http::withToken($apiKey)->timeout(15)
                    ->get(self::BASE . '/phone_numbers/' . rawurlencode($numberId) . '/messaging');

                // `$strict` (used only when RECOVERING a blocked sender): a refused or unavailable settings check is
                // NOT "field not reported" — the number's profile assignment was never re-verified, so it cannot be
                // read as a pass. Connect/enable flows stay lenient: there it only means "not confirmed".
                if ($strict) {
                    if ($early = $this->accessFailure($settings)) {
                        return $early;
                    }

                    if ($settings->status() === 429 || $settings->status() >= 500) {
                        return $this->fail('provider_unavailable', 'Telnyx could not be reached right now. Try again in a few minutes.');
                    }
                }

                $data = $settings->successful() ? $settings->json('data') : null;

                if (is_array($data)) {
                    if (array_key_exists('messaging_profile_id', $data)) {
                        $assigned = $data['messaging_profile_id'];

                        if (! is_string($assigned) || $assigned === '') {
                            return $this->fail('number_no_profile', 'That number is not assigned to a Messaging profile in Telnyx. Assign it to the profile you entered.');
                        }

                        if ($assigned !== $messagingProfileId) {
                            return $this->fail('number_other_profile', 'That number is assigned to a different Messaging profile in Telnyx than the one you entered.');
                        }
                    }

                    $features = $data['features'] ?? null;

                    if (is_array($features) && array_key_exists('sms', $features)) {
                        $sms = $features['sms'];
                        $enabled = is_array($sms) ? in_array(true, array_map('boolval', $sms), true) : (bool) $sms;

                        if (! $enabled) {
                            return $this->fail('number_not_sms_enabled', 'Telnyx reports that number cannot send text messages. Use an SMS-enabled number.');
                        }

                        $smsConfirmed = true;
                    }
                }
            }
        } catch (Throwable) {
            return $this->fail('provider_unavailable', 'Telnyx could not be reached right now. Try again in a few minutes.');
        }

        return ['ok' => true, 'reason' => null, 'message' => null, 'sms_confirmed' => $smsConfirmed];
    }

    /**
     * A confirmed authentication rejection is `invalid_api_key`; any other 403 is `permission_denied`
     * (Telnyx accepted the key but refuses this resource), which says nothing about the key itself.
     *
     * @return array{ok: bool, reason: string, message: string, sms_confirmed: bool}|null
     */
    private function accessFailure(\Illuminate\Http\Client\Response $response): ?array
    {
        $status = $response->status();

        if (TelnyxMessagingAdapter::isAuthenticationRejection($status, TelnyxMessagingAdapter::errorCodes($response->json('errors')))) {
            return $this->fail('invalid_api_key', 'Telnyx rejected this access key. Check that it is a valid, active v2 API key.');
        }

        if ($status === 403) {
            return $this->fail('permission_denied', 'Telnyx accepted the access key but denied access to this account resource. Check the key\'s permissions in Telnyx.');
        }

        return null;
    }

    /** @return array{ok: bool, reason: string, message: string, sms_confirmed: bool} */
    private function fail(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message, 'sms_confirmed' => false];
    }
}
