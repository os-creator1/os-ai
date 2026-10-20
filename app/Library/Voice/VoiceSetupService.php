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
    public function enable(Workspace $workspace, Business $business, string $source, int $ref, ?string $connectionId, ?int $inboundUserId): VoiceNumber
    {
        $candidate = collect($this->candidates($business))->first(fn (array $c) => $c['source'] === $source && $c['ref'] === $ref);

        if ($candidate === null) {
            throw new VoiceUnavailableException('number_not_allowed', 'That number does not belong to this account.', 404);
        }

        $connectionId = $connectionId !== null ? trim($connectionId) : null;

        if ($source === VoiceNumber::SOURCE_BYO && ($connectionId === null || $connectionId === '')) {
            throw new VoiceUnavailableException('voice_not_configured', 'Enter the ID of the Credential Connection in your Telnyx account that this number\'s browser calling uses.');
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
            'inbound_user_id' => $inboundUserId,
        ]);

        // Any change to the connection invalidates an earlier check.
        if ($row->exists && $row->isDirty('webrtc_connection_id')) {
            $row->voice_status = VoiceNumber::STATUS_UNVERIFIED;
        }

        $row->save();

        return $row->fresh();
    }

    /**
     * Ask Telnyx (read-only) whether this number can really place browser calls, and record the answer.
     *
     * @return array{ok: bool, code: ?string, message: string, notes: list<string>}
     */
    public function verify(VoiceNumber $number, Business $business): array
    {
        $notes = [];

        try {
            $problem = $this->numbers->problem($number, $business);

            // Only a "not verified yet" status may proceed to the provider check.
            if ($problem !== null && ! in_array($problem[0], ['voice_unverified', 'voice_misconfigured'], true)) {
                return $this->record($number, false, $problem[0], $problem[1], $notes);
            }

            $creds = $this->credentials->forNumber($number, $business);
            $facts = $this->provider->inspectNumber($creds['api_key'], $number->phone_number);

            if (! $facts['owned']) {
                return $this->record($number, false, 'number_not_in_account', 'This number is not in the Telnyx account that MotionGrove would use for it.', $notes);
            }

            if ($facts['status'] !== null && $facts['status'] !== 'active') {
                return $this->record($number, false, 'number_not_active', 'Telnyx reports this number is not active yet.', $notes);
            }

            $connection = $this->provider->inspectCredentialConnection($creds['api_key'], $creds['connection_id']);

            if (! $connection['exists']) {
                return $this->record($number, false, 'voice_connection_missing', 'That voice connection ID was not found in the Telnyx account. Create a Credential Connection with WebRTC enabled and enter its ID.', $notes);
            }

            if (! $connection['active']) {
                return $this->record($number, false, 'voice_connection_inactive', 'The Telnyx voice connection is switched off. Turn it on in Telnyx.', $notes);
            }

            if ($facts['connection_id'] === null) {
                $notes[] = 'Outgoing calls are ready. For INCOMING calls, assign this number to a Telnyx Call Control application whose webhook points to the URL shown on this page.';
            }

            return $this->record($number, true, null, 'Outgoing browser calling is ready for this number.', $notes);
        } catch (VoiceProviderException $e) {
            return $this->record($number, false, $e->reason, $e->getMessage(), $notes);
        } catch (VoiceUnavailableException $e) {
            return $this->record($number, false, $e->reason, $e->getMessage(), $notes);
        }
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

    /** @param list<string> $notes @return array{ok: bool, code: ?string, message: string, notes: list<string>} */
    private function record(VoiceNumber $number, bool $ok, ?string $code, string $message, array $notes): array
    {
        $number->forceFill([
            'voice_status' => $ok ? VoiceNumber::STATUS_READY : VoiceNumber::STATUS_MISCONFIGURED,
            'voice_error_code' => $code,
            'voice_checked_at' => now(),
        ])->save();

        return ['ok' => $ok, 'code' => $code, 'message' => $message, 'notes' => $notes];
    }
}
