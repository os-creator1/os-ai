<?php

namespace App\Library\Voice;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\Messaging\E164Normalizer;
use App\Library\Workspace\BusinessRouteAccess;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\VoiceNumber;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Explicit, per-number voice setup. A number is never assumed voice-capable because it can send SMS:
 * an admin enables voice for it, and the check below asks Telnyx (read-only GETs — nothing is bought,
 * created or changed) whether the number and its voice connection really exist in the right account.
 */
final class VoiceSetupService
{
    public function __construct(
        private readonly VoiceProviderClient $provider,
        private readonly VoiceCredentials $credentials,
        private readonly VoiceNumberResolver $numbers,
        private readonly BusinessRouteAccess $access,
    ) {
    }

    /**
     * Every number of this Business that COULD be set up, with its current voice state.
     *
     * @return list<array{source: string, ref: int, phone_number: string, voice: ?VoiceNumber}>
     */
    public function candidates(Business $business): array
    {
        $out = [];
        $existing = VoiceNumber::query()->where('business_id', (int) $business->id)->get();

        $identityIds = BusinessMessagingIdentity::query()
            ->where('business_id', (int) $business->id)
            ->where('status', BusinessMessagingIdentityStatus::Active->value)
            ->pluck('id');

        foreach (BusinessMessagingNumber::query()
            ->whereIn('business_messaging_identity_id', $identityIds)
            ->where('status', BusinessMessagingNumberStatus::Active->value)
            ->orderBy('id')->get() as $number) {
            $out[] = [
                'source' => VoiceNumber::SOURCE_MANAGED,
                'ref' => (int) $number->id,
                'phone_number' => (string) $number->phone_number,
                'voice' => $existing->first(fn (VoiceNumber $v) => $v->source === VoiceNumber::SOURCE_MANAGED && (int) $v->messaging_number_id === (int) $number->id),
            ];
        }

        if ($business->workspace_id !== null && AgencyByoSender::isOwnBusiness($business)) {
            foreach (AgencyProspectingChannel::query()
                ->where('workspace_id', (int) $business->workspace_id)
                ->where('provider', SendingServer::TYPE_TELNYX)
                ->where('status', AgencyProspectingChannel::STATUS_ACTIVE)
                ->orderBy('id')->get() as $channel) {
                $e164 = E164Normalizer::normalize((string) $channel->sender_number);

                if ($e164 === null) {
                    continue;
                }

                $out[] = [
                    'source' => VoiceNumber::SOURCE_BYO,
                    'ref' => (int) $channel->id,
                    'phone_number' => $e164,
                    'voice' => $existing->first(fn (VoiceNumber $v) => $v->source === VoiceNumber::SOURCE_BYO && (int) $v->agency_channel_id === (int) $channel->id),
                ];
            }
        }

        return $out;
    }

    /**
     * Turn voice on (or update it) for one candidate number. The candidate must be one of THIS Business's
     * own numbers; the inbound user must be allowed to use this Business.
     */
    public function enable(Workspace $workspace, Business $business, string $source, int $ref, ?string $connectionId, ?int $inboundUserId, ?string $callControlAppId = null): VoiceNumber
    {
        $candidate = collect($this->candidates($business))->first(fn (array $c) => $c['source'] === $source && $c['ref'] === $ref);

        if ($candidate === null) {
            throw new VoiceUnavailableException('number_not_allowed', 'That number does not belong to this account.', 404);
        }

        $connectionId = $connectionId !== null ? trim($connectionId) : null;

        $callControlAppId = $callControlAppId !== null ? trim($callControlAppId) : null;

        if ($source === VoiceNumber::SOURCE_BYO && ($connectionId === null || $connectionId === '' || $callControlAppId === null || $callControlAppId === '')) {
            throw new VoiceUnavailableException('voice_not_configured', 'Enter both the Credential Connection ID (for the browser identity) and the Call Control application ID (which places and receives the calls) from your Telnyx account.');
        }

        if ($inboundUserId !== null && ! $this->userMayRing($inboundUserId, $workspace, $business)) {
            throw new VoiceUnavailableException('inbound_user_not_allowed', 'That person cannot take calls for this account.');
        }

        // A phone number has exactly one owner across the platform.
        $taken = VoiceNumber::query()->where('phone_number', $candidate['phone_number'])->first();

        if ($taken !== null && (int) $taken->business_id !== (int) $business->id) {
            throw new VoiceUnavailableException('number_not_allowed', 'That number is already set up for calling by another account.', 409);
        }

        $row = $candidate['voice'] ?? $taken ?? new VoiceNumber(['uid' => (string) Str::uuid()]);

        $row->fill([
            'business_id' => (int) $business->id,
            'workspace_id' => (int) $workspace->id,
            'source' => $source,
            'messaging_number_id' => $source === VoiceNumber::SOURCE_MANAGED ? $ref : null,
            'agency_channel_id' => $source === VoiceNumber::SOURCE_BYO ? $ref : null,
            'phone_number' => $candidate['phone_number'],
            'webrtc_connection_id' => $source === VoiceNumber::SOURCE_BYO ? $connectionId : null,
            'call_control_app_id' => $source === VoiceNumber::SOURCE_BYO ? $callControlAppId : null,
            'inbound_user_id' => $inboundUserId,
        ]);

        // Any change to the Telnyx objects invalidates every earlier check.
        if ($row->exists && $row->isDirty(['webrtc_connection_id', 'call_control_app_id'])) {
            $row->voice_status = VoiceNumber::STATUS_UNVERIFIED;
            $row->outbound_locked_at = null;
            $row->inbound_status = 'unverified';
        }

        $row->save();

        return $row->fresh();
    }

    /**
     * Ask Telnyx (read-only GETs) whether this number can really place AND receive browser calls, and record
     * the answer. Outgoing readiness needs ALL of: the number in the right account and active; a credential
     * connection that exists, is active and has NO outbound voice profile (so a browser cannot dial itself);
     * a Call Control application that is active, has an outbound voice profile (that is where Telnyx enforces
     * destination/spend limits) and whose webhook is MotionGrove's. Incoming readiness additionally needs the
     * number itself to be assigned to that application. Anything Telnyx does not confirm counts as not ready.
     *
     * @return array{ok: bool, code: ?string, message: string, notes: list<string>, inbound_ok: bool, inbound_message: string}
     */
    public function verify(VoiceNumber $number, Business $business): array
    {
        $notes = [];

        try {
            $problem = $this->numbers->problem($number, $business);

            // Only a "not verified yet" status may proceed to the provider check.
            if ($problem !== null && ! in_array($problem[0], ['voice_unverified', 'voice_misconfigured'], true)) {
                return $this->record($number, false, $problem[0], $problem[1], $notes, null);
            }

            $creds = $this->credentials->forNumber($number, $business);
            $facts = $this->provider->inspectNumber($creds['api_key'], $number->phone_number);

            if (! $facts['owned']) {
                return $this->record($number, false, 'number_not_in_account', 'This number is not in the Telnyx account that MotionGrove would use for it.', $notes, null);
            }

            if ($facts['status'] !== null && $facts['status'] !== 'active') {
                return $this->record($number, false, 'number_not_active', 'Telnyx reports this number is not active yet.', $notes, null);
            }

            $connection = $this->provider->inspectCredentialConnection($creds['api_key'], $creds['connection_id']);

            if (! $connection['exists']) {
                return $this->record($number, false, 'voice_connection_missing', 'That voice connection ID was not found in the Telnyx account. Create a Credential Connection with WebRTC enabled and enter its ID.', $notes, null);
            }

            if (! $connection['active']) {
                return $this->record($number, false, 'voice_connection_inactive', 'The Telnyx voice connection is switched off. Turn it on in Telnyx.', $notes, null);
            }

            if (! $connection['outbound_known']) {
                return $this->record($number, false, 'outbound_unconfirmed', 'Telnyx did not report the voice connection\'s outbound settings, so MotionGrove cannot confirm browsers are unable to dial directly. Calling stays off.', $notes, null);
            }

            if ($connection['outbound_profile_id'] !== null) {
                return $this->record($number, false, 'direct_dialing_open', 'The voice connection has an outbound voice profile, which would let any signed-in browser dial the phone network directly. Remove the outbound voice profile from that Credential Connection in Telnyx — MotionGrove places calls itself.', $notes, null);
            }

            $app = $this->provider->inspectCallControlApp($creds['api_key'], $creds['app_id']);

            if (! $app['exists']) {
                return $this->record($number, false, 'call_control_app_missing', 'That Call Control application ID was not found in the Telnyx account.', $notes, null);
            }

            if (! $app['active']) {
                return $this->record($number, false, 'call_control_app_inactive', 'The Call Control application is switched off in Telnyx.', $notes, null);
            }

            if (! $app['outbound_known'] || $app['outbound_profile_id'] === null) {
                return $this->record($number, false, 'call_control_app_no_outbound_profile', 'Assign an outbound voice profile to the Call Control application in Telnyx. It is where Telnyx enforces which countries can be called and the spend limit.', $notes, null);
            }

            $expected = $this->webhookUrlFor($number, $business);

            if ($app['webhook_url'] === null || rtrim($app['webhook_url'], '/') !== rtrim($expected, '/')) {
                return $this->record($number, false, 'call_control_app_webhook_mismatch', 'The Call Control application\'s webhook URL must be exactly: ' . $expected, $notes, null);
            }

            // Outgoing is ready. Incoming needs the number itself on that application.
            $inbound = $facts['connection_id'] === $creds['app_id']
                ? [true, 'Incoming calls to this number reach MotionGrove.']
                : [false, 'Incoming calls are NOT ready: assign this number to the Call Control application in Telnyx (its Voice settings), then check again.'];

            return $this->record($number, true, null, 'Outgoing browser calling is ready for this number.', $notes, $inbound);
        } catch (VoiceProviderException $e) {
            return $this->record($number, false, $e->reason, $e->getMessage(), $notes, null);
        } catch (VoiceUnavailableException $e) {
            return $this->record($number, false, $e->reason, $e->getMessage(), $notes, null);
        }
    }

    /** The exact webhook URL the number's Call Control application must point at. */
    public function webhookUrlFor(VoiceNumber $number, Business $business): string
    {
        if ($number->source === VoiceNumber::SOURCE_MANAGED) {
            return route('voice.webhooks.telnyx_managed');
        }

        $channel = \App\Models\AgencyProspectingChannel::query()->whereKey((int) $number->agency_channel_id)->first();

        return $channel === null ? '' : route('prospecting.webhooks.agency_telnyx_voice', [$channel->uid, \App\Library\AgencyProspecting\AgencyProspectingWebhookToken::forChannel($channel->uid)]);
    }

    /** Active members who may take calls for this Business, for the assignment picker. */
    public function eligibleUsers(Workspace $workspace, Business $business): array
    {
        $ids = WorkspaceMembership::query()->where('workspace_id', (int) $workspace->id)->where('is_active', true)->pluck('user_id')->push((int) $workspace->owner_user_id)->unique();

        return User::query()->whereIn('id', $ids)->orderBy('first_name')->get()
            ->filter(fn (User $u) => $this->userMayRing((int) $u->id, $workspace, $business))
            ->values()->all();
    }

    public function userMayRing(int $userId, Workspace $workspace, Business $business): bool
    {
        $user = User::query()->find($userId);

        return $user !== null
            && $this->access->actorMayUseBusiness($user, $business)
            && Gate::forUser($user)->allows('chat_box');
    }

    /**
     * @param  list<string>  $notes
     * @param  array{0: bool, 1: string}|null  $inbound  null when outgoing readiness itself failed
     * @return array{ok: bool, code: ?string, message: string, notes: list<string>, inbound_ok: bool, inbound_message: string}
     */
    private function record(VoiceNumber $number, bool $ok, ?string $code, string $message, array $notes, ?array $inbound): array
    {
        $inboundOk = $ok && $inbound !== null && $inbound[0];

        $number->forceFill([
            'voice_status' => $ok ? VoiceNumber::STATUS_READY : VoiceNumber::STATUS_MISCONFIGURED,
            'voice_error_code' => $code,
            'voice_checked_at' => now(),
            'outbound_locked_at' => $ok ? now() : null,
            'inbound_status' => $inboundOk ? 'ready' : ($ok ? 'misconfigured' : 'unverified'),
            'inbound_error_code' => $inboundOk || ! $ok ? null : 'number_not_on_app',
        ])->save();

        return [
            'ok' => $ok, 'code' => $code, 'message' => $message, 'notes' => $notes,
            'inbound_ok' => $inboundOk,
            'inbound_message' => $inbound[1] ?? 'Incoming readiness is checked after outgoing setup passes.',
        ];
    }
}
