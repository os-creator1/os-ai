<?php

namespace App\Library\AgencyOutreach;

use App\Library\AgencyProspecting\TelnyxCredentialVerifier;
use App\Models\AgencyProspectingChannel;
use Illuminate\Support\Facades\Cache;

/**
 * Bounded recovery of a stale `invalid_api_key` flag, using the existing read-only
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

        if ($channel->last_error_code !== 'invalid_api_key') {
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

            if ($channel->last_error_code !== 'invalid_api_key') {
                return ['status' => 'not_needed', 'ok' => $channel->last_error_code === null, 'message' => null];
            }

            $last = Cache::get($stampKey);

            if (is_int($last) && (time() - $last) < $interval) {
                return ['status' => 'throttled', 'ok' => false, 'message' => 'The connection was checked a moment ago. Try again shortly.'];
            }

            $credentials = AgencyByoSender::credentials($channel);

            if ($credentials === null) {
                return ['status' => 'missing_credentials', 'ok' => false, 'message' => 'The saved Telnyx credentials can no longer be read. Reconnect your account.'];
            }

            Cache::put($stampKey, time(), 3600);

            $check = $this->verifier->verify($credentials['api_key'], $credentials['profile_id'], (string) $channel->sender_number);

            if ($check['ok'] || ! in_array($check['reason'], array_merge(self::INCONCLUSIVE, ['invalid_api_key']), true)) {
                // The key got past authentication. Clear ONLY the stale auth flag; a failing number/profile
                // check is recorded as its own (non-blocking) code, exactly as the connect flows do.
                $channel->forceFill([
                    'credentials_verified_at' => now(),
                    'last_error_code' => $check['ok'] ? null : $check['reason'],
                    'last_error_at' => $check['ok'] ? null : now(),
                    'sms_capability_confirmed_at' => $check['ok'] ? ($check['sms_confirmed'] ? now() : null) : $channel->sms_capability_confirmed_at,
                ])->save();

                return ['status' => 'recovered', 'ok' => $check['ok'], 'message' => $check['ok'] ? null : $check['message']];
            }

            if ($check['reason'] === 'invalid_api_key') {
                $channel->forceFill(['last_error_at' => now()])->save();

                return ['status' => 'still_blocked', 'ok' => false, 'message' => $check['message']];
            }

            return ['status' => 'inconclusive', 'ok' => false, 'message' => $check['message']];
        } finally {
            $lock->release();
        }
    }
}
