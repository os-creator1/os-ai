<?php

namespace App\Library\Voice;

use App\Library\Voice\Byo\AgencyByoVoice;
use App\Models\BusinessVoiceCall;

/**
 * WHICH Telnyx account, connections and webhook one call runs on: the platform's own (managed numbers) or one agency's
 * own connected account. Every Telnyx command for a call (dial, credential, bridge, hangup) takes its credentials from
 * here, so a BYO call can never be sent with the platform key and a managed call never with an agency's.
 *
 * Holds a live API key: never log it, serialise it, or pass it to a view.
 */
final class VoiceTransport
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $connectionId,
        public readonly string $webrtcConnectionId,
        public readonly string $webhookUrl,
        public readonly ?int $channelId = null,
        public readonly ?string $fromNumber = null,
    ) {
    }

    public function isByo(): bool
    {
        return $this->channelId !== null;
    }

    public static function platform(): self
    {
        return new self(
            trim((string) config('services.telnyx.api_key')),
            trim((string) config('messaging.voice.connection_id')),
            trim((string) config('messaging.voice.browser.credential_connection_id')),
            (string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice')),
        );
    }

    /** The transport a persisted call was placed on; null when an agency call's channel can no longer be used (disconnected). */
    public static function forCall(BusinessVoiceCall $call): ?self
    {
        return $call->agency_prospecting_channel_id === null
            ? self::platform()
            : AgencyByoVoice::transportForChannelId((int) $call->agency_prospecting_channel_id);
    }
}
