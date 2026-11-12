<?php

namespace App\Library\Voice\Inbound;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Voice\TelnyxCallDialer;
use App\Models\Business;
use App\Models\BusinessInboundCallSetting;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessVoiceCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proves a Business controls its forwarding number BEFORE it can be dialled: a one-time code is spoken on a short
 * verification call to that number and must be typed back. Bounded: per-Business and per-destination daily caps, a short
 * code lifetime and a small attempt limit. The code is stored encrypted only until used; changing the number discards the
 * verification (forwarding requires the verified hash to equal the current number's hash).
 */
final class ForwardVerifier
{
    public function __construct(
        private readonly ForwardDestinationPolicy $policy,
        private readonly TelnyxCallDialer $dialer,
        private readonly TelnyxCallCommands $commands,
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly InboundCallingGate $gate,
    ) {
    }

    /** @return array{ok: bool, code: string|null} */
    public function start(Business $business, int $userId, string $raw): array
    {
        if (! $this->gate->available($business)) {
            return ['ok' => false, 'code' => 'unavailable'];
        }
        [$e164, $reason] = $this->policy->check($raw);
        if ($e164 === null) {
            return ['ok' => false, 'code' => (string) $reason];
        }

        $connectionId = trim((string) config('messaging.voice.connection_id'));
        try {
            $number = $this->identities->resolvePrimaryNumber($this->identities->resolveForBusiness($business));
        } catch (\Throwable) {
            $number = null;
        }
        if (! $number instanceof BusinessMessagingNumber || ! $number->isActive() || $number->number_type !== PhoneNumberType::Local
            || $number->voice_assigned_at === null || $number->voice_connection_id !== $connectionId) {
            return ['ok' => false, 'code' => 'number_not_voice_ready'];
        }

        $cfg = (array) config('messaging.voice.inbound');
        $hash = BusinessVoiceCall::hashDestination($e164);
        $token = bin2hex(random_bytes(32));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $call = DB::transaction(function () use ($business, $userId, $e164, $hash, $token, $code, $number, $cfg): ?BusinessVoiceCall {
            // Serialise per Business so the caps cannot be raced.
            BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();
            $today = BusinessVoiceCall::query()->where('kind', 'forward_verify')->where('created_at', '>', now()->startOfDay());
            if ((clone $today)->where('business_id', $business->id)->count() >= (int) $cfg['verify_max_per_day_per_business']
                || (clone $today)->where('to_hash', $hash)->count() >= (int) $cfg['verify_max_per_day_per_destination']) {
                return null;
            }

            BusinessInboundCallSetting::query()->updateOrCreate(['business_id' => $business->id], [
                'forward_number' => $e164,
                'forward_number_hash' => BusinessInboundCallSetting::hashNumber((int) $business->id, $e164),
                'forward_verified_hash' => null, 'forward_verified_at' => null,
                'verify_code' => $code, 'verify_code_hash' => $this->codeHash((int) $business->id, $code),
                'verify_expires_at' => now()->addMinutes((int) $cfg['verify_ttl_minutes']), 'verify_attempts' => 0,
                'updated_by_user_id' => $userId,
            ]);

            $verifyCall = BusinessVoiceCall::create([
                'uid' => (string) Str::uuid(), 'kind' => 'forward_verify', 'channel' => 'server', 'direction' => 'outbound',
                'client_state_hash' => hash('sha256', $token), 'business_id' => $business->id,
                'business_messaging_number_id' => $number->id, 'initiated_by_user_id' => $userId,
                'to_hash' => $hash, 'status' => BusinessVoiceCall::STATUS_REQUESTED,
            ]);

            // Bind the challenge to THIS call: any earlier verification call is superseded and can never speak it.
            BusinessInboundCallSetting::query()->where('business_id', $business->id)->update(['verify_call_id' => $verifyCall->id]);

            return $verifyCall;
        });

        if ($call === null) {
            return ['ok' => false, 'code' => 'rate_limited'];
        }

        $call = $this->dialer->dial(
            $call, trim((string) config('services.telnyx.api_key')), $connectionId, (string) $number->phone_number, $e164,
            (string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice')), $token,
            ringTimeoutSecs: 30, timeLimitSecs: 120,
        );

        return $call->status === BusinessVoiceCall::STATUS_FAILED ? ['ok' => false, 'code' => 'dial_failed'] : ['ok' => true, 'code' => null];
    }

    /** @return array{ok: bool, code: string|null} */
    public function confirm(Business $business, string $entered): array
    {
        return DB::transaction(function () use ($business, $entered): array {
            $setting = BusinessInboundCallSetting::query()->where('business_id', $business->id)->lockForUpdate()->first();
            if ($setting === null || $setting->verify_code_hash === null || $setting->verify_expires_at === null || $setting->verify_expires_at->isPast()) {
                return ['ok' => false, 'code' => 'code_expired'];
            }
            if ($setting->verify_attempts >= (int) config('messaging.voice.inbound.verify_max_attempts')) {
                return ['ok' => false, 'code' => 'too_many_attempts'];
            }
            $setting->verify_attempts++;
            if (! hash_equals($setting->verify_code_hash, $this->codeHash((int) $business->id, trim($entered)))) {
                $setting->save();

                return ['ok' => false, 'code' => 'wrong_code'];
            }
            $setting->forward_verified_hash = $setting->forward_number_hash;
            $setting->forward_verified_at = now();
            $setting->verify_code = null;
            $setting->verify_call_id = null;
            $setting->verify_code_hash = null;
            $setting->verify_expires_at = null;
            $setting->verify_attempts = 0;
            $setting->save();

            return ['ok' => true, 'code' => null];
        });
    }

    /** Webhook step for a verification call's own events. @return \Closure|null */
    public function transition(BusinessVoiceCall $call, string $type, array $payload, Carbon $at): ?\Closure
    {
        $leg = (string) $call->telnyx_call_control_id;
        $terminal = in_array($call->status, [BusinessVoiceCall::STATUS_ENDED, BusinessVoiceCall::STATUS_FAILED], true);

        if ($type === 'call.hangup') {
            if (! $terminal) {
                $call->status = $call->answered_at !== null ? BusinessVoiceCall::STATUS_ENDED : BusinessVoiceCall::STATUS_FAILED;
                $call->failure_code = $call->answered_at !== null ? null : 'not_answered';
                $call->ended_at = $at;
                $call->duration_seconds = $call->answered_at !== null ? (int) max(0, $call->answered_at->diffInSeconds($at, false)) : null;
            }

            return null;
        }
        if ($terminal || $leg === '') {
            return null;
        }
        if ($type === 'call.initiated' && $call->status === BusinessVoiceCall::STATUS_REQUESTED) {
            $call->status = BusinessVoiceCall::STATUS_INITIATED;
            $call->initiated_at ??= $at;

            return null;
        }
        if ($type === 'call.answered' && $call->answered_at === null) {
            $call->status = BusinessVoiceCall::STATUS_ANSWERED;
            $call->answered_at = $at;
            $setting = BusinessInboundCallSetting::query()->where('business_id', $call->business_id)->first();
            $code = $setting !== null && (int) $setting->verify_call_id === (int) $call->id && $setting->verify_expires_at?->isFuture() ? (string) $setting->verify_code : '';
            if (! preg_match('/^\d{6}$/', $code)) {
                return fn () => $this->commands->send($leg, 'hangup', [], 'nocode');
            }
            $spaced = implode(', ', str_split($code));

            return fn () => $this->commands->send($leg, 'speak', [
                'payload' => 'Your MotionGrove verification code is ' . $spaced . '. Again, ' . $spaced . '.',
                'voice' => 'female', 'language' => 'en-US',
            ], 'speak');
        }
        if ($type === 'call.speak.ended') {
            return fn () => $this->commands->send($leg, 'hangup', [], 'done');
        }

        return null;
    }

    private function codeHash(int $businessId, string $code): string
    {
        return hash_hmac('sha256', $businessId . '|code|' . $code, (string) config('app.key'));
    }
}
