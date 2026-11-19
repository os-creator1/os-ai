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
use Illuminate\Support\Facades\Gate;

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
        $this->requireCalling($business);

        $data = $request->validate([
            'contact_uid' => ['required_without:conversation_uid', 'nullable', 'string', 'max:64'],
            // An open Conversation (its own uid). The number is ALWAYS resolved here from that conversation, never taken from the client.
            'conversation_uid' => ['required_without:contact_uid', 'nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-:.]+$/'],
            'channel' => ['sometimes', 'in:server,browser'],
            // Optional calling-number choice: an opaque token from GET calls/numbers; validated against THIS Business only.
            'from' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $contact = null;
        $number = null;
        if (! empty($data['contact_uid'])) {
            $contact = Contacts::query()->where('uid', $data['contact_uid'])->where('business_id', $business->id)->first();
            // A Contact at a Location this member cannot reach is not found: same rule as the Contacts screens.
            if ($contact === null || ! $this->locationAccessible($contact)) {
                return $this->error('contact_not_found', 'Contact not found.', 404);
            }
        } else {
            // Same authority as opening the conversation: chat_box permission, this Business, and the conversation's Location.
            $box = Gate::allows('chat_box')
                ? \App\Models\ChatBox::query()->where('uid', $data['conversation_uid'])->where('business_id', $business->id)->first()
                : null;
            if ($box !== null && $box->location_id !== null) {
                $location = $box->location;
                if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
                    $box = null;
                }
            }
            if ($box === null) {
                return $this->error('conversation_not_found', 'Conversation not found.', 404);
            }
            // A number the Business already has as a Contact is called as that Contact (with its Location rule); otherwise the
            // conversation's own counterparty number is called. The Business's sending number is never the destination.
            $contact = $box->resolveDisplayContact($business);
            if ($contact !== null && ! $this->locationAccessible($contact)) {
                return $this->error('conversation_not_found', 'Conversation not found.', 404);
            }
            $number = '+' . \App\Library\Business\Migration\ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $box->to);
        }

        try {
            [$call, $replay] = $placer->place($business, $contact, (int) Auth::id(), $data['idempotency_key'], $data['channel'] ?? 'server', $number, $data['from'] ?? null);
        } catch (CustomerCallRefusedException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->httpStatus);
        }

        $status = $call->status === BusinessVoiceCall::STATUS_FAILED ? 502 : ($replay ? 200 : 202);

        return response()->json(['data' => $this->present($call, $contact?->uid), 'duplicate' => $replay], $status);
    }

    /**
     * The calling numbers this user may choose from for THIS Business (opaque tokens, display numbers, which is primary).
     * Same tenancy, entitlement and calling permission as placing a call. Never lists another Business's number.
     */
    public function numbers(string $workspaceUid, string $businessUid, CustomerCallPlacer $placer): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->requireCalling($business);

        return response()->json(['data' => array_map(fn (array $choice): array => [
            'id' => $choice['id'],
            'number' => $this->formatNumber($choice['phone']),
            'primary' => $choice['primary'],
        ], $placer->callingChoices($business))])->header('Cache-Control', 'no-store');
    }

    /**
     * Start a browser calling session: provisions (or reuses) this user's telephony credential for this Business and
     * returns ONLY a short-lived login token for the Telnyx WebRTC SDK. Never the API key, never a SIP password.
     */
    public function browserSession(string $workspaceUid, string $businessUid, BrowserCredentialProvisioner $provisioner): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->requireCalling($business);

        try {
            // Browser calling also requires the customer-call switch, exactly like a server-side call.
            if (! (bool) config('messaging.voice.customer_calls.enabled')) {
                throw new BrowserCallsUnavailableException('browser_calling_unavailable', 'Browser calling is not available right now.');
            }
            // The agency's own Telnyx account when it selected one (an unusable one is an actionable refusal, never a fallback).
            $session = $provisioner->loginSession($business, (int) Auth::id(), \App\Library\Voice\Byo\AgencyByoVoice::resolveFor($business));
        } catch (CustomerCallRefusedException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->httpStatus);
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
        $this->requireCalling($business);

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

    public const DISPOSITIONS = ['interested', 'booked', 'follow_up', 'not_a_fit', 'voicemail'];

    /**
     * Post-call wrap-up from the call popup: the caller's note and "How did it go?". Only the user who placed
     * or answered the call may write it, only once the call is over, and a note on a call with a Contact is also
     * appended (dated) to that Contact's built-in Notes field, so it really is "saved to this contact".
     */
    public function wrapup(Request $request, string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->requireCalling($business);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'disposition' => ['nullable', 'string', 'in:' . implode(',', self::DISPOSITIONS)],
        ]);

        $call = $this->callFor($business, $callUid);
        $mine = $call !== null && ((int) $call->initiated_by_user_id === (int) Auth::id() || (int) $call->answered_by_user_id === (int) Auth::id());
        $contact = $call?->contact_id !== null ? Contacts::query()->where('business_id', $business->id)->find($call->contact_id) : null;

        if ($call === null || ! $mine || ($call->kind !== 'inbound' && $contact !== null && ! $this->locationAccessible($contact))) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        if (! in_array($call->status, [BusinessVoiceCall::STATUS_ENDED, BusinessVoiceCall::STATUS_FAILED], true)) {
            return $this->error('call_not_finished', 'Notes are saved when the call ends.', 409);
        }

        $note = trim((string) ($data['note'] ?? ''));

        // Once-only under a row lock: the call row is re-read FOR UPDATE inside the transaction, so two concurrent
        // submits serialize and the second sees wrapup_saved_at and appends nothing.
        $duplicate = \Illuminate\Support\Facades\DB::transaction(function () use ($call, $contact, $note, $data): bool {
            $locked = BusinessVoiceCall::query()->whereKey($call->id)->lockForUpdate()->first();

            if ($locked === null || $locked->wrapup_saved_at !== null) {
                return true;
            }

            $locked->forceFill([
                'wrapup_note' => $note !== '' ? $note : null,
                'wrapup_disposition' => $data['disposition'] ?? null,
                'wrapup_saved_at' => now(),
            ])->save();

            if ($note !== '' && $contact !== null && $contact->contactGroup !== null && Gate::allows('update_contact')) {
                $fieldId = app(\App\Library\Contacts\ContactEmailIdentity::class)->ensureField($contact->contactGroup, 'NOTES');
                $value = \App\Models\ContactsCustomField::query()->where('contact_id', $contact->id)->where('field_id', $fieldId)->lockForUpdate()->first()
                    ?? new \App\Models\ContactsCustomField(['contact_id' => $contact->id, 'field_id' => $fieldId]);
                $entry = '[Call ' . now()->format('M j, Y g:ia') . '] ' . $note;
                $value->contact_id = $contact->id;
                $value->field_id = $fieldId;
                $value->value = trim((string) $value->value) === '' ? $entry : $value->value . "\n" . $entry;
                $value->save();
            }

            return false;
        });

        if ($duplicate) {
            return response()->json(['data' => ['saved' => true, 'duplicate' => true]]);
        }

        return response()->json(['data' => ['saved' => true, 'duplicate' => false, 'on_contact' => $note !== '' && $contact !== null]]);
    }

    public function show(string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->requireCalling($business);

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

    /** Owner-aware (see CallingAuthority): the Workspace owner needs no grant; staff need place_voice_calls. */
    private function requireCalling(\App\Models\Business $business): void
    {
        if (! app(\App\Library\Voice\CallingAuthority::class)->mayCall(Auth::user(), $business)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('This action is unauthorized.');
        }
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
        $this->requireCalling($business);

        if (! $gate->available($business)) {
            return $this->error('inbound_unavailable', 'Inbound calling is not available.', 503);
        }

        // Presence belongs to the credential the browser is ACTUALLY signed into: the one carrying the active-session marker
        // (starting a session sets it here and clears it on the user's other credentials, so there is never a timestamp tie).
        // It counts only while it is still the account this Business routes to (platform-managed, or the agency's selected
        // channel on its current WebRTC connection); anything else asks the browser to start a fresh session.
        $active = BusinessVoiceBrowserCredential::query()->where('business_id', $business->id)->where('user_id', (int) Auth::id())
            ->where('expires_at', '>', now())->orderByRaw('session_started_at IS NULL')->orderByDesc('session_started_at')->orderByDesc('id')->first();
        $byoChannel = \App\Library\Voice\Byo\AgencyByoVoice::selectedChannel($business);
        $matches = $active !== null && (
            $byoChannel === null
                ? (int) $active->agency_prospecting_channel_id === 0
                : ((int) $active->agency_prospecting_channel_id === (int) $byoChannel->id
                    && $active->webrtc_connection_id === trim((string) $byoChannel->voice_webrtc_connection_id))
        );
        $updated = $matches ? BusinessVoiceBrowserCredential::query()->whereKey($active->id)->update(['presence_at' => now()]) : 0;

        return $updated === 0
            ? $this->error('browser_session_required', 'Start a calling session first.', 409)
            : response()->json(['data' => ['available' => true]])->header('Cache-Control', 'no-store');
    }

    /** Inbound calls currently ringing THIS user's browser, with caller identification (called when the SDK reports a ring). */
    public function incoming(string $workspaceUid, string $businessUid): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->requireCalling($business);

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
        $this->requireCalling($business);

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
        $this->requireCalling($business);

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
            // The Business's own calling number this call used (never another Business's: it is the call row's own number).
            'from_number' => $this->fromNumber($call),
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

    private function fromNumber(BusinessVoiceCall $call): ?string
    {
        if ($call->agency_prospecting_channel_id !== null) {
            $number = \App\Models\AgencyProspectingChannel::query()->whereKey($call->agency_prospecting_channel_id)->value('voice_number');

            return $number !== null ? $this->formatNumber('+' . ltrim((string) $number, '+')) : null;
        }

        if ($call->business_messaging_number_id === null) {
            return null;
        }

        $number = \App\Models\BusinessMessagingNumber::query()
            ->whereKey($call->business_messaging_number_id)
            ->value('phone_number');

        return $number !== null ? $this->formatNumber('+' . ltrim((string) $number, '+')) : null;
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
