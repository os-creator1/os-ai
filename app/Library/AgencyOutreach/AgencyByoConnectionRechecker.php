<?php

namespace App\Library\AgencyOutreach;

use App\Library\AgencyProspecting\TelnyxCredentialVerifier;
use App\Models\AgencyProspectingChannel;
use Illuminate\Support\Facades\Cache;

/**
 * Bounded recovery of a stale `invalid_api_key` / failed-verification flag, using the existing read-only
 * {@see TelnyxCredentialVerifier} (GET requests only — it never sends a message and never replaces a key).
 *
 * Bounds: one verification in flight per channel (lock), and a minimum interval between
 * verifications (manual: short, automatic: longer), so page refreshes and concurrent send workers
 * cannot flood Telnyx. Only a confirmed authentication failure is ever cleared here, and only by a
 * verification that actually got past authentication; a provider restriction (`sender_restricted`)
 * is never cleared by credential verification.
 */
final class AgencyByoConnectionRechecker
{
    public const MANUAL_INTERVAL_SECONDS = 30;
    public const AUTOMATIC_INTERVAL_SECONDS = 600;

    /** Verifier reasons that say nothing conclusive about the key (so they neither clear nor set the flag). */
    private const INCONCLUSIVE = ['provider_unavailable', 'permission_denied'];

    public function __construct(private readonly TelnyxCredentialVerifier $verifier)
    {
    }

    /**
     * @return array{status: string, ok: bool, message: ?string}
     *         status: not_needed | recovered | still_blocked | inconclusive | throttled | in_progress | missing_credentials
     */
    public function recheck(AgencyProspectingChannel $channel, bool $manual = false): array
    {
        $channel = $channel->fresh() ?? $channel;

        if (! AgencyByoSender::isRecheckable($channel->last_error_code)) {
            return ['status' => 'not_needed', 'ok' => $channel->last_error_code === null, 'message' => null];
        }

        $interval = $manual ? self::MANUAL_INTERVAL_SECONDS : self::AUTOMATIC_INTERVAL_SECONDS;
        $stampKey = 'byo-telnyx-recheck-at:' . (int) $channel->id;
        $lock = Cache::lock('byo-telnyx-recheck-lock:' . (int) $channel->id, 60);

        if (! $lock->get()) {
            return ['status' => 'in_progress', 'ok' => false, 'message' => 'A connection check is already running. Try again in a moment.'];
        }

        try {
            // Re-read under the lock: a concurrent worker may just have recovered it.
            $channel = $channel->fresh() ?? $channel;

            if (! AgencyByoSender::isRecheckable($channel->last_error_code)) {
                return ['status' => 'not_needed', 'ok' => $channel->last_error_code === null, 'message' => null];
            }

            $last = Cache::get($stampKey);

            if (is_int($last) && (now()->getTimestamp() - $last) < $interval) {
                return ['status' => 'throttled', 'ok' => false, 'message' => 'The connection was checked a moment ago. Try again shortly.'];
            }

            $credentials = AgencyByoSender::credentials($channel);

            if ($credentials === null) {
                return ['status' => 'missing_credentials', 'ok' => false, 'message' => 'The saved Telnyx credentials can no longer be read. Reconnect your account.'];
            }

            Cache::put($stampKey, now()->getTimestamp(), 3600);

            $check = $this->verifier->verify($credentials['api_key'], $credentials['profile_id'], (string) $channel->sender_number);

            if ($check['ok']) {
                // Full success is the ONLY thing that restores readiness. A capability field Telnyx omitted keeps the
                // previous confirmation timestamp; it is never invented, and never erased by an omission.
                $channel->forceFill([
                    'credentials_verified_at' => now(),
                    'last_error_code' => null,
                    'last_error_at' => null,
                    'sms_capability_confirmed_at' => $check['sms_confirmed'] ? now() : $channel->sms_capability_confirmed_at,
                ])->save();

                return ['status' => 'recovered', 'ok' => true, 'message' => null];
            }

            if ($check['reason'] === 'invalid_api_key') {
                $channel->forceFill(['last_error_code' => 'invalid_api_key', 'last_error_at' => now()])->save();

                return ['status' => 'still_blocked', 'ok' => false, 'message' => $check['message']];
            }

            if (in_array($check['reason'], self::INCONCLUSIVE, true)) {
                return ['status' => 'inconclusive', 'ok' => false, 'message' => $check['message']];
            }

            // The key was accepted but the number/profile check failed: the sender stays BLOCKED, with that
            // reason, and the credential-verified timestamp is not touched. A later successful recheck recovers it.
            $channel->forceFill([
                'last_error_code' => AgencyByoSender::verificationFailedCode((string) $check['reason']),
                'last_error_at' => now(),
                // Telnyx explicitly reporting "not SMS enabled" is a current restriction, not history to keep.
                'sms_capability_confirmed_at' => $check['reason'] === 'number_not_sms_enabled' ? null : $channel->sms_capability_confirmed_at,
            ])->save();

            return ['status' => 'still_blocked', 'ok' => false, 'message' => $check['message']];
        } finally {
            $lock->release();
        }
    }
}
