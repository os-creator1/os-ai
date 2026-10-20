<?php

namespace App\Library\Voice;

use App\Enums\Voice\VoiceCallStatus;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * V1 inbound routing: ONE explicitly assigned user per number — no queues, no fan-out. The assigned
 * user must still be allowed to use this Business and be online (a recent heartbeat from their leading
 * browser tab). Otherwise the call is turned away and recorded as missed with the real reason — it is
 * never auto-answered and never rung at someone else.
 */
final class VoiceInboundRouter
{
    public function __construct(
        private readonly VoiceProviderClient $provider,
        private readonly VoiceCredentials $credentials,
        private readonly VoiceRegistrationService $registrations,
        private readonly BusinessRouteAccess $access,
    ) {
    }

    public function route(VoiceCall $call, VoiceNumber $number, string $callControlId): void
    {
        // Once per call, however many times the same request's after-response hook is run.
        if (! Cache::add('voice:routed:' . $call->uid, true, 600)) {
            return;
        }

        $business = Business::query()->find((int) $number->business_id);
        $user = $number->inbound_user_id !== null ? User::query()->find((int) $number->inbound_user_id) : null;

        try {
            $creds = $business !== null ? $this->credentials->forNumber($number, $business) : null;
        } catch (VoiceUnavailableException) {
            $creds = null;
        }

        if ($creds === null) {
            $this->miss($call, 'voice_unavailable');

            return;
        }

        $eligible = $user !== null && $business !== null
            && $this->access->actorMayUseBusiness($user, $business)
            && Gate::forUser($user)->allows('chat_box');

        if (! $eligible) {
            $this->turnAway($call, $creds['api_key'], $callControlId, 'no_assigned_user');

            return;
        }

        $registration = $this->registrations->onlineRegistration((int) $user->id, $number);

        if ($registration === null) {
            $this->turnAway($call, $creds['api_key'], $callControlId, 'assigned_user_offline');

            return;
        }

        try {
            $this->provider->transferToSip(
                $creds['api_key'],
                $callControlId,
                'sip:' . $registration->sip_username . '@sip.telnyx.com',
                $call->from_number,
            );
            $call->forceFill(['user_id' => (int) $user->id])->save();
        } catch (VoiceProviderException $e) {
            Log::warning('voice.inbound_route_failed', ['call' => $call->uid, 'reason' => $e->reason]);
            $this->turnAway($call, $creds['api_key'], $callControlId, 'routing_failed');
        }
    }

    private function turnAway(VoiceCall $call, string $apiKey, string $callControlId, string $cause): void
    {
        $this->miss($call, $cause);

        try {
            $this->provider->rejectCall($apiKey, $callControlId, 'CALL_REJECTED');
        } catch (VoiceProviderException) {
            // The caller leg will end on its own; the record is already honest.
        }
    }

    private function miss(VoiceCall $call, string $cause): void
    {
        $fresh = VoiceCall::query()->whereKey($call->id)->first();

        if ($fresh === null || $fresh->statusEnum()->isFinal()) {
            return;
        }

        $fresh->forceFill([
            'status' => VoiceCallStatus::Missed->value,
            'status_rank' => VoiceCallStatus::Missed->rank(),
            'hangup_cause' => $cause,
            'ended_at' => now(),
            'duration_seconds' => 0,
        ])->save();
    }
}
