<?php

namespace App\Library\Voice;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Library\AgencyOutreach\AgencyByoSender;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\VoiceNumber;

/**
 * THE one answer to "which numbers may this user call from, for this Business and Location — and
 * with whose Telnyx account?". Everything is re-derived from persistence on every call; nothing a
 * browser sends is trusted.
 *
 * A number is callable only when ALL hold:
 *   - it has an explicit voice row (voice_status = ready) — an SMS-only number is never advertised;
 *   - the number behind it is still live and still this Business's: a managed number is an Active
 *     number of this Business's Active messaging identity; a BYO number is a connected, active
 *     Telnyx channel of this Business's own Workspace, and only the Agency's own Business may use BYO;
 *   - the Location rule holds: a number assigned to Locations serves only those; otherwise the
 *     requested Location (if any) must be an active Location of this Business the user can reach.
 *
 * Managed and BYO never substitute for each other: the credentials for a number are the ones its
 * own `source` names, and nothing falls back to the other.
 */
final class VoiceNumberResolver
{
    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly LocationAccessGuard $locations,
    ) {
    }

    /**
     * @return array{numbers: list<VoiceNumber>, reason: ?string, message: ?string}
     */
    public function callableFor(User $user, Business $business, ?int $locationId = null): array
    {
        if (! (bool) config('services.telnyx.voice_enabled') && ! $this->hasByoNumbers($business)) {
            return $this->none('voice_disabled', 'Browser calling is not enabled for this account yet.');
        }

        $rows = VoiceNumber::query()->where('business_id', (int) $business->id)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            return $this->none('no_voice_number', $this->hasAnyMessagingNumber($business)
                ? 'No number is set up for calling yet. An admin can enable voice for a number in Voice settings.'
                : 'This account has no phone number connected yet. Connect Telnyx or get a number first.');
        }

        if ($locationId !== null && ! $this->userMayUseLocation($user, $business, $locationId)) {
            return $this->none('location_forbidden', 'You do not have access to this contact\'s location.', );
        }

        $usable = [];
        $firstProblem = null;

        foreach ($rows as $row) {
            $problem = $this->problem($row, $business);

            if ($problem === null && ! $this->servesLocation($row, $business, $locationId)) {
                $problem = ['location_mismatch', 'No calling number is assigned to this contact\'s location.'];
            }

            if ($problem === null) {
                $usable[] = $row;
            } else {
                $firstProblem ??= $problem;
            }
        }

        if ($usable === []) {
            return $this->none($firstProblem[0], $firstProblem[1]);
        }

        return ['numbers' => $usable, 'reason' => null, 'message' => null];
    }

    /** One specific number, only if it is callable for this user/Business/Location right now. */
    public function authorize(User $user, Business $business, string $numberUid, ?int $locationId): VoiceNumber
    {
        $found = $this->callableFor($user, $business, $locationId);

        foreach ($found['numbers'] as $number) {
            if ((string) $number->uid === $numberUid) {
                return $number;
            }
        }

        // An unknown, foreign or no-longer-callable number: the same answer, so a uid reveals nothing.
        throw new VoiceUnavailableException(
            $found['reason'] ?? 'number_not_allowed',
            $found['message'] ?? 'That calling number is not available to you for this contact.',
            $found['reason'] === null ? 403 : 422,
        );
    }

    /**
     * Why this number cannot be called from right now; null when it can. Reads only local state.
     *
     * @return array{0: string, 1: string}|null
     */
    public function problem(VoiceNumber $number, Business $business): ?array
    {
        if ((int) $number->business_id !== (int) $business->id) {
            return ['number_not_allowed', 'That calling number does not belong to this account.'];
        }

        if ($number->source === VoiceNumber::SOURCE_MANAGED) {
            if (! (bool) config('services.telnyx.voice_enabled') || ! config('services.telnyx.voice_connection_id') || ! config('services.telnyx.api_key')) {
                return ['voice_disabled', 'Managed browser calling is not enabled on this platform yet.'];
            }

            if (! $this->managedNumberIsLive($number, $business)) {
                return ['number_inactive', 'This number is no longer active. Choose another number.'];
            }
        } elseif ($number->source === VoiceNumber::SOURCE_BYO) {
            $channel = $this->byoChannel($number, $business);

            if ($channel === null) {
                return ['telnyx_disconnected', 'The Telnyx account for this number is disconnected. Reconnect it to place calls.'];
            }

            if (trim((string) $number->webrtc_connection_id) === '') {
                return ['voice_not_configured', 'This number has no voice connection ID yet. Add it in Voice settings.'];
            }
        } else {
            return ['number_not_allowed', 'That calling number is not available.'];
        }

        if (! $number->isReady()) {
            return $number->voice_status === VoiceNumber::STATUS_MISCONFIGURED
                ? ['voice_misconfigured', 'Telnyx voice is not set up correctly for this number. Open Voice settings and run the check again.']
                : ['voice_unverified', 'Voice has not been checked for this number yet. Open Voice settings and run the check.'];
        }

        return null;
    }

    /** The channel behind a BYO number, only while it is live and this Business's own Agency channel. */
    public function byoChannel(VoiceNumber $number, Business $business): ?AgencyProspectingChannel
    {
        if ($number->agency_channel_id === null || $business->workspace_id === null || ! AgencyByoSender::isOwnBusiness($business)) {
            return null;
        }

        $channel = AgencyProspectingChannel::query()
            ->whereKey((int) $number->agency_channel_id)
            ->where('workspace_id', (int) $business->workspace_id)
            ->where('provider', SendingServer::TYPE_TELNYX)
            ->where('status', AgencyProspectingChannel::STATUS_ACTIVE)
            ->with('sendingServer')
            ->first();

        if ($channel === null || AgencyByoSender::credentials($channel) === null) {
            return null;
        }

        return preg_replace('/\D/', '', (string) $channel->sender_number) === preg_replace('/\D/', '', (string) $number->phone_number)
            ? $channel
            : null;
    }

    private function managedNumberIsLive(VoiceNumber $number, Business $business): bool
    {
        if ($number->messaging_number_id === null) {
            return false;
        }

        $messaging = BusinessMessagingNumber::query()->whereKey((int) $number->messaging_number_id)->first();

        if ($messaging === null
            || $messaging->status !== BusinessMessagingNumberStatus::Active
            || $messaging->phone_number !== $number->phone_number) {
            return false;
        }

        $identity = BusinessMessagingIdentity::query()->whereKey((int) $messaging->business_messaging_identity_id)->first();

        return $identity !== null
            && (int) $identity->business_id === (int) $business->id
            && $identity->status === BusinessMessagingIdentityStatus::Active;
    }

    private function servesLocation(VoiceNumber $number, Business $business, ?int $locationId): bool
    {
        if ($number->source === VoiceNumber::SOURCE_MANAGED && $number->messaging_number_id !== null) {
            $messaging = BusinessMessagingNumber::query()->whereKey((int) $number->messaging_number_id)->first();
            $assigned = $messaging !== null ? $this->identities->assignedLocationIds($messaging) : [];

            if ($assigned !== []) {
                return $locationId !== null && in_array($locationId, $assigned, true);
            }
        }

        return $locationId === null || in_array($locationId, $this->identities->activeLocationIds($business), true);
    }

    private function userMayUseLocation(User $user, Business $business, int $locationId): bool
    {
        $location = BusinessLocation::query()->where('business_id', (int) $business->id)->whereKey($locationId)->first();

        return $location !== null && $this->locations->userCanAccessLocation((int) $user->id, $location);
    }

    private function hasAnyMessagingNumber(Business $business): bool
    {
        return BusinessMessagingNumber::query()
            ->whereIn('business_messaging_identity_id', BusinessMessagingIdentity::query()->where('business_id', (int) $business->id)->select('id'))
            ->where('status', BusinessMessagingNumberStatus::Active->value)
            ->exists()
            || ($business->workspace_id !== null && AgencyByoSender::isOwnBusiness($business)
                && AgencyProspectingChannel::query()->where('workspace_id', (int) $business->workspace_id)->where('status', AgencyProspectingChannel::STATUS_ACTIVE)->exists());
    }

    private function hasByoNumbers(Business $business): bool
    {
        return VoiceNumber::query()->where('business_id', (int) $business->id)->where('source', VoiceNumber::SOURCE_BYO)->exists();
    }

    /** @return array{numbers: list<VoiceNumber>, reason: string, message: string} */
    private function none(string $reason, string $message): array
    {
        return ['numbers' => [], 'reason' => $reason, 'message' => $message];
    }
}
