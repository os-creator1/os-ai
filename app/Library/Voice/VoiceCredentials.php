<?php

namespace App\Library\Voice;

use App\Library\AgencyOutreach\AgencyByoSender;
use App\Models\Business;
use App\Models\VoiceNumber;

/**
 * Server-side only: whose Telnyx API key and credential connection a number's voice runs on.
 * Managed → the platform key from config; BYO → the Agency channel's own sealed key. The result
 * is used to call Telnyx and is never returned to a browser, logged, or substituted across modes.
 */
final class VoiceCredentials
{
    public function __construct(private readonly VoiceNumberResolver $numbers)
    {
    }

    /** @return array{api_key: string, connection_id: string, app_id: string, public_key: string} */
    public function forNumber(VoiceNumber $number, Business $business): array
    {
        if ($number->source === VoiceNumber::SOURCE_MANAGED) {
            $key = trim((string) config('services.telnyx.api_key'));
            $connection = trim((string) config('services.telnyx.voice_connection_id'));

            if ($key === '' || $connection === '' || ! (bool) config('services.telnyx.voice_enabled')) {
                throw new VoiceUnavailableException('voice_disabled', 'Managed browser calling is not enabled on this platform yet.', 503);
            }

            $app = trim((string) config('services.telnyx.voice_call_control_app_id'));

            if ($app === '') {
                throw new VoiceUnavailableException('voice_not_configured', 'Managed browser calling is missing its Call Control application on the platform.', 503);
            }

            return ['api_key' => $key, 'connection_id' => $connection, 'app_id' => $app, 'public_key' => (string) config('services.telnyx.webhook_public_key')];
        }

        $channel = $this->numbers->byoChannel($number, $business);
        $credentials = $channel !== null ? AgencyByoSender::credentials($channel) : null;

        if ($channel === null || $credentials === null) {
            throw new VoiceUnavailableException('telnyx_disconnected', 'The Telnyx account for this number is disconnected. Reconnect it to place calls.');
        }

        return [
            'api_key' => $credentials['api_key'],
            'connection_id' => trim((string) $number->webrtc_connection_id),
            'app_id' => trim((string) $number->call_control_app_id),
            'public_key' => (string) $channel->webhook_public_key,
        ];
    }
}
