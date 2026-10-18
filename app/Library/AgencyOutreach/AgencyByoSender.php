<?php

namespace App\Library\AgencyOutreach;

use App\Library\Security\SealedSecret;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\SendingServer;
use App\Models\Workspace;

/**
 * THE one answer to "does this Business send through the agency's own Telnyx
 * account, and is that connection usable?".
 *
 * A BYO sender exists only for the Agency Workspace's own single Business
 * (AgencyOutreachBusinessResolver) — never for a client Business — and only
 * when the agency has explicitly chosen one of its own connected Telnyx
 * channels as its default sender. Once chosen, it is the transport: if it is
 * not usable the send is refused, and the managed (platform-paid) path is
 * never used as a silent substitute.
 *
 * Read-only; no provider call.
 */
final class AgencyByoSender
{
    /** The channel the agency chose as its default sender, whether or not it is usable. */
    public static function selectedChannel(?Business $business): ?AgencyProspectingChannel
    {
        if ($business === null || $business->workspace_id === null) {
            return null;
        }

        if (! self::isOwnBusiness($business)) {
            return null;
        }

        return AgencyProspectingChannel::query()
            ->where('workspace_id', (int) $business->workspace_id)
            ->where('provider', SendingServer::TYPE_TELNYX)
            ->where('is_default_sender', true)
            ->with('sendingServer')
            ->orderBy('id')
            ->first();
    }

    /** The Business is its Agency Workspace's own single Business — never a client's. */
    public static function isOwnBusiness(Business $business): bool
    {
        if ($business->workspace_id === null) {
            return false;
        }

        $workspace = Workspace::query()->find((int) $business->workspace_id);
        $own = $workspace !== null ? AgencyOutreachBusinessResolver::forWorkspace($workspace) : null;

        return $own !== null && (int) $own->id === (int) $business->id;
    }

    /**
     * The agency channel an EXISTING conversation was sent from, if it was one of the
     * agency's own Telnyx numbers — so a retry of a failed bubble keeps the transport it
     * started on, whatever the default sender is now. Null means "not a BYO thread".
     */
    public static function channelForConversation(Business $business, int $conversationBoxId): ?AgencyProspectingChannel
    {
        if (! self::isOwnBusiness($business)) {
            return null;
        }

        $from = \App\Models\ChatBox::query()
            ->where('id', $conversationBoxId)
            ->where('business_id', (int) $business->id)
            ->value('from');

        if ($from === null) {
            return null;
        }

        return AgencyProspectingChannel::query()
            ->where('workspace_id', (int) $business->workspace_id)
            ->where('provider', SendingServer::TYPE_TELNYX)
            ->where('sender_number', preg_replace('/\D/', '', (string) $from))
            ->with('sendingServer')
            ->orderBy('id')
            ->first();
    }

    /**
     * Whether the channel can SEND now. Reason codes are stable; messages are for customers.
     *
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public static function sendReadiness(AgencyProspectingChannel $channel): array
    {
        $server = $channel->sendingServer;

        return match (true) {
            $channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED => self::no('disconnected', 'This Telnyx account was disconnected. Reconnect it or choose another sender.'),
            $channel->status !== AgencyProspectingChannel::STATUS_ACTIVE => self::no('channel_disabled', 'This sender is turned off. Turn it on or choose another sender.'),
            $channel->provider !== SendingServer::TYPE_TELNYX => self::no('not_telnyx', 'Only Telnyx accounts can be used as an agency sender.'),
            $server === null || ! $server->status => self::no('missing_credentials', 'The Telnyx connection is unavailable. Reconnect your account.'),
            self::credentials($channel) === null => self::no('missing_credentials', 'The saved Telnyx credentials can no longer be read. Reconnect your account.'),
            $channel->credentials_verified_at === null => self::no('unverified', 'This Telnyx account has not been verified yet. Reconnect it to verify.'),
            $channel->last_error_code === 'invalid_api_key' => self::no('invalid_api_key', 'Telnyx rejected the saved access key. Reconnect with a valid key.'),
            default => ['ok' => true, 'code' => null, 'message' => null],
        };
    }

    /** @return array{api_key: string, profile_id: string}|null */
    public static function credentials(AgencyProspectingChannel $channel): ?array
    {
        $server = $channel->sendingServer;

        if ($server === null) {
            return null;
        }

        $key = SealedSecret::open($server->getRawOriginal('api_key'));
        $profile = trim((string) $server->c1);

        if ($key === null || $key === '' || $profile === '') {
            return null;
        }

        return ['api_key' => $key, 'profile_id' => $profile];
    }

    /** Whether inbound events for this channel can be authenticated at all. */
    public static function hasWebhookKey(AgencyProspectingChannel $channel): bool
    {
        return trim((string) $channel->webhook_public_key) !== '';
    }

    /** @return array{ok: bool, code: string, message: string} */
    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
