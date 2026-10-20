<?php

namespace App\Library\Voice;

use App\Enums\Voice\VoiceCallStatus;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

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

        $call->forceFill([
            'provider_call_control_id' => $leg['call_control_id'],
            'provider_call_session_id' => $leg['call_session_id'],
            'provider_leg_id' => $leg['call_leg_id'],
        ])->save();

        return $call;
    }

    /** The browser leg was answered: bridge to the validated destination, once, after re-authorizing. */
    public function bridge(VoiceCall $call): void
    {
        $claimed = VoiceCall::query()
            ->whereKey($call->id)
            ->whereNull('bridge_requested_at')
            // A call the user canceled, that the browser reported ended, or that is already finished can never bridge.
            ->whereNull('client_reported_ended_at')
            ->whereNotIn('status', array_map(fn (VoiceCallStatus $s) => $s->value, array_filter(VoiceCallStatus::cases(), fn ($s) => $s->isFinal() || $s === VoiceCallStatus::Unconfirmed)))
            ->update(['bridge_requested_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $call = $call->fresh();
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
            $this->fail($call, $deny ?? 'voice_unavailable');

            return;
        }

        if ($deny !== null) {
            $this->endLeg($call, $creds['api_key']);
            $this->fail($call, $deny);

            return;
        }

        try {
            // The destination and caller ID are the ones recorded when the request was validated.
            $this->provider->transferToSip($creds['api_key'], (string) $call->provider_call_control_id, $call->to_number, $call->from_number);
        } catch (VoiceProviderException $e) {
            Log::info('voice.bridge_failed', ['call' => $call->uid, 'reason' => $e->reason]);
            $this->endLeg($call, $creds['api_key']);
            $this->fail($call, 'bridge_failed');
        }
    }

    /** The user pressed Cancel (or End) before the browser leg was up: hang up our leg. */
    public function cancel(VoiceCall $call, VoiceNumber $number, Business $business): void
    {
        if ($call->statusEnum()->isFinal()) {
            return;
        }

        // Close the record FIRST: from this moment no later "leg answered" event can bridge this call.
        VoiceCall::query()->whereKey($call->id)->whereNull('answered_at')->whereNotIn('status', array_map(fn (VoiceCallStatus $s) => $s->value, array_filter(VoiceCallStatus::cases(), fn ($s) => $s->isFinal())))->update([
            'status' => VoiceCallStatus::Canceled->value,
            'status_rank' => VoiceCallStatus::Canceled->rank(),
            'hangup_cause' => 'user_canceled',
            'ended_at' => now(),
            'duration_seconds' => 0,
        ]);

        if ($call->provider_call_control_id === null) {
            return;
        }

        try {
            $creds = $this->credentials->forNumber($number, $business);
            $this->provider->hangupCall($creds['api_key'], (string) $call->provider_call_control_id);
        } catch (VoiceProviderException|VoiceUnavailableException) {
            // The browser-reported hint and the provider's own hangup event settle the record.
        }
    }

    private function endLeg(VoiceCall $call, string $apiKey): void
    {
        try {
            $this->provider->hangupCall($apiKey, (string) $call->provider_call_control_id);
        } catch (VoiceProviderException) {
            // The record is closed below regardless.
        }
    }

    private function fail(VoiceCall $call, string $cause): void
    {
        $fresh = VoiceCall::query()->whereKey($call->id)->first();

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
    }
}
