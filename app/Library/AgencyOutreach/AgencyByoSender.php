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

    /** Prefix of a stored code meaning "the key was accepted but the last verification of the number/profile failed". */
    public const VERIFICATION_FAILED_PREFIX = 'recheck_failed:';

    /** Customer wording for the verifier reasons that can be stored behind {@see self::VERIFICATION_FAILED_PREFIX}. */
    private const VERIFICATION_MESSAGES = [
        'profile_not_found' => 'That Messaging profile ID was not found in this Telnyx account.',
        'number_not_owned' => 'That sender number is not a number in this Telnyx account.',
        'number_not_active' => 'That number is not active in your Telnyx account yet.',
        'number_no_profile' => 'That number is not assigned to a Messaging profile in Telnyx.',
        'number_other_profile' => 'That number is assigned to a different Messaging profile in Telnyx than the one saved here.',
        'number_not_sms_enabled' => 'Telnyx reports that number cannot send text messages. Use an SMS-enabled number.',
    ];

    /** Whether a stored code is one a read-only credential recheck may re-evaluate (never a provider restriction). */
    public static function isRecheckable(?string $code): bool
    {
        return $code === 'invalid_api_key' || ($code !== null && str_starts_with($code, self::VERIFICATION_FAILED_PREFIX));
    }

    /** The code stored when the key is accepted but verification of the number/profile fails. */
    public static function verificationFailedCode(string $reason): string
    {
        return self::VERIFICATION_FAILED_PREFIX . $reason;
    }

    /**
     * Whether the channel can SEND now. Reason codes are stable; messages are for customers.
     *
     * `$forSms = false` is the credentials-only view used by Voice: an SMS-specific provider restriction or
     * messaging-profile/number verification failure must not block calling, but a rejected key still blocks both.
     * An `ok` result may carry the informational code `retry_pending` (a restriction cool-down has elapsed and ONE
     * retry is allowed): sending is permitted but is not shown as verified.
     *
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public static function sendReadiness(AgencyProspectingChannel $channel, bool $forSms = true): array
    {
        $server = $channel->sendingServer;
        $code = (string) $channel->last_error_code;

        return match (true) {
            $channel->status === AgencyProspectingChannel::STATUS_DISCONNECTED => self::no('disconnected', 'This Telnyx account was disconnected. Reconnect it or choose another sender.'),
            $channel->status !== AgencyProspectingChannel::STATUS_ACTIVE => self::no('channel_disabled', 'This sender is turned off. Turn it on or choose another sender.'),
            $channel->provider !== SendingServer::TYPE_TELNYX => self::no('not_telnyx', 'Only Telnyx accounts can be used as an agency sender.'),
            $server === null || ! $server->status => self::no('missing_credentials', 'The Telnyx connection is unavailable. Reconnect your account.'),
            self::credentials($channel) === null => self::no('missing_credentials', 'The saved Telnyx credentials can no longer be read. Reconnect your account.'),
            $channel->credentials_verified_at === null => self::no('unverified', 'This Telnyx account has not been verified yet. Reconnect it to verify.'),
            $code === 'invalid_api_key' => self::no('invalid_api_key', 'Telnyx rejected the saved access key. Use Recheck connection, or reconnect with a valid key.'),
            ! $forSms => ['ok' => true, 'code' => null, 'message' => null],
            str_starts_with($code, self::VERIFICATION_FAILED_PREFIX) => self::no('verification_failed', self::VERIFICATION_MESSAGES[substr($code, strlen(self::VERIFICATION_FAILED_PREFIX))] ?? 'The last connection check failed. Use Recheck connection.'),
            self::restrictionActive($channel) => self::no('sender_restricted', 'Telnyx refused to send from this number (a permission, registration or account restriction). Resolve it in Telnyx; sending is retried once after a cool-down.'),
            $code === 'sender_restricted' => ['ok' => true, 'code' => 'retry_pending', 'message' => 'Telnyx previously refused to send from this number. One retry will be attempted on the next send; sending is not verified until it succeeds.'],
            default => ['ok' => true, 'code' => null, 'message' => null],
        };
    }

    /**
     * {@see self::sendReadiness()}, but a stored authentication error is first re-verified (read-only,
     * locked, rate-limited — see AgencyByoConnectionRechecker) so a stale flag heals instead of blocking forever.
     * The channel passed in is refreshed in place.
     *
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public static function sendReadinessRecovering(AgencyProspectingChannel $channel): array
    {
        $state = self::sendReadiness($channel);

        if (! $state['ok'] && in_array($state['code'], ['invalid_api_key', 'verification_failed'], true)) {
            app(AgencyByoConnectionRechecker::class)->recheck($channel);
            $channel->refresh();
            $state = self::sendReadiness($channel);
        }

        return $state;
    }

    /** How long a provider restriction (HTTP 403 that is not an authentication failure) blocks sending before ONE new attempt is allowed. */
    public const RESTRICTION_COOLDOWN_SECONDS = 43200;

    /** Whether a persisted provider restriction is still inside its cool-down (credential checks never clear it). */
    public static function restrictionActive(AgencyProspectingChannel $channel): bool
    {
        if ($channel->last_error_code !== 'sender_restricted') {
            return false;
        }

        return $channel->last_error_at === null
            || $channel->last_error_at->gt(now()->subSeconds(self::RESTRICTION_COOLDOWN_SECONDS));
    }

    /**
     * After the cool-down, exactly ONE sender wins the right to try again (atomic across workers); a
     * restriction that is still in force re-stamps itself and blocks again, so a prohibited send is
     * never attempted in a loop.
     */
    public static function claimRestrictionRetry(AgencyProspectingChannel $channel): bool
    {
        if ($channel->last_error_code !== 'sender_restricted') {
            return true;
        }

        return \Illuminate\Support\Facades\Cache::add('byo-telnyx-restriction-retry:' . (int) $channel->id, 1, self::RESTRICTION_COOLDOWN_SECONDS);
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
