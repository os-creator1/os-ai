<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Voice\CustomerCallPlacer;
use App\Library\Voice\CustomerCallRefusedException;
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
 * contact by the contact's own uid. Placing a call rings the contact via Telnyx Call Control; it provides NO browser
 * audio, so the response says so (`audio.browser` = false) rather than implying a working softphone.
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
        ]);

        $contact = Contacts::query()->where('uid', $data['contact_uid'])->where('business_id', $business->id)->first();
        if ($contact === null) {
            return $this->error('contact_not_found', 'Contact not found.', 404);
        }

        try {
            [$call, $replay] = $placer->place($business, $contact, (int) Auth::id(), $data['idempotency_key']);
        } catch (CustomerCallRefusedException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->httpStatus);
        }

        $status = $call->status === BusinessVoiceCall::STATUS_FAILED ? 502 : ($replay ? 200 : 202);

        return response()->json(['data' => $this->present($call, $contact->uid), 'duplicate' => $replay], $status);
    }

    public function show(string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);
        $this->authorize('place_voice_calls');

        $call = BusinessVoiceCall::query()->where('business_id', $business->id)->where('kind', 'customer')->where('uid', $callUid)->first();
        if ($call === null) {
            return $this->error('call_not_found', 'Call not found.', 404);
        }

        $contactUid = $call->contact_id !== null ? Contacts::query()->whereKey($call->contact_id)->value('uid') : null;

        return response()->json(['data' => $this->present($call, $contactUid)]);
    }

    /** @return array<string, mixed> */
    private function present(BusinessVoiceCall $call, ?string $contactUid): array
    {
        return [
            'id' => $call->uid,
            'contact_uid' => $contactUid,
            'status' => $call->status, // requested | initiated | answered | ended | failed
            'failure_code' => $call->failure_code,
            'hangup_cause' => $call->hangup_cause,
            'initiated_at' => $call->initiated_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'duration_seconds' => $call->duration_seconds,
            'max_duration_seconds' => (int) config('messaging.voice.customer_calls.max_duration_secs'),
            'audio' => ['browser' => false, 'note' => 'Placing a call does not connect browser audio.'],
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
