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

    /**
     * The transport for COMMANDS on legs a call already has (hangup, bridge). An agency call gets one only while the channel
     * is still the same Telnyx ACCOUNT it was placed on (same sending server and API key): after a key/account change the
     * legs live in an account the new key cannot touch, so no command is sent with someone else's credentials. Null then, or
     * when the channel is disconnected. A changed connection or number does not matter here - those legs already exist.
     */
    public static function forCall(BusinessVoiceCall $call): ?self
    {
        if ($call->agency_prospecting_channel_id === null) {
            return self::platform();
        }

        return self::pinned($call, 'transport_account_hash', 'account');
    }

    /**
     * The transport for DIALLING A NEW LEG (the contact) of an in-flight call. An agency call gets one only if the channel
     * still has exactly the account, Call Control connection and caller number that were verified when the call was placed;
     * otherwise null and the caller fails the call closed rather than dial through unverified details.
     */
    public static function forCallDial(BusinessVoiceCall $call): ?self
    {
        if ($call->agency_prospecting_channel_id === null) {
            return self::platform();
        }

        return self::pinned($call, 'transport_config_hash', 'config');
    }

    private static function pinned(BusinessVoiceCall $call, string $column, string $key): ?self
    {
        $channel = \App\Models\AgencyProspectingChannel::query()->with('sendingServer')->find((int) $call->agency_prospecting_channel_id);
        $expected = (string) $call->{$column};
        // A call with no stored pin (never produced by placement) cannot be proven unchanged: fail closed.
        if ($channel === null || $expected === '' || ! hash_equals($expected, AgencyByoVoice::pin($channel)[$key])) {
            return null;
        }

        return AgencyByoVoice::transport($channel);
    }
}
