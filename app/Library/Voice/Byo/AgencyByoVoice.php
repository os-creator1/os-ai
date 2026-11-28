<?php

namespace App\Library\Voice\Byo;

use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\Voice\CustomerCallRefusedException;
use App\Library\Voice\VoiceTransport;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\SendingServer;

/**
 * THE one answer to "does this Business place calls through the agency's own Telnyx account, and is that usable?".
 *
 * Two separate modes, never mixed: a Business whose Agency has EXPLICITLY turned voice on for one of its own connected
 * Telnyx channels calls through that channel (and only it); every other Business uses the platform-managed path,
 * untouched. Once a BYO channel is selected it is the transport: if it is not usable the call is refused with an
 * actionable reason, and MotionGrove-paid voice is never a silent substitute.
 *
 * Read-only; no provider call.
 */
final class AgencyByoVoice
{
    /** Setup steps in the order the agency completes them, with the customer-facing label of each. */
    public const STEPS = [
        'voice_connection' => 'Voice (Call Control) connection',
        'webrtc_connection' => 'WebRTC (browser) credential connection',
        'number' => 'Voice number',
        'webhook' => 'Webhook signing key',
    ];

    /** The channel this Business has chosen for calling (whether or not it is usable). Own Agency Business only. */
    public static function selectedChannel(?Business $business): ?AgencyProspectingChannel
    {
        if ($business === null || $business->workspace_id === null || ! AgencyByoSender::isOwnBusiness($business)) {
            return null;
        }

        return AgencyProspectingChannel::query()
            ->where('workspace_id', (int) $business->workspace_id)
            ->where('provider', SendingServer::TYPE_TELNYX)
            ->where('voice_enabled', true)
            ->with('sendingServer')
            ->orderBy('id')
            ->first();
    }

    /**
     * Which setup steps are still outstanding (empty = nothing missing).
     *
     * @return list<string> keys of self::STEPS
     */
    public static function missingSteps(AgencyProspectingChannel $channel): array
    {
        $missing = [];
        if (trim((string) $channel->voice_connection_id) === '' || $channel->voice_connection_verified_at === null) {
            $missing[] = 'voice_connection';
        }
        if (trim((string) $channel->voice_webrtc_connection_id) === '' || $channel->voice_webrtc_verified_at === null) {
            $missing[] = 'webrtc_connection';
        }
        if (trim((string) $channel->voice_number) === '' || $channel->voice_number_verified_at === null) {
            $missing[] = 'number';
        }
        if (! AgencyByoSender::hasWebhookKey($channel)) {
            $missing[] = 'webhook';
        }

        return $missing;
    }

    /** @return array{ok: bool, code: ?string, message: ?string} */
    public static function readiness(AgencyProspectingChannel $channel): array
    {
        $send = AgencyByoSender::sendReadiness($channel, false);
        if (! $send['ok']) {
            return ['ok' => false, 'code' => 'account_' . $send['code'], 'message' => 'Calling through your own Telnyx account is blocked: ' . $send['message']];
        }

        if (! $channel->voice_enabled) {
            return self::no('voice_disabled', 'Voice is turned off for your Telnyx account. Turn it on in Phone & Messaging channels.');
        }

        $missing = self::missingSteps($channel);
        if ($missing !== []) {
            return self::no('setup_incomplete', 'Voice setup for your Telnyx account is incomplete. Missing or unverified: '
                . implode(', ', array_map(fn (string $k): string => self::STEPS[$k], $missing)) . '. Finish it in Phone & Messaging channels.');
        }

        if (! str_starts_with(self::webhookUrl($channel), 'https://')) {
            return self::no('webhook_not_https', 'The voice webhook URL must be https.');
        }

        if ($channel->voice_last_error_code !== null) {
            return self::no('voice_error', 'Telnyx reported a problem with your voice setup. Re-run verification in Phone & Messaging channels.');
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }

    /**
     * The BYO transport for this Business, or null when it is platform-managed. Throws (no fallback) when a BYO channel
     * is selected but not usable.
     */
    public static function resolveFor(Business $business): ?VoiceTransport
    {
        $channel = self::selectedChannel($business);
        if ($channel === null) {
            return null;
        }

        $ready = self::readiness($channel);
        if (! $ready['ok']) {
            throw new CustomerCallRefusedException('byo_voice_unavailable', (string) $ready['message'], 409);
        }

        return self::transport($channel) ?? throw new CustomerCallRefusedException('byo_voice_unavailable', 'The saved Telnyx credentials can no longer be read. Reconnect your account.', 409);
    }

    /** Whether a BYO channel is selected for this Business and why it cannot call yet (null = usable, or not BYO at all). */
    public static function unavailableReason(Business $business): ?string
    {
        $channel = self::selectedChannel($business);

        return $channel === null ? null : self::readiness($channel)['message'];
    }

    /** Credentials + connections of a channel. No readiness check: used for commands on a call already in flight too. */
    public static function transport(AgencyProspectingChannel $channel): ?VoiceTransport
    {
        $credentials = AgencyByoSender::credentials($channel);
        if ($credentials === null || $channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED) {
            return null;
        }

        return new VoiceTransport(
            $credentials['api_key'],
            trim((string) $channel->voice_connection_id),
            trim((string) $channel->voice_webrtc_connection_id),
            self::webhookUrl($channel),
            (int) $channel->id,
            '+' . ltrim((string) $channel->voice_number, '+'),
        );
    }

    public static function transportForChannelId(int $channelId): ?VoiceTransport
    {
        $channel = AgencyProspectingChannel::query()->with('sendingServer')->find($channelId);

        return $channel === null ? null : self::transport($channel);
    }

    /** This channel's own signed-voice-webhook URL (opaque uid + HMAC token, like the messaging webhook). */
    public static function webhookUrl(AgencyProspectingChannel $channel): string
    {
        return route('voice.webhooks.agency_telnyx', [$channel->uid, \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($channel->uid)]);
    }

    /** The opaque, business- and channel-bound token the browser sends instead of a number. */
    public static function selectorToken(Business $business, AgencyProspectingChannel $channel): string
    {
        return substr(hash_hmac('sha256', 'callfrom-byo:' . $business->id . ':' . $channel->id, (string) config('app.key')), 0, 32);
    }

    /**
     * Non-secret keyed digests of the transport a call is placed on, stored with the call so it can never silently move to
     * settings changed afterwards: `account` = which Telnyx account (channel + sending server + API key), enough to decide
     * whether commands on already-created legs may still be sent; `config` = account + Call Control connection + caller
     * number, which must still match before a NEW leg (the contact) is dialled. The key itself is only an HMAC input.
     *
     * @return array{account: string, config: string}
     */
    public static function pin(AgencyProspectingChannel $channel): array
    {
        $channel->loadMissing('sendingServer');
        $server = $channel->sendingServer;
        $account = [(int) $channel->id, (int) $channel->sending_server_id, $server !== null ? (string) $server->getRawOriginal('api_key') : ''];
        $key = (string) config('app.key');

        return [
            'account' => hash_hmac('sha256', json_encode(['acct', $account]), $key),
            'config' => hash_hmac('sha256', json_encode(['cfg', $account, trim((string) $channel->voice_connection_id), preg_replace('/\D/', '', (string) $channel->voice_number)]), $key),
        ];
    }

    /**
     * A keyed digest of exactly what a verification checks: the account (API key and its sending server), the two
     * connection ids and the number. Compared before and after the Telnyx calls so a result can only approve the
     * configuration it was actually obtained for. Never stored or logged.
     */
    public static function fingerprint(AgencyProspectingChannel $channel): string
    {
        $channel->loadMissing('sendingServer');
        $server = $channel->sendingServer;

        return hash_hmac('sha256', json_encode([
            (int) $channel->id,
            (int) $channel->sending_server_id,
            $server !== null ? (string) $server->getRawOriginal('api_key') : '',
            trim((string) $channel->voice_connection_id),
            trim((string) $channel->voice_webrtc_connection_id),
            preg_replace('/\D/', '', (string) $channel->voice_number),
        ]), (string) config('app.key'));
    }

    /** @return array{ok: bool, code: string, message: string} */
    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
