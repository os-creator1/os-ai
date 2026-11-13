<?php

namespace App\Library\Voice\Inbound;

use App\Library\Messaging\E164Normalizer;
use App\Library\Voice\BrowserCredentialProvisioner;
use App\Library\Voice\VoiceCallStatusRecorder;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\BusinessInboundCallSetting;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessVoiceBrowserCredential;
use App\Models\BusinessVoiceCall;
use App\Models\BusinessVoiceCallLeg;
use App\Models\Contacts;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Routes ONE inbound call (Telnyx Call Control) from signature-verified webhooks. Called only after the controller has
 * verified the Ed25519 signature.
 *
 *   call.initiated (incoming)  -> the called number is resolved to exactly one Business (never from anything the caller
 *                                 controls), limits and loop checks run, the call is saved, then per the Business's mode
 *                                 its available users' browsers are rung and/or the verified personal phone is dialled.
 *   a leg answers              -> exactly one leg may win (compare-and-set on claimed_leg_id); every other leg is hung up
 *                                 and the winner is bridged to the caller.
 *   every leg ends unanswered  -> next phase (forward) or a recorded missed/declined call; the caller is rejected, never
 *                                 left ringing.
 *
 * Idempotent per Telnyx event id; every state change happens under a row lock and every network command runs AFTER the
 * transaction commits (so no lock spans an HTTP call) with a deterministic command_id. The caller's own leg is never
 * answered until somebody has actually answered, so a caller is never connected into silence.
 */
final class InboundCallRouter
{
    private const API_BASE = 'https://api.telnyx.com/v2';

    public function __construct(
        private readonly TelnyxCallCommands $commands,
        private readonly InboundCallingGate $gate,
        private readonly ForwardDestinationPolicy $policy,
        private readonly BrowserCredentialProvisioner $credentials,
        private readonly ForwardVerifier $verifier,
    ) {
    }

    /** @return string|null applied|duplicate|ignored, or null when the event is not about an inbound/verification call */
    public function apply(string $eventId, string $type, string $callControlId, array $payload, ?string $occurredAt = null): ?string
    {
        if ($callControlId === '' || ! str_starts_with($type, 'call.')) {
            return null;
        }
        if (! $this->gate->platformEnabled()) {
            return null; // flag off: this class does nothing at all and existing behaviour is untouched
        }

        if ($type === 'call.initiated' && ($payload['direction'] ?? null) === 'incoming') {
            return $this->newInbound($eventId, $callControlId, $payload);
        }

        $after = null;
        $result = DB::transaction(function () use ($eventId, $type, $callControlId, $payload, $occurredAt, &$after): ?string {
            $found = $this->locate($callControlId, $payload);
            if ($found === null) {
                return null;
            }
            [$call, $leg] = $found;

            try {
                $call->getConnection()->table('business_voice_call_events')->insert([
                    'provider_event_id' => $eventId, 'business_voice_call_id' => $call->id,
                    'event_type' => $type, 'applied' => true, 'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return VoiceCallStatusRecorder::DUPLICATE;
            }

            $at = ($type === 'call.hangup' ? $this->parse($payload['end_time'] ?? null) : null) ?? $this->parse($occurredAt) ?? now();
            $after = $call->kind === 'forward_verify'
                ? $this->verifier->transition($call, $type, $payload, $at)
                : $this->transition($call, $leg, $type, $payload, $at);
            $call->save();

            return VoiceCallStatusRecorder::APPLIED;
        });

        if ($after !== null) {
            $after();
        }

        return $result;
    }

    // ------------------------------------------------------------------ new inbound call

    private function newInbound(string $eventId, string $callerLegId, array $payload): ?string
    {
        $connection = trim((string) config('messaging.voice.connection_id'));
        if (isset($payload['connection_id']) && (string) $payload['connection_id'] !== $connection) {
            return null; // not this platform's Call Control application
        }

        $to = E164Normalizer::normalize(is_string($payload['to'] ?? null) ? $payload['to'] : null);
        $from = E164Normalizer::normalize(is_string($payload['from'] ?? null) ? $payload['from'] : null);
        $number = $to === null ? null : BusinessMessagingNumber::query()->where('phone_number', $to)->with('identity')->get()
            ->first(fn (BusinessMessagingNumber $n) => $n->isActive() && $n->voice_assigned_at !== null && $n->voice_connection_id === $connection);
        $business = $number?->identity?->business;
        if ($number === null || $business === null) {
            return null; // not a number this platform routes: untouched
        }
        if (! $this->gate->available($business) || $business->status?->value !== 'active') {
            return null;
        }

        $setting = BusinessInboundCallSetting::query()->where('business_id', $business->id)->first();
        if (! $this->gate->routable($business, $setting)) {
            $this->commands->send($callerLegId, 'reject', ['cause' => 'CALL_REJECTED'], 'off');

            return VoiceCallStatusRecorder::IGNORED;
        }

        $cfg = (array) config('messaging.voice.inbound');
        $tokens = [];
        $callId = null;

        $duplicate = DB::transaction(function () use ($eventId, $callerLegId, $number, $business, $setting, $from, $to, $cfg, &$tokens, &$callId): bool {
            // Serialise inbound arrivals for this number so the limit checks and the insert are one atomic step.
            BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();
            if (BusinessVoiceCall::query()->where('telnyx_call_control_id', $callerLegId)->exists()) {
                return true;
            }

            $window = now()->subSeconds((int) $cfg['max_duration_secs'] + 300);
            $active = BusinessVoiceCall::query()->where('business_id', $business->id)->where('kind', 'inbound')
                ->whereIn('status', [BusinessVoiceCall::STATUS_REQUESTED, BusinessVoiceCall::STATUS_INITIATED, BusinessVoiceCall::STATUS_ANSWERED])
                ->where('created_at', '>', $window)->count();
            $hour = BusinessVoiceCall::query()->where('business_id', $business->id)->where('kind', 'inbound')->where('created_at', '>', now()->subHour())->count();
            $blocked = null;
            if ($active >= (int) $cfg['max_concurrent_per_business'] || $hour >= (int) $cfg['max_per_hour_per_business']) {
                $blocked = 'limit_reached';
            } elseif ($from !== null && ($this->isPlatformNumber($from) || $this->isForwardNumber($setting, $from))) {
                $blocked = 'loop_prevented'; // a call from one of our own numbers (or our forward target) would loop
            }

            $call = BusinessVoiceCall::create([
                'uid' => (string) Str::uuid(), 'kind' => 'inbound', 'direction' => 'inbound', 'channel' => 'inbound',
                'business_id' => $business->id, 'business_messaging_number_id' => $number->id,
                'to_hash' => BusinessVoiceCall::hashDestination((string) $to),
                'from_hash' => $from !== null ? BusinessVoiceCall::hashDestination($from) : null,
                'caller_number' => $from,
                'telnyx_call_control_id' => $callerLegId,
                'contact_id' => $from !== null ? Contacts::query()->where('business_id', $business->id)->where('phone', ltrim($from, '+'))->value('id') : null,
                'route_mode' => $setting->mode, 'phase' => $blocked === null ? 'ringing_browser' : 'done',
                'status' => $blocked === null ? BusinessVoiceCall::STATUS_INITIATED : BusinessVoiceCall::STATUS_ENDED,
                'initiated_at' => now(),
                'ended_at' => $blocked === null ? null : now(),
                'outcome' => $blocked === null ? null : 'missed', 'failure_code' => $blocked,
                'ring_deadline_at' => now()->addSeconds($setting->browser_ring_secs + 8),
            ]);
            $callId = $call->id;
            $call->getConnection()->table('business_voice_call_events')->insert([
                'provider_event_id' => $eventId, 'business_voice_call_id' => $call->id, 'event_type' => 'call.initiated', 'applied' => true, 'received_at' => now(),
            ]);

            if ($blocked === null && $setting->usesBrowser()) {
                foreach ($this->eligibleUsers($business, (int) $cfg['max_browser_fanout']) as $userId) {
                    $token = bin2hex(random_bytes(32));
                    $leg = BusinessVoiceCallLeg::create([
                        'business_voice_call_id' => $call->id, 'kind' => BusinessVoiceCallLeg::KIND_BROWSER, 'user_id' => $userId,
                        'client_state_hash' => hash('sha256', $token),
                    ]);
                    $tokens[$leg->id] = [$token, $userId];
                }
            }

            return false;
        });

        if ($duplicate || $callId === null) {
            return VoiceCallStatusRecorder::DUPLICATE;
        }

        $call = BusinessVoiceCall::query()->find($callId);
        if ($call->status !== BusinessVoiceCall::STATUS_INITIATED) {
            $this->commands->send($callerLegId, 'reject', ['cause' => 'USER_BUSY'], 'limit');

            return VoiceCallStatusRecorder::APPLIED;
        }

        foreach ($tokens as $legId => [$token, $userId]) {
            $uri = $this->credentials->sipUriFor($business, (int) $userId);
            $this->dialLeg($call, (int) $legId, $uri, (string) $number->phone_number, $token, (int) $setting->browser_ring_secs, (int) $cfg['max_duration_secs'] + 90);
        }

        $this->advance($callId);

        return VoiceCallStatusRecorder::APPLIED;
    }

    /** Users whose browser is signed in, recently seen, permitted and not already on a call. @return list<int> */
    private function eligibleUsers(Business $business, int $limit): array
    {
        $fresh = now()->subSeconds((int) config('messaging.voice.inbound.presence_ttl_secs'));
        $busy = BusinessVoiceCall::query()->whereIn('status', [BusinessVoiceCall::STATUS_REQUESTED, BusinessVoiceCall::STATUS_INITIATED, BusinessVoiceCall::STATUS_ANSWERED])
            ->where('created_at', '>', now()->subSeconds((int) config('messaging.voice.inbound.max_duration_secs') + 300))
            ->where(fn ($q) => $q->whereNotNull('answered_by_user_id')->orWhereNotNull('initiated_by_user_id'))
            ->get(['answered_by_user_id', 'initiated_by_user_id', 'kind', 'status'])
            ->flatMap(fn ($c) => $c->kind === 'inbound' && $c->status === BusinessVoiceCall::STATUS_INITIATED ? [] : [(int) $c->answered_by_user_id, (int) $c->initiated_by_user_id])
            ->filter()->all();

        // A user already ringing for another inbound call is busy too, so each browser has at most one ringing call.
        $ringing = BusinessVoiceCallLeg::query()->where('status', 'ringing')->whereNotNull('user_id')
            ->whereIn('business_voice_call_id', BusinessVoiceCall::query()->where('kind', 'inbound')->where('status', BusinessVoiceCall::STATUS_INITIATED)->select('id'))
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $busy = array_merge($busy, $ringing);

        $ids = [];
        $rows = BusinessVoiceBrowserCredential::query()->where('business_id', $business->id)
            ->where('expires_at', '>', now())->where('presence_at', '>', $fresh)->orderByDesc('presence_at')->get();
        foreach ($rows as $row) {
            $user = User::query()->find($row->user_id);
            if ($user === null || in_array((int) $user->id, $busy, true)
                || ! app(\App\Library\Voice\CallingAuthority::class)->mayCall($user, $business)
                || ! app(BusinessRouteAccess::class)->actorMayUseBusiness($user, $business)) {
                continue;
            }
            $ids[] = (int) $user->id;
            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    private function isPlatformNumber(string $e164): bool
    {
        return BusinessMessagingNumber::query()->where('phone_number', $e164)->exists();
    }

    private function isForwardNumber(BusinessInboundCallSetting $setting, string $e164): bool
    {
        return $setting->forward_number_hash !== null
            && hash_equals($setting->forward_number_hash, BusinessInboundCallSetting::hashNumber((int) $setting->business_id, $e164));
    }

    // ------------------------------------------------------------------ event transitions

    /** @return \Closure|null network step to run after commit */
    private function transition(BusinessVoiceCall $call, ?BusinessVoiceCallLeg $leg, string $type, array $payload, Carbon $at): ?\Closure
    {
        $terminal = in_array($call->status, [BusinessVoiceCall::STATUS_ENDED, BusinessVoiceCall::STATUS_FAILED], true);

        if ($leg === null) { // the caller's own leg
            if ($type === 'call.hangup' && ! $terminal) {
                $ids = $this->liveLegIds($call);
                if ($call->claimed_leg_id !== null && $call->answered_at !== null) {
                    $this->close($call, $at, $payload);
                } else {
                    $this->finish($call, $at, 'missed', 'caller_hangup', $payload);
                    $call->legs()->whereIn('status', ['ringing'])->update(['status' => 'ended']);
                }

                return fn () => $this->hangupAll($ids);
            }

            return null;
        }

        if ($terminal) {
            // A leg that outlived its call (late answer/ring) must not ring or answer into silence.
            return $type === 'call.hangup' ? null : fn () => $this->commands->send((string) $leg->call_control_id, 'hangup', [], 'stray');
        }

        if ($type === 'call.answered') {
            return $this->legAnswered($call, $leg, $at);
        }

        if ($type === 'call.hangup') {
            if ((int) $call->claimed_leg_id === (int) $leg->id) { // the answered side hung up: the call is over
                $callerLeg = (string) $call->telnyx_call_control_id;
                $others = $this->liveLegIds($call, (int) $leg->id);
                $leg->status = 'ended';
                $leg->save();
                if ($call->answered_at === null) {
                    // Claimed but the browser never connected the audio: a recorded miss, and the caller is released.
                    $this->finish($call, $at, 'missed', 'answer_failed', $payload);
                    $rejectCaller = $callerLeg;

                    return function () use ($rejectCaller, $others): void {
                        $this->hangupAll($others);
                        $this->commands->send($rejectCaller, 'reject', ['cause' => 'USER_BUSY'], 'answerfail');
                    };
                }
                $this->close($call, $at, $payload);

                return fn () => $this->hangupAll(array_merge([$callerLeg], $others));
            }
            if ($leg->status === 'ringing') {
                $leg->status = 'ended';
                $leg->save();

                return $this->decideAdvance($call);
            }
        }

        return null;
    }

    private function legAnswered(BusinessVoiceCall $call, BusinessVoiceCallLeg $leg, Carbon $at): ?\Closure
    {
        $legId = (string) $leg->call_control_id;

        if ($leg->kind === BusinessVoiceCallLeg::KIND_PHONE) {
            if ($call->claimed_leg_id !== null) {
                return fn () => $this->commands->send($legId, 'hangup', [], 'lost');
            }
            $call->claimed_leg_id = $leg->id;
            $call->outcome = 'forwarded';
        } else {
            // A browser leg may only connect after the user's claim (the one-winner gate) named exactly this leg.
            if ((int) $call->claimed_leg_id !== (int) $leg->id) {
                return fn () => $this->commands->send($legId, 'hangup', [], 'unclaimed');
            }
            $call->outcome = 'answered';
        }

        if ($call->bridged_at !== null) {
            return null;
        }
        $leg->status = 'claimed';
        $leg->save();
        $call->status = BusinessVoiceCall::STATUS_ANSWERED;
        $call->answered_at = $at;
        $call->bridged_at = $at;
        $call->phase = 'done';
        $caller = (string) $call->telnyx_call_control_id;
        $others = $this->liveLegIds($call, (int) $leg->id);
        $callId = (int) $call->id;

        return function () use ($caller, $legId, $others, $callId): void {
            $this->hangupAll($others);
            // Answer the caller only now that somebody has; then join the two legs. Either failing drops both.
            if (! $this->commands->send($caller, 'answer', [], 'answer') || ! $this->commands->send($legId, 'bridge', ['call_control_id' => $caller], 'bridge')) {
                $this->commands->send($legId, 'hangup', [], 'bridgefail');
                $this->commands->send($caller, 'hangup', [], 'bridgefail');
                BusinessVoiceCall::query()->whereKey($callId)->whereIn('status', [BusinessVoiceCall::STATUS_ANSWERED])
                    ->update(['status' => BusinessVoiceCall::STATUS_FAILED, 'failure_code' => 'bridge_failed', 'outcome' => 'failed', 'ended_at' => now()]);
            }
        };
    }

    // ------------------------------------------------------------------ user actions (claim / decline / hangup)

    /** One-winner answer. @return array{ok: bool, code: string|null} */
    public function claim(BusinessVoiceCall $call, int $userId): array
    {
        $after = null;
        $result = DB::transaction(function () use ($call, $userId, &$after): array {
            $locked = BusinessVoiceCall::query()->whereKey($call->id)->where('kind', 'inbound')->lockForUpdate()->first();
            if ($locked === null || $locked->status !== BusinessVoiceCall::STATUS_INITIATED) {
                return ['ok' => false, 'code' => 'call_not_ringing'];
            }
            if ($locked->claimed_leg_id !== null) {
                return ['ok' => false, 'code' => 'already_answered'];
            }
            $leg = $locked->legs()->where('kind', BusinessVoiceCallLeg::KIND_BROWSER)->where('user_id', $userId)->where('status', 'ringing')->lockForUpdate()->first();
            if ($leg === null) {
                return ['ok' => false, 'code' => 'call_not_ringing'];
            }
            $locked->claimed_leg_id = $leg->id;
            $locked->answered_by_user_id = $userId;
            $locked->save();
            $leg->status = 'claimed';
            $leg->save();
            $others = $this->liveLegIds($locked, (int) $leg->id);
            $after = fn () => $this->hangupAll($others);

            return ['ok' => true, 'code' => null];
        });

        if ($after !== null) {
            $after();
        }

        return $result;
    }

    public function decline(BusinessVoiceCall $call, int $userId): bool
    {
        $after = null;
        $found = DB::transaction(function () use ($call, $userId, &$after): bool {
            $locked = BusinessVoiceCall::query()->whereKey($call->id)->where('kind', 'inbound')->lockForUpdate()->first();
            if ($locked === null || $locked->status !== BusinessVoiceCall::STATUS_INITIATED || $locked->claimed_leg_id !== null) {
                return false;
            }
            $leg = $locked->legs()->where('kind', BusinessVoiceCallLeg::KIND_BROWSER)->where('user_id', $userId)->where('status', 'ringing')->lockForUpdate()->first();
            if ($leg === null) {
                return false;
            }
            $leg->status = 'declined';
            $leg->save();
            $ccid = (string) $leg->call_control_id;
            $next = $this->decideAdvance($locked);
            $after = function () use ($ccid, $next): void {
                $this->commands->send($ccid, 'hangup', [], 'decline');
                if ($next !== null) {
                    $next();
                }
            };

            return true;
        });

        if ($after !== null) {
            $after();
        }

        return $found;
    }

    /** The user hung up an answered call from the CRM: end every leg and close the record. */
    public function userHangup(BusinessVoiceCall $call): BusinessVoiceCall
    {
        $ids = [];
        DB::transaction(function () use ($call, &$ids): void {
            $locked = BusinessVoiceCall::query()->whereKey($call->id)->lockForUpdate()->first();
            if ($locked === null || in_array($locked->status, [BusinessVoiceCall::STATUS_ENDED, BusinessVoiceCall::STATUS_FAILED], true)) {
                return;
            }
            $ids = array_merge([(string) $locked->telnyx_call_control_id], $this->liveLegIds($locked));
            if ($locked->answered_at !== null) {
                $this->close($locked, now(), ['hangup_cause' => 'user_hangup']);
            } else {
                $this->finish($locked, now(), 'missed', 'user_hangup', []);
            }
            $locked->legs()->whereIn('status', ['ringing', 'claimed'])->update(['status' => 'ended']);
            $locked->save();
        });
        $this->hangupAll($ids);

        return $call->refresh();
    }

    /**
     * Backstop for lost/late webhooks (run every minute): nothing may ring or stay open beyond its bound.
     *
     * @return int number of calls it acted on
     */
    public function expireStale(): int
    {
        $acted = 0;
        $maxSecs = (int) config('messaging.voice.inbound.max_duration_secs');

        foreach (BusinessVoiceCall::query()->where('kind', 'inbound')->where('status', BusinessVoiceCall::STATUS_INITIATED)
            ->where('ring_deadline_at', '<', now()->subSeconds(5))->get() as $call) {
            if ($call->claimed_leg_id !== null) {
                $this->userHangup($call); // claimed but never connected: close as a recorded miss
            } else {
                $this->advance((int) $call->id, true);
            }
            $acted++;
        }

        foreach (BusinessVoiceCall::query()->where('kind', 'inbound')->where('status', BusinessVoiceCall::STATUS_ANSWERED)
            ->where('answered_at', '<', now()->subSeconds($maxSecs + 30))->get() as $call) {
            $this->userHangup($call);
            $acted++;
        }

        $acted += BusinessVoiceCall::query()->where('kind', 'forward_verify')
            ->whereIn('status', [BusinessVoiceCall::STATUS_REQUESTED, BusinessVoiceCall::STATUS_INITIATED])->where('created_at', '<', now()->subMinutes(5))
            ->update(['status' => BusinessVoiceCall::STATUS_FAILED, 'failure_code' => 'expired', 'ended_at' => now()]);

        return $acted;
    }

    // ------------------------------------------------------------------ advance (browser -> phone -> missed)

    /** Run the next phase if nothing is still ringing. Safe to call repeatedly. */
    public function advance(int $callId, bool $force = false): void
    {
        $after = DB::transaction(function () use ($callId, $force): ?\Closure {
            $call = BusinessVoiceCall::query()->whereKey($callId)->where('kind', 'inbound')->lockForUpdate()->first();

            return $call === null ? null : $this->decideAdvance($call, $force);
        });

        if ($after !== null) {
            $after();
        }
    }

    /** Mutates the locked call and returns an optional network step. Never advances an answered/closed call. */
    private function decideAdvance(BusinessVoiceCall $call, bool $force = false): ?\Closure
    {
        if ($call->status !== BusinessVoiceCall::STATUS_INITIATED || $call->claimed_leg_id !== null) {
            return null;
        }

        $ringing = $call->legs()->where('status', 'ringing')->lockForUpdate()->get();
        if ($ringing->isNotEmpty() && ! $force) {
            return null;
        }
        $stray = $ringing->pluck('call_control_id')->filter()->map(fn ($v) => (string) $v)->all();
        $call->legs()->where('status', 'ringing')->update(['status' => 'ended']);

        $setting = BusinessInboundCallSetting::query()->where('business_id', $call->business_id)->first();
        $phoneTried = $call->legs()->where('kind', BusinessVoiceCallLeg::KIND_PHONE)->exists();

        if ($call->phase === 'ringing_browser' && ! $phoneTried && $setting !== null && $setting->usesForwarding()) {
            $destination = $this->forwardDestination($setting, (int) $call->business_id);
            if ($destination !== null) {
                $token = bin2hex(random_bytes(32));
                $leg = BusinessVoiceCallLeg::create([
                    'business_voice_call_id' => $call->id, 'kind' => BusinessVoiceCallLeg::KIND_PHONE,
                    'client_state_hash' => hash('sha256', $token),
                ]);
                $call->phase = 'forwarding';
                $call->ring_deadline_at = now()->addSeconds((int) config('messaging.voice.inbound.forward_ring_secs') + 10);
                $call->save();
                $callId = (int) $call->id;
                $legId = (int) $leg->id;
                $number = BusinessMessagingNumber::query()->find($call->business_messaging_number_id);
                $from = (string) $number?->phone_number;

                return function () use ($stray, $callId, $legId, $destination, $from, $token): void {
                    $this->hangupAll($stray);
                    $fresh = BusinessVoiceCall::query()->find($callId);
                    if ($fresh !== null) {
                        $this->dialLeg($fresh, $legId, $destination, $from, $token, (int) config('messaging.voice.inbound.forward_ring_secs'), (int) config('messaging.voice.inbound.max_duration_secs'));
                        $this->advance($callId); // a dial that failed outright must not leave the caller ringing
                    }
                };
            }
        }

        // Nothing left to try: a reliable recorded outcome, and the caller is released (never left ringing).
        $declined = ! $phoneTried && $call->legs()->where('status', 'declined')->exists();
        $this->finish($call, now(), $declined ? 'declined' : 'missed', $declined ? 'declined' : 'no_answer', []);
        $caller = (string) $call->telnyx_call_control_id;

        return function () use ($stray, $caller, $declined): void {
            $this->hangupAll($stray);
            $this->commands->send($caller, 'reject', ['cause' => $declined ? 'CALL_REJECTED' : 'USER_BUSY'], 'final');
        };
    }

    /** The verified, policy-valid, cap-respecting forwarding number — or null (browser-only / unverified / capped). */
    private function forwardDestination(BusinessInboundCallSetting $setting, int $businessId): ?string
    {
        if (! $setting->forwardVerified()) {
            return null;
        }
        [$e164] = $this->policy->check((string) $setting->forward_number);
        if ($e164 === null) {
            return null;
        }

        $cfg = (array) config('messaging.voice.inbound');
        $today = BusinessVoiceCall::query()->where('business_id', $businessId)->where('kind', 'inbound')->where('created_at', '>', now()->startOfDay());
        $attempts = BusinessVoiceCallLeg::query()->where('kind', BusinessVoiceCallLeg::KIND_PHONE)->where('created_at', '>', now()->startOfDay())
            ->whereIn('business_voice_call_id', BusinessVoiceCall::query()->where('business_id', $businessId)->select('id'))->count();
        $secs = (int) (clone $today)->where('outcome', 'forwarded')->sum('duration_seconds');
        if ($attempts >= (int) $cfg['max_forward_attempts_per_day'] || $secs >= (int) $cfg['max_forward_secs_per_day']) {
            return null;
        }

        return $e164;
    }

    // ------------------------------------------------------------------ leg dialling + helpers

    private function dialLeg(BusinessVoiceCall $call, int $legId, ?string $to, string $from, string $token, int $ringSecs, int $limitSecs): void
    {
        $fail = function () use ($legId): void {
            BusinessVoiceCallLeg::query()->whereKey($legId)->where('status', 'ringing')->update(['status' => 'failed']);
        };
        if ($to === null || $to === '') {
            $fail();

            return;
        }

        try {
            $response = Http::withToken(trim((string) config('services.telnyx.api_key')))->timeout(20)->acceptJson()->asJson()->post(self::API_BASE . '/calls', [
                'connection_id' => trim((string) config('messaging.voice.connection_id')),
                'to' => $to, 'from' => $from,
                'webhook_url' => (string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice')),
                'client_state' => base64_encode($token),
                'timeout_secs' => max(5, $ringSecs),
                'time_limit_secs' => max(30, min($limitSecs, 3700)),
            ]);
        } catch (\Throwable) {
            $fail();

            return;
        }

        $id = (string) $response->json('data.call_control_id', '');
        if (! $response->successful() || $id === '') {
            Log::warning('Telnyx inbound leg dial rejected', ['voice_call_id' => $call->id, 'http_status' => $response->status(), 'provider_code' => $response->json('errors.0.code')]);
            $fail();

            return;
        }
        BusinessVoiceCallLeg::query()->whereKey($legId)->whereNull('call_control_id')->update(['call_control_id' => $id]);
    }

    /** @return list<string> */
    private function liveLegIds(BusinessVoiceCall $call, ?int $exceptLegId = null): array
    {
        return $call->legs()->whereIn('status', ['ringing', 'claimed'])->whereNotNull('call_control_id')
            ->when($exceptLegId !== null, fn ($q) => $q->where('id', '!=', $exceptLegId))->pluck('call_control_id')->map(fn ($v) => (string) $v)->all();
    }

    /** @param list<string> $ids */
    private function hangupAll(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            $this->commands->send($id, 'hangup', [], 'cleanup');
        }
    }

    private function close(BusinessVoiceCall $call, Carbon $at, array $payload): void
    {
        $call->status = BusinessVoiceCall::STATUS_ENDED;
        $call->phase = 'done';
        $call->ended_at = $at;
        $call->hangup_cause = $this->cause($payload);
        $call->duration_seconds = $call->answered_at !== null ? (int) max(0, $call->answered_at->diffInSeconds($at, false)) : null;
    }

    private function finish(BusinessVoiceCall $call, Carbon $at, string $outcome, ?string $code, array $payload): void
    {
        $call->status = BusinessVoiceCall::STATUS_ENDED;
        $call->phase = 'done';
        $call->ended_at = $at;
        $call->outcome = $outcome;
        $call->failure_code = $code;
        $call->hangup_cause = $this->cause($payload);
        $call->duration_seconds = null;
        $call->save();
    }

    private function locate(string $callControlId, array $payload): ?array
    {
        $call = BusinessVoiceCall::query()->whereIn('kind', ['inbound', 'forward_verify'])->where('telnyx_call_control_id', $callControlId)->lockForUpdate()->first();
        if ($call !== null) {
            return [$call, null];
        }

        $state = $payload['client_state'] ?? null;
        $token = is_string($state) ? base64_decode($state, true) : false;
        $hash = $token !== false && preg_match('/^[a-f0-9]{64}$/', (string) $token) ? hash('sha256', (string) $token) : null;

        $leg = BusinessVoiceCallLeg::query()->where('call_control_id', $callControlId)->first();
        if ($leg === null && $hash !== null) {
            $leg = BusinessVoiceCallLeg::query()->where('client_state_hash', $hash)->whereNull('call_control_id')->first();
        }
        if ($leg !== null) {
            $call = BusinessVoiceCall::query()->whereKey($leg->business_voice_call_id)->lockForUpdate()->first();
            if ($call === null) {
                return null;
            }
            $leg->refresh();
            if ($leg->call_control_id === null) {
                $leg->call_control_id = $callControlId; // an event outran our own save of the dial response
                $leg->save();
            }

            return [$call, $leg];
        }

        if ($hash !== null) { // a verification call whose event outran the saved id
            $call = BusinessVoiceCall::query()->where('kind', 'forward_verify')->where('client_state_hash', $hash)->whereNull('telnyx_call_control_id')->lockForUpdate()->first();
            if ($call !== null) {
                $call->telnyx_call_control_id = $callControlId;

                return [$call, null];
            }
        }

        return null;
    }

    private function cause(array $payload): ?string
    {
        $cause = $payload['hangup_cause'] ?? null;

        return is_string($cause) ? substr($cause, 0, 64) : null;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
