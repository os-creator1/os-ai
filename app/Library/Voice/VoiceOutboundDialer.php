<?php

namespace App\Library\Voice;

use App\Enums\Voice\VoiceCallStatus;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-mediated outgoing calls — the only way MotionGrove places a call.
 *
 * The browser's Telnyx credential is NOT trusted to dial: the credential connection is verified to have no
 * outbound voice profile (see VoiceSetupService / VoiceRegistrationService), so a browser that calls the SDK
 * directly cannot reach the phone network. Instead:
 *
 *   1. dial()   MotionGrove rings the user's OWN browser identity from its Call Control application, with the
 *               destination number as the caller ID shown, and the call's own id as client_state.
 *   2. bridge() when that leg is answered (the person's browser picks it up), MotionGrove transfers it to the
 *               destination and caller ID it recorded when it validated the request — never anything the browser
 *               said — after re-checking, right now, that the user, Business, number and destination are
 *               still allowed. Once per call.
 *
 * Destination restrictions Telnyx itself enforces live on the Call Control application's outbound voice profile.
 */
final class VoiceOutboundDialer
{
    /** The browser must answer the ringing leg within this many seconds, and a bridge is refused after twice that. */
    private const RING_SECONDS = 30;

    public const TEARDOWN_PENDING = 'pending';
    public const TEARDOWN_DONE = 'done';
    public const TEARDOWN_FAILED = 'failed';
    public const TEARDOWN_MAX_ATTEMPTS = 6;
    private const TEARDOWN_RETRY_SECONDS = 20;

    /** A claim never dispatched this long ago was abandoned; a dispatch with no outcome this long ago is unresolved. */
    public const ABANDONED_CLAIM_SECONDS = 90;
    public const UNRESOLVED_DISPATCH_SECONDS = 180;

    public function __construct(
        private readonly VoiceProviderClient $provider,
        private readonly VoiceCredentials $credentials,
        private readonly VoiceRegistrationService $registrations,
        private readonly VoiceNumberResolver $numbers,
        private readonly VoiceDestinationPolicy $policy,
        private readonly BusinessRouteAccess $access,
    ) {
    }

    public function dial(User $user, Business $business, VoiceNumber $number, VoiceCall $call): VoiceCall
    {
        $registration = $this->registrations->onlineRegistration((int) $user->id, $number);

        if ($registration === null) {
            $this->fail($call, 'browser_not_registered');

            throw new VoiceUnavailableException('browser_not_registered', 'Your browser is not connected for calling yet. Wait a moment and try again.', 409);
        }

        try {
            $creds = $this->credentials->forNumber($number, $business);
            $leg = $this->provider->dial(
                $creds['api_key'],
                $creds['app_id'],
                'sip:' . $registration->sip_username . '@sip.telnyx.com',
                $call->to_number,
                base64_encode($call->uid),
                self::RING_SECONDS,
            );
        } catch (VoiceProviderException|VoiceUnavailableException $e) {
            $this->fail($call, $e instanceof VoiceProviderException ? $e->reason : $e->reason);

            throw $e;
        }

        // Record the provider ids under the row lock. A cancel that landed while the provider was still creating
        // the leg found no id to hang up; whoever finds the cancel here owns the teardown of the leg just created.
        $canceled = DB::transaction(function () use ($call, $leg): bool {
            $row = VoiceCall::query()->whereKey($call->id)->lockForUpdate()->firstOrFail();
            $row->forceFill([
                'provider_call_control_id' => $leg['call_control_id'],
                'provider_call_session_id' => $leg['call_session_id'],
                'provider_leg_id' => $leg['call_leg_id'],
            ]);

            $stop = $row->cancel_requested_at !== null || $row->statusEnum()->isFinal();

            if ($stop) {
                $row->leg_teardown_status = self::TEARDOWN_PENDING;
            }

            $row->save();

            return $stop;
        });

        $call = $call->fresh();

        if ($canceled) {
            $this->teardown($call);
        }

        return $call;
    }

    /**
     * The browser leg was answered: bridge to the validated destination, once, after re-authorizing.
     *
     * Three row-locked steps serialize this against cancel(): CLAIM (nothing may have closed the call),
     * DISPATCH GATE (a cancel that arrived while we were authorizing wins; we never send the transfer), and —
     * because a cancel can still land while the transfer request is in flight — a re-check afterwards that
     * tears the legs down.
     */
    public function bridge(VoiceCall $call): void
    {
        $call = DB::transaction(function () use ($call): ?VoiceCall {
            $row = VoiceCall::query()->whereKey($call->id)->lockForUpdate()->first();

            if ($row === null || $row->bridge_requested_at !== null || $row->cancel_requested_at !== null || $row->client_reported_ended_at !== null) {
                return null;
            }

            // A call that is finished (or that the browser reported ended) can never bridge.
            if ($row->statusEnum()->isFinal() || $row->statusEnum() === VoiceCallStatus::Unconfirmed) {
                return null;
            }

            $row->forceFill(['bridge_requested_at' => now()])->save();

            return $row;
        });

        if ($call === null) {
            return;
        }

        $number = VoiceNumber::query()->whereKey((int) $call->voice_number_id)->first();
        $business = Business::query()->find((int) $call->business_id);
        $user = $call->user_id !== null ? User::query()->find((int) $call->user_id) : null;

        $deny = match (true) {
            $number === null || $business === null || $user === null => 'authorization_revoked',
            $call->initiated_at === null || $call->initiated_at->lt(now()->subSeconds(self::RING_SECONDS * 2)) => 'authorization_expired',
            ! $this->access->actorMayUseBusiness($user, $business) || ! Gate::forUser($user)->allows('chat_box') => 'authorization_revoked',
            $number->phone_number !== $call->from_number || $this->numbers->problem($number, $business) !== null => 'number_no_longer_callable',
            $this->policy->refusal($call->to_number) !== null => 'destination_not_allowed',
            default => null,
        };

        try {
            $creds = $business !== null && $number !== null ? $this->credentials->forNumber($number, $business) : null;
        } catch (VoiceUnavailableException) {
            $creds = null;
            $deny ??= 'voice_unavailable';
        }

        if ($creds === null) {
            // We cannot reach the provider without credentials: the leg is still owed a hangup (retried later).
            $this->owe($call);
            $this->fail($call, $deny ?? 'voice_unavailable');

            return;
        }

        if ($deny !== null) {
            $this->owe($call);
            $this->teardown($call);
            $this->fail($call, $deny);

            return;
        }

        // Dispatch gate: from this committed moment a cancel knows a transfer may be in flight.
        $go = DB::transaction(function () use ($call): bool {
            $row = VoiceCall::query()->whereKey($call->id)->lockForUpdate()->first();

            if ($row === null || $row->cancel_requested_at !== null || $row->client_reported_ended_at !== null || $row->statusEnum()->isFinal()
                || $row->statusEnum() === VoiceCallStatus::Unconfirmed) {
                return false;
            }

            $row->forceFill(['bridge_dispatched_at' => now()])->save();

            return true;
        });

        if (! $go) {
            // Cancel (or the browser) won before dispatch: no transfer is sent. cancel() owns the hangup.
            return;
        }

        try {
            // The destination and caller ID are the ones recorded when the request was validated.
            $this->provider->transferToSip($creds['api_key'], (string) $call->provider_call_control_id, $call->to_number, $call->from_number);
        } catch (VoiceProviderException $e) {
            // A 4xx is a definitive refusal. A timeout / transport error / 5xx is AMBIGUOUS: Telnyx may have
            // accepted the transfer. Either way the call is closed and our leg torn down, but an ambiguous one is
            // recorded as such, is never transferred a second time (bridge_requested_at stays set), and a customer
            // leg that shows up late is hung up because teardown stays owed (see VoiceCallReconciler).
            $ambiguous = $e->reason === 'provider_unavailable';
            Log::info('voice.bridge_failed', ['call' => $call->uid, 'reason' => $e->reason, 'ambiguous' => $ambiguous]);
            $this->owe($call);
            $this->teardown($call);
            $this->fail($call, $ambiguous ? 'bridge_uncertain' : 'bridge_failed');

            return;
        }

        // A cancel (or browser hangup) that arrived while the transfer was in flight could not stop it:
        // whatever legs exist now must be torn down.
        $after = VoiceCall::query()->whereKey($call->id)->first();

        if ($after !== null && ($after->cancel_requested_at !== null || $after->client_reported_ended_at !== null)) {
            $this->owe($after);
            $this->teardown($after);
        }
    }

    /**
     * The user pressed Cancel (or End): close the record and hang up the provider legs.
     *
     * The close happens under the same row lock the bridge claim uses, so exactly one of two things is true
     * afterwards: the bridge never dispatched (and now never will), or its dispatch is recorded and the
     * teardown below — plus the bridge's own post-transfer check, the late-leg handling in the reconciler and
     * the retry command — make sure every provider leg is eventually hung up.
     *
     * @return bool true when the provider legs are confirmed gone (or none exist); false when the hangup failed
     *              and is queued for retry. The call itself is canceled for the user either way.
     */
    public function cancel(VoiceCall $call, ?VoiceNumber $number = null, ?Business $business = null): bool
    {
        $row = DB::transaction(function () use ($call): ?VoiceCall {
            $row = VoiceCall::query()->whereKey($call->id)->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            $final = $row->statusEnum()->isFinal();

            if (! $final) {
                $update = ['cancel_requested_at' => $row->cancel_requested_at ?? now()];

                // A call the customer already answered is a connected call: End hangs it up, the provider's
                // hangup event decides the outcome. Anything not yet answered is a cancellation.
                if ($row->answered_at === null) {
                    $update += [
                        'status' => VoiceCallStatus::Canceled->value,
                        'status_rank' => VoiceCallStatus::Canceled->rank(),
                        'hangup_cause' => 'user_canceled',
                        'ended_at' => now(),
                        'duration_seconds' => 0,
                    ];
                }

                $row->forceFill($update);
            }

            // Owe a hangup for any leg we know of (also for an already-final call whose earlier hangup failed).
            if (($row->provider_call_control_id !== null || $row->customer_call_control_id !== null) && $row->leg_teardown_status !== self::TEARDOWN_DONE) {
                $row->leg_teardown_status = self::TEARDOWN_PENDING;
            }

            $row->save();

            return $row;
        });

        if ($row === null || $row->leg_teardown_status !== self::TEARDOWN_PENDING) {
            return true;
        }

        return $this->teardown($row);
    }

    /**
     * Hang up every provider leg we know of for this call and record the outcome. Never silent: a failure is
     * logged, counted, stored (status `failed` + a stable reason) and retried by retryPendingTeardowns().
     * A leg the provider no longer has counts as hung up. Credentials come only from the call's OWN number and
     * Business; a number that is gone, or that belongs to another Business, is "number_unavailable" — never a
     * reason to borrow another tenant's key. No lock is held while Telnyx is contacted.
     */
    public function teardown(VoiceCall $call): bool
    {
        $row = VoiceCall::query()->whereKey($call->id)->first();

        // Idempotent: a teardown already confirmed is not repeated. Anything that makes a hangup owed again (a late
        // customer leg, a re-cancel, a browser-ended hint) sets the status back to pending under the row lock first.
        if ($row === null || $row->leg_teardown_status === self::TEARDOWN_DONE) {
            return true;
        }

        $legs = $this->legsOf($row);
        $error = null;

        if ($legs !== []) {
            try {
                $number = VoiceNumber::query()->whereKey((int) $row->voice_number_id)->first();
                $business = Business::query()->find((int) $row->business_id);

                if ($number === null || $business === null || (int) $number->business_id !== (int) $row->business_id) {
                    throw new VoiceUnavailableException('number_unavailable', 'The calling number for this call is no longer available.');
                }

                $apiKey = $this->credentials->forNumber($number, $business)['api_key'];

                foreach ($legs as $leg) {
                    try {
                        $this->provider->hangupCall($apiKey, (string) $leg);
                    } catch (VoiceProviderException $e) {
                        $error ??= $e->reason;
                    } catch (Throwable $e) {
                        $error ??= 'unexpected_error';
                    }
                }
            } catch (VoiceUnavailableException $e) {
                $error = $e->reason;
            } catch (Throwable) {
                // Only a safe, fixed reason is stored or logged — never the exception message.
                $error = 'unexpected_error';
            }
        }

        return $this->recordTeardown($row->id, $legs, $error);
    }

    /**
     * Re-attempt provider hangups that failed or were never made. Bounded; returns how many succeeded.
     * Each call is its own failure boundary: one bad row, credential or exception cannot starve the others.
     */
    public function retryPendingTeardowns(int $limit = 50): int
    {
        $done = 0;

        $calls = VoiceCall::query()
            ->whereIn('leg_teardown_status', [self::TEARDOWN_PENDING, self::TEARDOWN_FAILED])
            ->where('leg_teardown_attempts', '<', self::TEARDOWN_MAX_ATTEMPTS)
            ->where('created_at', '>', now()->subDay())
            ->where(fn ($q) => $q->whereNull('leg_teardown_at')->orWhere('leg_teardown_at', '<', now()->subSeconds(self::TEARDOWN_RETRY_SECONDS)))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($calls as $call) {
            try {
                $done += $this->teardown($call) ? 1 : 0;
            } catch (Throwable $e) {
                $this->recordUnexpectedTeardownFailure($call, $e);
            }
        }

        return $done;
    }

    /**
     * Close outgoing calls whose bridge was abandoned or never resolved, and tear their legs down.
     *
     *  - claimed, never dispatched, older than ABANDONED_CLAIM_SECONDS: the transfer was provably never sent
     *    (the dispatch stamp precedes the request), so the call is closed `bridge_abandoned`.
     *  - dispatched, no outcome and no customer leg reported after UNRESOLVED_DISPATCH_SECONDS: the transfer's
     *    outcome is UNCERTAIN; the call is closed `bridge_unresolved`. A second transfer is NEVER sent — recovery
     *    only requests hangups, and a customer leg that appears later is hung up by the reconciler.
     *
     * A call the customer answered, one whose customer leg is known, or one the user/provider already closed is
     * never touched, however long it has lasted.
     */
    public function recoverAbandonedBridges(int $limit = 50): int
    {
        $recovered = 0;

        $candidates = VoiceCall::query()
            ->where('direction', VoiceCall::DIRECTION_OUTBOUND)
            ->whereIn('status', [VoiceCallStatus::Initiating->value, VoiceCallStatus::Ringing->value])
            ->whereNull('answered_at')
            ->whereNull('customer_call_control_id')
            ->whereNull('cancel_requested_at')
            ->whereNotNull('bridge_requested_at')
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->whereNull('bridge_dispatched_at')->where('bridge_requested_at', '<', now()->subSeconds(self::ABANDONED_CLAIM_SECONDS)))
                ->orWhere('bridge_dispatched_at', '<', now()->subSeconds(self::UNRESOLVED_DISPATCH_SECONDS)))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($candidates as $candidate) {
            try {
                $closed = DB::transaction(function () use ($candidate): ?VoiceCall {
                    $row = VoiceCall::query()->whereKey($candidate->id)->lockForUpdate()->first();

                    // Re-check everything under the lock: the world may have moved since the scan.
                    if ($row === null || $row->statusEnum()->isFinal() || $row->statusEnum() === VoiceCallStatus::Unconfirmed
                        || $row->answered_at !== null || $row->customer_call_control_id !== null || $row->cancel_requested_at !== null
                        || $row->bridge_requested_at === null) {
                        return null;
                    }

                    $dispatched = $row->bridge_dispatched_at !== null;
                    $stale = $dispatched
                        ? $row->bridge_dispatched_at->lt(now()->subSeconds(self::UNRESOLVED_DISPATCH_SECONDS))
                        : $row->bridge_requested_at->lt(now()->subSeconds(self::ABANDONED_CLAIM_SECONDS));

                    if (! $stale) {
                        return null;
                    }

                    $row->forceFill([
                        'status' => VoiceCallStatus::Failed->value,
                        'status_rank' => VoiceCallStatus::Failed->rank(),
                        'hangup_cause' => $dispatched ? 'bridge_unresolved' : 'bridge_abandoned',
                        'ended_at' => now(),
                        'duration_seconds' => 0,
                        'leg_teardown_status' => self::TEARDOWN_PENDING,
                    ])->save();

                    return $row;
                });

                if ($closed !== null) {
                    $recovered++;
                    Log::warning('voice.bridge_recovered', ['call' => $closed->uid, 'cause' => $closed->hangup_cause]);
                    $this->teardown($closed);
                }
            } catch (Throwable $e) {
                Log::error('voice.bridge_recovery_failed', ['call' => $candidate->uid, 'exception' => class_basename($e)]);
            }
        }

        return $recovered;
    }

    private function owe(VoiceCall $call): void
    {
        VoiceCall::query()->whereKey($call->id)->where(fn ($q) => $q->whereNull('leg_teardown_status')->orWhere('leg_teardown_status', '!=', self::TEARDOWN_DONE))
            ->update(['leg_teardown_status' => self::TEARDOWN_PENDING]);
    }

    /** @return list<string> */
    private function legsOf(VoiceCall $call): array
    {
        return array_values(array_unique(array_map('strval', array_filter([$call->provider_call_control_id, $call->customer_call_control_id]))));
    }

    /**
     * Record the outcome of one teardown pass under the row lock, against the CURRENT row. Legs that were
     * recorded after this pass read the list (a late customer leg) keep the teardown pending, so an older
     * successful hangup can never erase a newer obligation. Returns true only when every known leg is confirmed.
     *
     * @param  list<string>  $attempted
     */
    private function recordTeardown(int $callId, array $attempted, ?string $error): bool
    {
        [$confirmed, $row, $exhaustedNow] = DB::transaction(function () use ($callId, $attempted, $error): array {
            $row = VoiceCall::query()->whereKey($callId)->lockForUpdate()->first();

            if ($row === null) {
                return [true, null, false];
            }

            $missed = array_values(array_diff($this->legsOf($row), $attempted));
            $attempts = (int) $row->leg_teardown_attempts + ($attempted !== [] ? 1 : 0);
            $confirmed = $error === null && $missed === [];
            $exhaustedNow = false;

            $row->leg_teardown_at = now();
            $row->leg_teardown_attempts = min(255, $attempts);

            if ($confirmed) {
                $row->leg_teardown_status = self::TEARDOWN_DONE;
                $row->leg_teardown_error = null;
                $row->leg_teardown_exhausted_at = null;
            } elseif ($error === null) {
                $row->leg_teardown_status = self::TEARDOWN_PENDING; // a newer leg is still owed
            } else {
                $row->leg_teardown_status = self::TEARDOWN_FAILED;
                $row->leg_teardown_error = Str::limit($error, 48, '');

                if ($attempts >= self::TEARDOWN_MAX_ATTEMPTS && $row->leg_teardown_exhausted_at === null) {
                    $row->leg_teardown_exhausted_at = now();
                    $exhaustedNow = true;
                }
            }

            $row->save();

            return [$confirmed, $row, $exhaustedNow];
        });

        if ($row !== null && $error !== null) {
            Log::warning('voice.leg_teardown_failed', [
                'call' => $row->uid, 'reason' => $error, 'attempts' => (int) $row->leg_teardown_attempts,
                'will_retry' => (int) $row->leg_teardown_attempts < self::TEARDOWN_MAX_ATTEMPTS,
            ]);
        }

        if ($exhaustedNow && $row !== null) {
            // Emitted once per call (guarded by leg_teardown_exhausted_at under the row lock). Identifiers only.
            Log::error('voice.leg_teardown_exhausted', [
                'call' => $row->uid,
                'call_id' => (int) $row->id,
                'business_id' => (int) $row->business_id,
                'voice_number_id' => $row->voice_number_id !== null ? (int) $row->voice_number_id : null,
                'provider_call_session_id' => $row->provider_call_session_id,
                'provider_call_control_id' => $row->provider_call_control_id,
                'customer_call_control_id' => $row->customer_call_control_id,
                'last_reason' => $row->leg_teardown_error,
                'attempts' => (int) $row->leg_teardown_attempts,
                'provider_leg_may_remain' => true,
            ]);
        }

        return $confirmed;
    }

    /** The per-call safety net: something outside the provider/credential handling threw. Never claims success. */
    private function recordUnexpectedTeardownFailure(VoiceCall $call, Throwable $e): void
    {
        Log::error('voice.leg_teardown_unexpected', ['call' => $call->uid, 'exception' => class_basename($e)]);

        try {
            VoiceCall::query()->whereKey($call->id)->update([
                'leg_teardown_status' => self::TEARDOWN_FAILED,
                'leg_teardown_error' => 'unexpected_error',
                'leg_teardown_at' => now(),
                'leg_teardown_attempts' => DB::raw('LEAST(leg_teardown_attempts + 1, 255)'),
            ]);
        } catch (Throwable) {
            // Nothing more can be recorded; the next sweep sees the unchanged row and tries again.
        }
    }

    private function fail(VoiceCall $call, string $cause): void
    {
        DB::transaction(function () use ($call, $cause): void {
            $fresh = VoiceCall::query()->whereKey($call->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->statusEnum()->isFinal()) {
                return;
            }

            $fresh->forceFill([
                'status' => VoiceCallStatus::Failed->value,
                'status_rank' => VoiceCallStatus::Failed->rank(),
                'hangup_cause' => $cause,
                'ended_at' => now(),
                'duration_seconds' => 0,
            ])->save();
        });
    }
}
