<?php

namespace App\Library\Voice;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\E164Normalizer;
use App\Library\Voice\Byo\AgencyByoVoice;
use App\Models\AgencyProspectingChannel;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\Blacklists;
use App\Models\BusinessVoiceCall;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Places ONE outbound Call Control call from a Business's own voice-assigned local number to one of its CRM contacts.
 *
 * Authorization (tenancy, permission, entitlement) is the controller's job and has already passed. This class owns the
 * call-level gates, all before any Telnyx request: explicit enablement, live mode, credentials, an https webhook, a
 * contact that belongs to THIS Business with a valid E.164 destination, and a number that is active, primary, local and
 * recorded as CONFIRMED assigned to the configured Voice Connection (voice_assigned_at — never assumed from messaging).
 * Limits are enforced server-side under a row lock on the number so two requests cannot both slip past them:
 * idempotent by key, one active call per contact, a concurrency cap and an hourly cap per Business, and a hard
 * time limit sent to Telnyx. The dial itself, webhooks and call records are the existing voice infrastructure.
 */
final class CustomerCallPlacer
{
    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly TelnyxCallDialer $dialer,
        private readonly BrowserCredentialProvisioner $browserCredentials,
    ) {
    }

    /** @return array{0: BusinessVoiceCall, 1: bool} the call, and whether it is a replay of an earlier identical request */
    public function place(Business $business, ?Contacts $contact, int $userId, string $idempotencyKey, string $channel = 'server', ?string $number = null, ?string $fromToken = null): array
    {
        $cfg = (array) config('messaging.voice.customer_calls');

        // Two separate modes. A Business whose Agency selected its own Telnyx account for voice calls ONLY through it, and a
        // selected-but-unusable account is an actionable refusal here: never a silent switch to platform-paid voice.
        $byo = AgencyByoVoice::resolveFor($business);
        $transport = $byo ?? VoiceTransport::platform();
        $apiKey = $transport->apiKey;
        $connectionId = $transport->connectionId;
        $webhookUrl = $transport->webhookUrl;

        if (! (bool) ($cfg['enabled'] ?? false)) {
            throw new CustomerCallRefusedException('calling_unavailable', 'Calling is not available right now.', 503);
        }
        if ($byo === null && (config('services.telnyx.mode') !== 'live' || $apiKey === '' || $connectionId === ''
            || trim((string) config('services.telnyx.webhook_public_key')) === '')) {
            throw new CustomerCallRefusedException('calling_unavailable', 'Calling is not available right now.', 503);
        }
        if (! str_starts_with($webhookUrl, 'https://')) {
            throw new CustomerCallRefusedException('calling_unavailable', 'Calling is not available right now.', 503);
        }

        // Browser calls ring the agent's browser first, so a provisioned, unexpired session for THIS user must exist.
        $agentSipUri = null;
        if ($channel === 'browser') {
            try {
                BrowserCredentialProvisioner::assertEnabled($byo);
            } catch (BrowserCallsUnavailableException) {
                throw new CustomerCallRefusedException('calling_unavailable', 'Calling is not available right now.', 503);
            }
            $agentSipUri = $this->browserCredentials->sipUriFor($business, $userId, $byo?->channelId, $byo?->webrtcConnectionId);
            if ($agentSipUri === null) {
                throw new CustomerCallRefusedException('browser_session_required', 'Start a calling session first.', 409);
            }
        }

        // A call is to a CRM contact, or (for an authorized conversation with an unlinked number, resolved by the controller)
        // to that exact number. No contact row is ever invented for it.
        if ($contact !== null && (int) $contact->business_id !== (int) $business->id) {
            throw new CustomerCallRefusedException('contact_not_found', 'Contact not found.', 404);
        }

        $destination = E164Normalizer::normalize('+' . ltrim((string) ($contact?->phone ?? $number ?? ''), '+'));
        if ($destination === null) {
            throw new CustomerCallRefusedException('invalid_destination', 'This contact does not have a valid phone number.');
        }
        // Business-scoped, like managed messaging's own opt-out lookups: another tenant's opt-out must never block this one.
        if (Blacklists::query()->where('business_id', $business->id)->where('number', $contact?->phone ?? ltrim($destination, '+'))->exists()) {
            throw new CustomerCallRefusedException('contact_blocked', 'This number is on the do-not-contact list.', 403);
        }

        $selected = $fromToken !== null && $fromToken !== '';
        if ($byo !== null) {
            // The agency's one voice number: the only choice, and only by its own token (never another tenant's number).
            $byoChannel = AgencyByoVoice::selectedChannel($business);
            if ($selected && ($byoChannel === null || ! hash_equals(AgencyByoVoice::selectorToken($business, $byoChannel), (string) $fromToken))) {
                throw new CustomerCallRefusedException('number_not_available', 'That calling number is not available for this business.', 422);
            }
            $number = null;
        } else {
            $number = $selected
                ? $this->resolveSelectedNumber($business, $fromToken, $connectionId)
                : $this->resolveVoiceReadyNumber($business, $connectionId);
        }
        $toHash = BusinessVoiceCall::hashDestination($destination);
        $token = bin2hex(random_bytes(32));
        $window = now()->subSeconds((int) $cfg['ring_timeout_secs'] + (int) $cfg['max_duration_secs'] + 60);

        $pin = null;
        [$call, $replay] = DB::transaction(function () use ($destination, $business, $contact, $userId, $idempotencyKey, $number, $toHash, $token, $window, $cfg, $connectionId, $channel, $selected, $byo, $apiKey, $webhookUrl, &$pin): array {
            // Serialise every placement for this number (or this agency channel): the checks below and the insert are one atomic step.
            if ($byo !== null) {
                $lockedChannel = AgencyProspectingChannel::query()->with('sendingServer')->whereKey($byo->channelId)->lockForUpdate()->first();
                // Re-validate on the LOCKED row: the agency may have disconnected or turned voice off since it was first read.
                $ready = $lockedChannel === null ? null : AgencyByoVoice::readiness($lockedChannel);
                if ($ready === null || ! $ready['ok'] || (int) $lockedChannel->workspace_id !== (int) $business->workspace_id) {
                    throw new CustomerCallRefusedException('byo_voice_unavailable', (string) ($ready['message'] ?? 'Your Telnyx voice setup is unavailable.'), 409);
                }
                // Compare the LOCKED channel's transport to the SAME snapshot resolved for the agent leg.
                // Looking up the channel again before locking can produce a new configuration while $byo still
                // holds the old account/connection/number, allowing a call with a mismatched stored pin.
                $lockedTransport = AgencyByoVoice::transport($lockedChannel);
                if ($lockedTransport === null || $lockedTransport->channelId !== $byo->channelId
                    || ! hash_equals($apiKey, $lockedTransport->apiKey)
                    || $lockedTransport->connectionId !== $connectionId
                    || $lockedTransport->webrtcConnectionId !== $byo->webrtcConnectionId
                    || $lockedTransport->fromNumber !== $byo->fromNumber
                    || $lockedTransport->webhookUrl !== $webhookUrl) {
                    throw new CustomerCallRefusedException('byo_voice_unavailable', 'Your Telnyx voice settings changed while the call was starting. Try again.', 409);
                }
                // Both legs now share the exact verified transport we checked under this lock.
                $pin = AgencyByoVoice::pin($lockedChannel);
            } else {
                $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();
                // Re-validate on the LOCKED row: a renewal may have suspended the number after it was first read.
                $this->assertVoiceReady($locked, $connectionId, ! $selected);
            }

            $existing = BusinessVoiceCall::query()->where('business_id', $business->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->to_hash !== $toHash || (int) $existing->contact_id !== (int) $contact?->id
                    || (int) $existing->business_messaging_number_id !== (int) $number?->id
                    || (int) $existing->agency_prospecting_channel_id !== (int) $byo?->channelId) {
                    throw new CustomerCallRefusedException('idempotency_key_reused', 'That request key was already used for a different call.', 409);
                }

                return [$existing, true];
            }

            // A transport timeout leaves the outcome unknown (the call may be live), so it stays reserved for the
            // bounded window: it cannot be re-dialled to the same contact or slip past the concurrency cap.
            $active = BusinessVoiceCall::query()->where('business_id', $business->id)
                ->whereNotIn('kind', ['inbound', 'forward_verify']) // inbound calls and verification calls have their own caps
                ->where(fn ($q) => $q
                    ->whereIn('status', [BusinessVoiceCall::STATUS_REQUESTED, BusinessVoiceCall::STATUS_INITIATED, BusinessVoiceCall::STATUS_ANSWERED])
                    ->orWhere(fn ($u) => $u->where('status', BusinessVoiceCall::STATUS_FAILED)->where('failure_code', 'transport_error')))
                ->where('created_at', '>', $window);

            // One softphone per browser: a user cannot start a second browser call while one is live.
            if ($channel === 'browser' && (clone $active)->where('channel', 'browser')->where('initiated_by_user_id', $userId)->exists()) {
                throw new CustomerCallRefusedException('already_on_a_call', 'You are already on a call.', 409);
            }
            if ((clone $active)->where('to_hash', $toHash)->exists()) {
                throw new CustomerCallRefusedException('contact_call_in_progress', 'A call to this contact is already in progress.', 409);
            }
            if ((clone $active)->count() >= (int) $cfg['max_concurrent_per_business']) {
                throw new CustomerCallRefusedException('too_many_active_calls', 'Too many calls are in progress. Try again shortly.', 429);
            }
            if (BusinessVoiceCall::query()->where('business_id', $business->id)->where('kind', 'customer')
                ->where('created_at', '>', now()->subHour())->count() >= (int) $cfg['max_per_hour_per_business']) {
                throw new CustomerCallRefusedException('rate_limited', 'Hourly call limit reached.', 429);
            }

            return [BusinessVoiceCall::create([
                'uid' => (string) Str::uuid(),
                'kind' => 'customer',
                'channel' => $channel,
                'client_state_hash' => hash('sha256', $token),
                'business_id' => $business->id,
                'business_messaging_number_id' => $number?->id,
                'agency_prospecting_channel_id' => $byo?->channelId,
                'transport_account_hash' => $pin['account'] ?? null,
                'transport_config_hash' => $pin['config'] ?? null,
                'contact_id' => $contact?->id,
                'caller_number' => $contact === null ? $destination : null, // remote party of an unlinked call (encrypted by the model)
                'initiated_by_user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
                'to_hash' => $toHash,
                'status' => BusinessVoiceCall::STATUS_REQUESTED,
            ]), false];
        });

        if ($replay) {
            return [$call, true];
        }

        $maxDuration = max(1, min((int) $cfg['max_duration_secs'], 3600));

        if ($channel === 'browser') {
            // Leg 1 of 2: ring the agent's browser. The contact is dialled only when the browser answers (the webhook
            // orchestrator does that and bridges), so nobody is ever called into silence.
            $call = $this->dialer->dial(
                $call, $apiKey, $connectionId, $byo?->fromNumber ?? $number->phone_number, $agentSipUri, $webhookUrl, $token,
                ringTimeoutSecs: max(5, (int) config('messaging.voice.browser.agent_ring_timeout_secs')),
                timeLimitSecs: $maxDuration + 90,
                idColumn: 'agent_call_control_id',
            );

            return [$call, false];
        }

        $call = $this->dialer->dial(
            $call, $apiKey, $connectionId, $byo?->fromNumber ?? $number->phone_number, $destination, $webhookUrl, $token,
            ringTimeoutSecs: (int) $cfg['ring_timeout_secs'],
            timeLimitSecs: $maxDuration,
        );

        return [$call, false];
    }

    private function resolveVoiceReadyNumber(Business $business, string $connectionId): BusinessMessagingNumber
    {
        $identity = $this->identities->resolveForBusiness($business);
        if ($identity === null) {
            throw $this->notVoiceReady();
        }

        try {
            $number = $this->identities->resolvePrimaryNumber($identity);
        } catch (\Throwable) {
            throw $this->notVoiceReady();
        }

        $this->assertVoiceReady($number, $connectionId);

        return $number;
    }

    /**
     * Every number of THIS Business a call may be placed from: active, local, provider-backed, and confirmed on the
     * configured Voice Connection. Reached only through the Business's own messaging identity, so another Business's
     * numbers can never appear. The primary number is listed first.
     *
     * @return \Illuminate\Support\Collection<int, BusinessMessagingNumber>
     */
    public function eligibleNumbers(Business $business): \Illuminate\Support\Collection
    {
        $connectionId = trim((string) config('messaging.voice.connection_id'));
        $identity = $this->identities->resolveForBusiness($business);

        if ($identity === null || $connectionId === '') {
            return collect();
        }

        return BusinessMessagingNumber::query()
            ->where('business_messaging_identity_id', (int) $identity->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->filter(function (BusinessMessagingNumber $number) use ($connectionId): bool {
                try {
                    $this->assertVoiceReady($number, $connectionId, false);

                    return true;
                } catch (CustomerCallRefusedException) {
                    return false;
                }
            })
            ->values();
    }

    /**
     * What the number picker offers: [{id, phone, primary}]. A Business on its Agency's own Telnyx account is offered ONLY that
     * account's verified voice number (empty while it is unusable, never the platform's numbers); every other Business is
     * offered its managed numbers exactly as before.
     *
     * @return list<array{id: string, phone: string, primary: bool}>
     */
    public function callingChoices(Business $business): array
    {
        $channel = AgencyByoVoice::selectedChannel($business);
        if ($channel !== null) {
            return AgencyByoVoice::readiness($channel)['ok']
                ? [['id' => AgencyByoVoice::selectorToken($business, $channel), 'phone' => '+' . ltrim((string) $channel->voice_number, '+'), 'primary' => true]]
                : [];
        }

        return $this->eligibleNumbers($business)->map(fn (BusinessMessagingNumber $n): array => [
            'id' => $this->selectorToken($business, $n),
            'phone' => '+' . ltrim((string) $n->phone_number, '+'),
            'primary' => (bool) $n->is_primary,
        ])->values()->all();
    }

    /** The opaque, business-bound token the browser sends instead of a database id. */
    public function selectorToken(Business $business, BusinessMessagingNumber $number): string
    {
        return substr(hash_hmac('sha256', 'callfrom:' . $business->id . ':' . $number->id, (string) config('app.key')), 0, 32);
    }

    private function resolveSelectedNumber(Business $business, string $token, string $connectionId): BusinessMessagingNumber
    {
        foreach ($this->eligibleNumbers($business) as $number) {
            if (hash_equals($this->selectorToken($business, $number), $token)) {
                return $number;
            }
        }

        // Unknown, another Business's, or no longer eligible: all the same answer, and nothing is dialled.
        throw new CustomerCallRefusedException('number_not_available', 'That calling number is not available for this business.', 422);
    }

    private function assertVoiceReady(?BusinessMessagingNumber $number, string $connectionId, bool $requirePrimary = true): void
    {
        if ($number === null
            || ! $number->isActive()
            || ($requirePrimary && ! $number->is_primary)
            || $number->number_type !== PhoneNumberType::Local
            || trim((string) $number->provider_number_reference) === ''
            || $number->voice_assigned_at === null
            || $number->voice_connection_id !== $connectionId) {
            throw $this->notVoiceReady();
        }
    }

    private function notVoiceReady(): CustomerCallRefusedException
    {
        return new CustomerCallRefusedException('number_not_voice_ready', 'This business does not have a number set up for calling.', 409);
    }
}
