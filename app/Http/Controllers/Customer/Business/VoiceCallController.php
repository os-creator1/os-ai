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
use App\Library\Voice\Inbound\InboundCallRouter;
use App\Library\Voice\Inbound\InboundCallingGate;
use App\Models\BusinessVoiceBrowserCredential;
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
    public function hangup(string $workspaceUid, string $businessUid, string $callUid, BrowserCallOrchestrator $orchestrator, InboundCallRouter $inbound): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $call = $this->callFor($business, $callUid);
        $contact = $call?->contact_id !== null ? Contacts::query()->find($call->contact_id) : null;
        // Outbound keeps its Location rule. An inbound participant keeps their controls (contact details are redacted in
        // present()), but ONLY the user who answered may end an answered call: another employee whose browser also rang may not.
        $isInbound = $call?->kind === 'inbound';
        if ($call === null || ($isInbound && (int) $call->answered_by_user_id !== (int) Auth::id())
            || (! $isInbound && $contact !== null && ! $this->locationAccessible($contact))) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        $call = $call->kind === 'inbound' ? $inbound->userHangup($call) : $orchestrator->userHangup($call);

        return response()->json(['data' => $this->present($call, $contact?->uid)]);
    }

    public function show(string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $call = $this->callFor($business, $callUid);
        if ($call === null) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        $contact = $call->contact_id !== null ? Contacts::query()->find($call->contact_id) : null;
        if ($call->kind !== 'inbound' && $contact !== null && ! $this->locationAccessible($contact)) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        return response()->json(['data' => $this->present($call, $contact?->uid)]);
    }

    /** A customer call of this Business, or an inbound call this user is a party to (rang their browser / they answered). */
    private function callFor(\App\Models\Business $business, string $callUid): ?BusinessVoiceCall
    {
        $call = BusinessVoiceCall::query()->where('business_id', $business->id)->whereIn('kind', ['customer', 'inbound'])->where('uid', $callUid)->first();
        if ($call === null || $call->kind !== 'inbound') {
            return $call;
        }

        $userId = (int) Auth::id();

        return ((int) $call->answered_by_user_id === $userId || $call->legs()->where('user_id', $userId)->exists()) ? $call : null;
    }

    /** Browser availability heartbeat: this user's open page is signed in and can take an inbound call. */
    public function presence(string $workspaceUid, string $businessUid, InboundCallingGate $gate): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        if (! $gate->available($business)) {
            return $this->error('inbound_unavailable', 'Inbound calling is not available.', 503);
        }

        $updated = BusinessVoiceBrowserCredential::query()->where('business_id', $business->id)->where('user_id', (int) Auth::id())
            ->where('expires_at', '>', now())->update(['presence_at' => now()]);

        return $updated === 0
            ? $this->error('browser_session_required', 'Start a calling session first.', 409)
            : response()->json(['data' => ['available' => true]])->header('Cache-Control', 'no-store');
    }

    /** Inbound calls currently ringing THIS user's browser, with caller identification (called when the SDK reports a ring). */
    public function incoming(string $workspaceUid, string $businessUid): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $userId = (int) Auth::id();
        $calls = BusinessVoiceCall::query()->where('business_id', $business->id)->where('kind', 'inbound')
            ->where('status', BusinessVoiceCall::STATUS_INITIATED)->whereNull('claimed_leg_id')
            ->whereHas('legs', fn ($q) => $q->where('user_id', $userId)->where('status', 'ringing'))
            ->orderBy('id')->limit(3)->get();

        return response()->json(['data' => $calls->map(fn (BusinessVoiceCall $c) => $this->present($c, null))->all()])->header('Cache-Control', 'no-store');
    }

    /** Answer an inbound call: the ONE-winner claim. Only after this succeeds does the browser answer its leg. */
    public function answer(string $workspaceUid, string $businessUid, string $callUid, InboundCallRouter $router): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $call = $this->callFor($business, $callUid);
        if ($call === null || $call->kind !== 'inbound') {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        $result = $router->claim($call, (int) Auth::id());
        if (! $result['ok']) {
            return $this->error((string) $result['code'], $result['code'] === 'already_answered' ? 'Someone else already answered this call.' : 'This call is no longer ringing.', 409);
        }

        return response()->json(['data' => $this->present($call->refresh(), null)]);
    }

    public function decline(string $workspaceUid, string $businessUid, string $callUid, InboundCallRouter $router): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $call = $this->callFor($business, $callUid);
        if ($call === null || $call->kind !== 'inbound') {
            return $this->error('call_not_found', 'Call not found.', 404);
        }
        $router->decline($call, (int) Auth::id());

        return response()->json(['data' => $this->present($call->refresh(), null)]);
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
        $inbound = $call->kind === 'inbound';
        $caller = null;
        if ($inbound) {
            $contact = $call->contact_id !== null ? Contacts::query()->find($call->contact_id) : null;
            // A contact at a Location this user cannot reach is shown as an unknown number: never leak the name.
            $visible = $contact !== null && $this->locationAccessible($contact);
            $contactUid = $visible ? (string) $contact->uid : null;
            $caller = [
                'name' => $visible ? $contact->getFullName(null) : null,
                'number' => $call->caller_number !== null ? $this->formatNumber((string) $call->caller_number) : null,
            ];
        }

        return array_merge([
            'id' => $call->uid,
            'contact_uid' => $contactUid,
            'status' => $call->status, // requested | initiated | answered | ended | failed
            'channel' => $call->channel,
            // What a calling UI shows: connecting (browser/call being set up), ringing, connected, ended, failed.
            'ui_state' => ['requested' => 'connecting', 'initiated' => 'ringing', 'answered' => 'connected', 'ended' => 'ended', 'failed' => 'failed'][$call->status] ?? 'connecting',
            'direction' => $inbound ? 'inbound' : 'outbound',
            'outcome' => $call->outcome,
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
        ], $inbound ? ['audio' => ['browser' => true, 'note' => 'Audio is carried by the browser softphone.'], 'caller' => $caller] : []);
    }

    private function formatNumber(string $e164): string
    {
        return preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m) === 1 ? sprintf('+1 (%s) %s-%s', $m[1], $m[2], $m[3]) : $e164;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
