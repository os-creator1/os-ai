<?php

namespace App\Library\Voice;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Messaging\E164Normalizer;
use App\Library\Voice\Inbound\InboundCallingSurface;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

/**
 * What the existing CRM browser softphone needs on a page, and who it may be offered for.
 *
 * This is NOT a calling system: placing, session, status and hang-up all stay in VoiceCallController / CustomerCallPlacer,
 * which re-check everything server-side on every request. This class only decides whether the UI may OFFER a Call button,
 * so a button that would certainly be refused is never rendered.
 */
final class ConversationCalling
{
    /**
     * Why outbound browser calling cannot run on this deployment, as a precise, non-sensitive message naming the missing
     * requirement (a setting name, never a value or credential), or null when the platform side is operational. The user's
     * own permission is separate (see unauthorizedReason()).
     */
    public function unavailableReason(Business $business): ?string
    {
        return match (true) {
            ! (bool) config('messaging.voice.customer_calls.enabled') => 'Calling is switched off for this platform (VOICE_CUSTOMER_CALLS_ENABLED).',
            ! (bool) config('messaging.voice.browser.enabled') => 'Browser calling is switched off for this platform (VOICE_BROWSER_CALLS_ENABLED).',
            config('services.telnyx.mode') !== 'live' => 'Telnyx is not in live mode on this platform.',
            trim((string) config('services.telnyx.api_key')) === '' => 'The Telnyx API key is not configured on this platform.',
            trim((string) config('messaging.voice.connection_id')) === '' => 'The Telnyx voice connection is not configured (TELNYX_VOICE_CONNECTION_ID).',
            trim((string) config('messaging.voice.browser.credential_connection_id')) === '' => 'The Telnyx browser-calling connection is not configured (TELNYX_WEBRTC_CONNECTION_ID).',
            trim((string) config('services.telnyx.webhook_public_key')) === '' => 'The Telnyx webhook key is not configured on this platform.',
            ! str_starts_with((string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice')), 'https://') => 'The Telnyx voice webhook URL must be https.',
            default => null,
        };
    }

    /** A precise reason when this user, specifically, may not place calls from this Business; null when they may. */
    public function unauthorizedReason(Business $business): ?string
    {
        return app(CallingAuthority::class)->mayCall(Auth::user(), $business)
            ? null
            : 'Your role does not include placing calls. Ask the account owner to grant "place voice calls".';
    }

    /**
     * The softphone endpoints, or null when this user cannot place browser calls here (switches, live mode, permission,
     * and the CRM entitlement that every calls endpoint requires).
     *
     * @return array{inbound: ?array, sessionUrl: string, placeUrl: string, statusUrl: string, hangupUrl: string}|null
     */
    public function config(Workspace $workspace, Business $business): ?array
    {
        $user = Auth::user();

        if ($this->unavailableReason($business) !== null || ! app(CallingAuthority::class)->mayCall($user, $business)) {
            return null;
        }

        try {
            $entitled = app(EntitlementManager::class)->decide($workspace, $business, PlatformFeature::Crm->value, (int) $user->id)->allowed;
        } catch (\Throwable) {
            return null;
        }

        if (! $entitled) {
            return null;
        }

        $scope = [$workspace->uid, $business->uid];

        return [
            'inbound' => app(InboundCallingSurface::class)->inboundUrls((string) $workspace->uid, $business, $user),
            'sessionUrl' => route('customer.workspaces.businesses.calls.browser-session', $scope),
            'placeUrl' => route('customer.workspaces.businesses.calls.store', $scope),
            'statusUrl' => route('customer.workspaces.businesses.calls.show', [...$scope, '__CALL__']),
            'hangupUrl' => route('customer.workspaces.businesses.calls.hangup', [...$scope, '__CALL__']),
        ];
    }

    /**
     * The open conversation's own number, for a conversation with NO linked contact (no contact is created). Same gates as the
     * placement endpoint: this Business's conversation (its Location already authorized by the caller), a valid E.164 number
     * and no do-not-contact entry. The destination is the customer's number (`to`), never the Business's sending number.
     *
     * @return array{uid: string, conversation: string, label: string, phone: string, initials: string}|null
     */
    public function numberTarget(?array $calling, Business $business, ChatBox $box, string $label): ?array
    {
        if ($calling === null || (int) $box->business_id !== (int) $business->id) {
            return null;
        }

        $digits = \App\Library\Business\Migration\ChatBoxBusinessBackfillV1::normalizeCounterparty((string) $box->to);
        if ($digits === '' || E164Normalizer::normalize('+' . $digits) === null
            || Blacklists::query()->where('business_id', $business->id)->where('number', $digits)->exists()) {
            return null;
        }

        $label = trim($label) !== '' ? trim($label) : '+' . $digits;
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $label)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = $words === [] ? '#' : mb_strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));

        return ['uid' => '', 'conversation' => (string) $box->uid, 'label' => $label, 'phone' => '+' . $digits, 'initials' => $initials];
    }

    /**
     * The one contact the open conversation may call, or null. Requires a verified association (the caller passes only a
     * Contact the conversation resolved to), a Location the actor can reach, a valid E.164 number and no do-not-contact entry:
     * the same gates the placement endpoint applies.
     *
     * @return array{uid: string, label: string, phone: string, initials: string}|null
     */
    public function target(?array $calling, Business $business, ?Contacts $contact, string $label): ?array
    {
        if ($calling === null || $contact === null || (int) $contact->business_id !== (int) $business->id) {
            return null;
        }

        if (E164Normalizer::normalize('+' . ltrim((string) $contact->phone, '+')) === null) {
            return null;
        }

        if ($contact->location_id !== null) {
            $location = $contact->location;
            if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
                return null;
            }
        }

        if (Blacklists::query()->where('business_id', $business->id)->where('number', $contact->phone)->exists()) {
            return null;
        }

        $label = trim($label) !== '' ? trim($label) : (string) $contact->phone;
        $words = preg_split('/\s+/u', trim((string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $label)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = $words === [] ? '#' : mb_strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));

        return ['uid' => (string) $contact->uid, 'label' => $label, 'phone' => (string) $contact->phone, 'initials' => $initials];
    }
}
