<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Library\Messaging\E164Normalizer;
use App\Library\Voice\VoiceBusinessContext;
use App\Library\Voice\VoiceCallService;
use App\Library\Voice\VoiceDestinationPolicy;
use App\Library\Voice\VoiceOutboundDialer;
use App\Library\Voice\VoiceNumberResolver;
use App\Library\Voice\VoiceProviderException;
use App\Library\Voice\VoiceRegistrationService;
use App\Library\Voice\VoiceSetupService;
use App\Library\Voice\VoiceUnavailableException;
use App\Library\Workspace\WorkspaceManagementAuthority;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Browser calling, Business-scoped. Every action re-runs the tenancy chain (VoiceBusinessContext) and then
 * re-derives everything else — destination, caller ID, credentials — from persistence. Errors are returned
 * as JSON bodies with a stable `code` and an actionable message, never thrown (the application's exception
 * handler turns any exception on a JSON request into HTTP 200).
 */
class VoiceController extends Controller
{
    public function __construct(
        private readonly VoiceBusinessContext $context,
        private readonly VoiceNumberResolver $numbers,
        private readonly VoiceRegistrationService $registrations,
        private readonly VoiceCallService $calls,
        private readonly VoiceSetupService $setup,
        private readonly WorkspaceManagementAuthority $authority,
        private readonly VoiceDestinationPolicy $policy,
        private readonly VoiceOutboundDialer $dialer,
    ) {
    }

    /** Which numbers can call this contact/conversation, and the person/number to be called. */
    public function options(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request, $workspaceUid, $businessUid) {
            $target = $this->calls->resolveTarget($user, $business, $request->query('contact_uid'), $request->query('conversation_uid'));
            $found = $this->numbers->callableFor($user, $business, $target['location_id']);

            if (($refusal = $this->policy->refusal($target['number'])) !== null) {
                $found = ['numbers' => [], 'reason' => $refusal[0], 'message' => $refusal[1]];
            }

            return response()->json([
                'status' => 'success',
                'callable' => $found['numbers'] !== [],
                'reason' => $found['reason'],
                'message' => $found['message'],
                'target' => ['name' => $target['name'], 'number' => $target['number']],
                'numbers' => array_map(fn (VoiceNumber $n) => [
                    'uid' => $n->uid,
                    'number' => $n->phone_number,
                    'source' => $n->source,
                    'scope' => $n->scopeKey(),
                ], $found['numbers']),
                'settings_url' => $this->settingsUrl($workspaceUid, $businessUid, $workspace),
            ]);
        });
    }

    /**
     * What this signed-in user should register for inbound: their assigned, ready number (if any).
     * Presence is only ever for the number the user is explicitly assigned to.
     */
    public function session(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) {
            $assigned = VoiceNumber::query()
                ->where('business_id', (int) $business->id)
                ->where('inbound_user_id', (int) $user->id)
                ->orderBy('id')->get()
                ->first(fn (VoiceNumber $n) => $this->numbers->problem($n, $business) === null && $n->inboundReady());

            return response()->json([
                'status' => 'success',
                'inbound_number_uid' => $assigned?->uid,
                'inbound_number' => $assigned?->phone_number,
                'inbound_scope' => $assigned?->scopeKey(),
            ]);
        });
    }

    /** A short-lived WebRTC token for THIS user, minted server-side. */
    public function token(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request) {
            $number = $this->registrationNumber($business, (string) $request->input('number_uid'));
            $issued = $this->registrations->issueToken($user, $business, $number);

            return response()->json(['status' => 'success'] + $issued)->header('Cache-Control', 'no-store');
        });
    }

    public function heartbeat(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request) {
            $number = $this->registrationNumber($business, (string) $request->input('number_uid'));

            return response()->json(['status' => 'success', 'registered' => $this->registrations->heartbeat($user, $business, $number)]);
        });
    }

    /** Logout / workspace switch / tab closed: stop ringing this person's browser. */
    public function offline(string $workspaceUid, string $businessUid): JsonResponse
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $this->registrations->goOffline($user);
        }

        return response()->json(['status' => 'success']);
    }

    public function startCall(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request) {
            $data = $request->validate([
                'number_uid' => ['required', 'string', 'max:64'],
                'contact_uid' => ['nullable', 'string', 'max:64'],
                'conversation_uid' => ['nullable', 'string', 'max:64'],
                'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
            ]);

            $target = $this->calls->resolveTarget($user, $business, $data['contact_uid'] ?? null, $data['conversation_uid'] ?? null);

            if (($refusal = $this->policy->refusal($target['number'])) !== null) {
                throw new VoiceUnavailableException($refusal[0], $refusal[1]);
            }

            $from = $this->numbers->authorize($user, $business, $data['number_uid'], $target['location_id']);
            $started = $this->calls->startOutbound($user, $workspace, $business, $from, $target, $data['idempotency_key']);
            $call = $started['call'];

            // MotionGrove places the call itself (a retried request with the same key never dials twice).
            if ($started['created']) {
                $call = $this->dialer->dial($user, $business, $from, $call);
            }

            return response()->json([
                'status' => 'success',
                'duplicate' => ! $started['created'],
                'call' => [
                    'uid' => $call->uid,
                    'from' => $call->from_number,
                    'to' => $call->to_number,
                    'name' => $target['name'],
                    // The call's own id, handed to the SDK as clientState so Telnyx echoes it in webhooks.
                    'client_state' => base64_encode($call->uid),
                ],
            ]);
        });
    }

    /** The authoritative-so-far state of the user's own call, for the panel (answered time is the server's). */
    public function showCall(string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($callUid) {
            $call = $this->ownCall($user, $business, $callUid);

            return response()->json([
                'status' => 'success',
                'call_status' => $call->status,
                'final' => $call->statusEnum()->isFinal(),
                'answered_elapsed_ms' => $call->answered_at !== null ? max(0, (int) ($call->answered_at->diffInMilliseconds(now(), false))) : null,
                'cause' => $call->hangup_cause,
                'provider_hangup' => $call->providerHangupState(),
                'message' => $this->hangupMessage($call->providerHangupState() ?? 'confirmed'),
            ]);
        });
    }

    /** Hang up the server-placed leg of the user's own call (Cancel before the browser leg is up). */
    public function cancelCall(string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($callUid) {
            $call = $this->ownCall($user, $business, $callUid);
            // The user's intent is always recorded. Cleanup uses ONLY the call's own number and Business; if that
            // number is gone or disconnected the hangup is reported honestly as needing attention, never "confirmed".
            $this->dialer->cancel($call);
            $state = $call->fresh()->providerHangupState() ?? 'confirmed';

            return response()->json(['status' => 'success', 'provider_hangup' => $state, 'message' => $this->hangupMessage($state)]);
        });
    }

    private function hangupMessage(string $state): ?string
    {
        return match ($state) {
            'pending' => 'Canceled. Waiting for the phone provider to confirm the call has stopped.',
            'attention' => "Canceled, but we couldn't confirm the call has stopped at the phone provider. We'll keep retrying; if the line keeps ringing, contact support.",
            default => null,
        };
    }

    public function clientEvent(Request $request, string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request, $callUid) {
            $data = $request->validate([
                'event' => ['required', 'in:dial_failed,ended'],
                'detail' => ['nullable', 'string', 'max:80'],
            ]);

            $call = $this->ownCall($user, $business, $callUid);
            $call = $this->calls->recordClientEvent($call, $data['event'], $data['detail'] ?? null);

            return response()->json(['status' => 'success', 'call_status' => $call->status]);
        });
    }

    public function note(Request $request, string $workspaceUid, string $businessUid, string $callUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request, $callUid) {
            $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
            $this->calls->saveNote($this->ownCall($user, $business, $callUid), $data['note'] ?? null);

            return response()->json(['status' => 'success']);
        });
    }

    /** Who is this incoming number, in THIS Business only? Unknown numbers return no name — never invented. */
    public function lookup(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request) {
            $e164 = E164Normalizer::normalize((string) $request->query('number'));
            $contact = $e164 !== null ? $this->calls->contactForNumber($business, $e164) : null;
            $name = null;

            if ($contact !== null) {
                $name = app(\App\Library\Contacts\ContactDirectory::class)->profile($business, $contact, false)['name'] ?? null;
            }

            // The incoming call record this user is being rung for, so notes and hints attach to it.
            $call = $e164 === null ? null : VoiceCall::query()
                ->where('business_id', (int) $business->id)
                ->where('user_id', (int) $user->id)
                ->where('direction', VoiceCall::DIRECTION_INBOUND)
                ->where('counterpart_number', $e164)
                ->where('initiated_at', '>=', now()->subMinutes(2))
                ->orderByDesc('id')
                ->first();

            return response()->json(['status' => 'success', 'number' => $e164, 'name' => $name, 'contact_uid' => $contact?->uid, 'call_uid' => $call?->uid]);
        });
    }

    /* ---------------------------------------------------------------- settings */

    public function settings(string $workspaceUid, string $businessUid): View
    {
        $user = Auth::user();
        $resolved = $user instanceof User ? $this->context->resolve($user, $workspaceUid, $businessUid) : null;

        abort_if($resolved === null, 404);
        [$workspace, $business] = $resolved;
        abort_unless($this->authority->mayManage($workspace, 'view_numbers'), 404);

        $managed = (bool) config('services.telnyx.voice_enabled');

        return view('customer.business.voice.settings', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'candidates' => $this->setup->candidates($business),
            'users' => $this->setup->eligibleUsers($workspace, $business),
            'managedEnabled' => $managed && config('services.telnyx.voice_connection_id') && config('services.telnyx.voice_call_control_app_id'),
            'managedWebhookUrl' => url(route('voice.webhooks.telnyx_managed', [], false)),
            'channelWebhooks' => $this->channelWebhookUrls($business),
        ]);
    }

    public function saveNumber(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($request) {
            $this->requireNumberManagement($user, $workspace);

            $data = $request->validate([
                'source' => ['required', 'in:managed,byo'],
                'ref' => ['required', 'integer'],
                'webrtc_connection_id' => ['nullable', 'string', 'max:64'],
                'inbound_user_id' => ['nullable', 'integer'],
                'call_control_app_id' => ['nullable', 'string', 'max:64'],
            ]);

            $row = $this->setup->enable($workspace, $business, $data['source'], (int) $data['ref'], $data['webrtc_connection_id'] ?? null, isset($data['inbound_user_id']) ? (int) $data['inbound_user_id'] : null, $data['call_control_app_id'] ?? null);

            return response()->json(['status' => 'success', 'uid' => $row->uid, 'voice_status' => $row->voice_status]);
        });
    }

    public function verifyNumber(string $workspaceUid, string $businessUid, string $numberUid): JsonResponse
    {
        return $this->guard($workspaceUid, $businessUid, function (User $user, Workspace $workspace, Business $business) use ($numberUid) {
            $this->requireNumberManagement($user, $workspace);

            $number = VoiceNumber::query()->where('business_id', (int) $business->id)->where('uid', $numberUid)->first();

            if ($number === null) {
                throw new VoiceUnavailableException('number_not_allowed', 'That number was not found.', 404);
            }

            return response()->json(['status' => 'success'] + $this->setup->verify($number, $business));
        });
    }

    /* ----------------------------------------------------------------- helpers */

    /**
     * Run an action inside the tenancy chain and translate failures into actionable JSON.
     *
     * @param  callable(User, Workspace, Business): JsonResponse  $action
     */
    private function guard(string $workspaceUid, string $businessUid, callable $action): JsonResponse
    {
        $user = Auth::user();
        $resolved = $user instanceof User ? $this->context->resolve($user, $workspaceUid, $businessUid) : null;

        if ($resolved === null) {
            return $this->error('not_found', 'Calling is not available here.', 404);
        }

        try {
            return $action($user, $resolved[0], $resolved[1]);
        } catch (VoiceUnavailableException $e) {
            return $this->error($e->reason, $e->getMessage(), $e->httpStatus);
        } catch (VoiceProviderException $e) {
            Log::info('voice.provider_failure', ['reason' => $e->reason, 'business' => $resolved[1]->id]);

            return $this->error($e->reason, $e->getMessage(), $e->reason === 'invalid_api_key' ? 422 : 502);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'code' => $code, 'message' => $message], $status);
    }

    private function requireNumberManagement(User $user, Workspace $workspace): void
    {
        if (! $this->authority->allows($user, $workspace, 'view_numbers')) {
            throw new VoiceUnavailableException('not_found', 'Voice settings are not available to you.', 404);
        }
    }

    /** A number this user may register a browser for: this Business's own, ready, still-live number. */
    private function registrationNumber(Business $business, string $numberUid): VoiceNumber
    {
        $number = VoiceNumber::query()->where('business_id', (int) $business->id)->where('uid', $numberUid)->first();

        if ($number === null) {
            throw new VoiceUnavailableException('number_not_allowed', 'That calling number is not available to you.', 403);
        }

        $problem = $this->numbers->problem($number, $business);

        if ($problem !== null) {
            throw new VoiceUnavailableException($problem[0], $problem[1]);
        }

        return $number;
    }

    /** A call record the signed-in user placed or answered in THIS Business. */
    private function ownCall(User $user, Business $business, string $callUid): VoiceCall
    {
        $call = VoiceCall::query()
            ->where('uid', $callUid)
            ->where('business_id', (int) $business->id)
            ->where('user_id', (int) $user->id)
            ->first();

        if ($call === null) {
            throw new VoiceUnavailableException('call_not_found', 'That call was not found.', 404);
        }

        return $call;
    }

    private function settingsUrl(string $workspaceUid, string $businessUid, Workspace $workspace): ?string
    {
        return $this->authority->mayManage($workspace, 'view_numbers')
            ? route('customer.workspaces.businesses.voice.settings', [$workspaceUid, $businessUid])
            : null;
    }

    /** @return array<int, string> channel id => the webhook URL to configure in that Agency's Telnyx account */
    private function channelWebhookUrls(Business $business): array
    {
        if ($business->workspace_id === null) {
            return [];
        }

        return AgencyProspectingChannel::query()
            ->where('workspace_id', (int) $business->workspace_id)
            ->pluck('uid', 'id')
            ->map(fn (string $uid) => route('prospecting.webhooks.agency_telnyx_voice', [$uid, \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($uid)]))
            ->all();
    }
}
