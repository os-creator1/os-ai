<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Voice\BrowserCallOrchestrator;
use App\Library\Voice\BrowserCallsUnavailableException;
use App\Library\Voice\BrowserCredentialProvisioner;
use App\Library\Voice\CustomerCallPlacer;
use App\Library\Voice\CustomerCallRefusedException;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\BusinessVoiceCall;
use App\Models\Contacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * JSON backend for a future CRM calling interface: place an outbound call to one of this Business's contacts, and read
 * a call's status. Tenancy -> entitlement (CRM) -> `place_voice_calls` permission run first, exactly like the other
 * Business-scoped controllers; a foreign Workspace/Business or an un-entitled plan is a 404.
 *
 * Responses never carry Telnyx credentials, ids, or any phone number: a call is identified by its opaque `uid` and its
 * contact by the contact's own uid. A `server` call only rings the contact (no audio, `audio.browser` = false); a
 * `browser` call is bridged to the agent's WebRTC softphone, which is where the audio is.
 */
class VoiceCallController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function store(Request $request, string $workspaceUid, string $businessUid, CustomerCallPlacer $placer): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $data = $request->validate([
            'contact_uid' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'channel' => ['sometimes', 'in:server,browser'],
        ]);

        $contact = Contacts::query()->where('uid', $data['contact_uid'])->where('business_id', $business->id)->first();
        // A Contact at a Location this member cannot reach is not found: same rule as the Contacts screens.
        if ($contact === null || ! $this->locationAccessible($contact)) {
            return $this->error('contact_not_found', 'Contact not found.', 404);
        }

        try {
            [$call, $replay] = $placer->place($business, $contact, (int) Auth::id(), $data['idempotency_key'], $data['channel'] ?? 'server');
        } catch (CustomerCallRefusedException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->httpStatus);
        }

        $status = $call->status === BusinessVoiceCall::STATUS_FAILED ? 502 : ($replay ? 200 : 202);

        return response()->json(['data' => $this->present($call, $contact->uid), 'duplicate' => $replay], $status);
    }

    /**
     * Start a browser calling session: provisions (or reuses) this user's telephony credential for this Business and
     * returns ONLY a short-lived login token for the Telnyx WebRTC SDK. Never the API key, never a SIP password.
     */
    public function browserSession(string $workspaceUid, string $businessUid, BrowserCredentialProvisioner $provisioner): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        try {
            // Browser calling also requires the customer-call switch, exactly like a server-side call.
            if (! (bool) config('messaging.voice.customer_calls.enabled')) {
                throw new BrowserCallsUnavailableException('browser_calling_unavailable', 'Browser calling is not available right now.');
            }
            $session = $provisioner->loginSession($business, (int) Auth::id());
        } catch (BrowserCallsUnavailableException $e) {
            return $this->error($e->reason, $e->getMessage(), 503);
        } catch (\Throwable) {
            return $this->error('browser_calling_unavailable', 'Browser calling is not available right now.', 503);
        }

        return response()->json(['data' => $session])->header('Cache-Control', 'no-store');
    }

    /** End a call from the CRM: hangs up both legs and closes the record. */
    public function hangup(string $workspaceUid, string $businessUid, string $callUid, BrowserCallOrchestrator $orchestrator): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $call = BusinessVoiceCall::query()->where('business_id', $business->id)->where('kind', 'customer')->where('uid', $callUid)->first();
        $contact = $call?->contact_id !== null ? Contacts::query()->find($call->contact_id) : null;
        if ($call === null || ($contact !== null && ! $this->locationAccessible($contact))) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        $call = $orchestrator->userHangup($call);

        return response()->json(['data' => $this->present($call, $contact?->uid)]);
    }

    public function show(string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $call = BusinessVoiceCall::query()->where('business_id', $business->id)->where('kind', 'customer')->where('uid', $callUid)->first();
        if ($call === null) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        $contact = $call->contact_id !== null ? Contacts::query()->find($call->contact_id) : null;
        if ($contact !== null && ! $this->locationAccessible($contact)) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        return response()->json(['data' => $this->present($call, $contact?->uid)]);
    }

    /** Mirrors ContactsController::locationAccessible(): Location-less Contacts are open; otherwise the actor needs the grant. */
    private function locationAccessible(Contacts $contact): bool
    {
        if ($contact->location_id === null) {
            return true;
        }

        $location = $contact->location;

        return $location !== null && app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location);
    }

    /** @return array<string, mixed> */
    private function present(BusinessVoiceCall $call, ?string $contactUid): array
    {
        return [
            'id' => $call->uid,
            'contact_uid' => $contactUid,
            'status' => $call->status, // requested | initiated | answered | ended | failed
            'channel' => $call->channel,
            // What a calling UI shows: connecting (browser/call being set up), ringing, connected, ended, failed.
            'ui_state' => ['requested' => 'connecting', 'initiated' => 'ringing', 'answered' => 'connected', 'ended' => 'ended', 'failed' => 'failed'][$call->status] ?? 'connecting',
            'failure_code' => $call->failure_code,
            'hangup_cause' => $call->hangup_cause,
            'initiated_at' => $call->initiated_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->duration_seconds,
            'max_duration_seconds' => (int) config('messaging.voice.customer_calls.max_duration_secs'),
            'audio' => $call->channel === 'browser'
                ? ['browser' => true, 'note' => 'Audio is carried by the browser softphone.']
                : ['browser' => false, 'note' => 'Placing a call does not connect browser audio.'],
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
