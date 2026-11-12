<?php

namespace App\Library\Voice;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\E164Normalizer;
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
    public function place(Business $business, Contacts $contact, int $userId, string $idempotencyKey, string $channel = 'server'): array
    {
        $cfg = (array) config('messaging.voice.customer_calls');
        $apiKey = trim((string) config('services.telnyx.api_key'));
        $connectionId = trim((string) config('messaging.voice.connection_id'));
        $webhookKey = trim((string) config('services.telnyx.webhook_public_key'));

        if (! (bool) ($cfg['enabled'] ?? false) || config('services.telnyx.mode') !== 'live'
            || $apiKey === '' || $connectionId === '' || $webhookKey === '') {
            throw new CustomerCallRefusedException('calling_unavailable', 'Calling is not available right now.', 503);
        }

        $webhookUrl = (string) (config('messaging.voice.webhook_url') ?: route('webhooks.telnyx_voice'));
        if (! str_starts_with($webhookUrl, 'https://')) {
            throw new CustomerCallRefusedException('calling_unavailable', 'Calling is not available right now.', 503);
        }

        // Browser calls ring the agent's browser first, so a provisioned, unexpired session for THIS user must exist.
        $agentSipUri = null;
        if ($channel === 'browser') {
            try {
                BrowserCredentialProvisioner::assertEnabled();
            } catch (BrowserCallsUnavailableException) {
                throw new CustomerCallRefusedException('calling_unavailable', 'Calling is not available right now.', 503);
            }
            $agentSipUri = $this->browserCredentials->sipUriFor($business, $userId);
            if ($agentSipUri === null) {
                throw new CustomerCallRefusedException('browser_session_required', 'Start a calling session first.', 409);
            }
        }

        if ((int) $contact->business_id !== (int) $business->id) {
            throw new CustomerCallRefusedException('contact_not_found', 'Contact not found.', 404);
        }

        $destination = E164Normalizer::normalize('+' . ltrim((string) $contact->phone, '+'));
        if ($destination === null) {
            throw new CustomerCallRefusedException('invalid_destination', 'This contact does not have a valid phone number.');
        }
        // Business-scoped, like managed messaging's own opt-out lookups: another tenant's opt-out must never block this one.
        if (Blacklists::query()->where('business_id', $business->id)->where('number', $contact->phone)->exists()) {
            throw new CustomerCallRefusedException('contact_blocked', 'This number is on the do-not-contact list.', 403);
        }

        $number = $this->resolveVoiceReadyNumber($business, $connectionId);
        $toHash = BusinessVoiceCall::hashDestination($destination);
        $token = bin2hex(random_bytes(32));
        $window = now()->subSeconds((int) $cfg['ring_timeout_secs'] + (int) $cfg['max_duration_secs'] + 60);

        [$call, $replay] = DB::transaction(function () use ($business, $contact, $userId, $idempotencyKey, $number, $toHash, $token, $window, $cfg, $connectionId, $channel): array {
            // Serialise every placement for this number: the checks below and the insert are one atomic step.
            $locked = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();
            // Re-validate on the LOCKED row: a renewal may have suspended the number after it was first read.
            $this->assertVoiceReady($locked, $connectionId);

            $existing = BusinessVoiceCall::query()->where('business_id', $business->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->to_hash !== $toHash || (int) $existing->contact_id !== (int) $contact->id) {
                    throw new CustomerCallRefusedException('idempotency_key_reused', 'That request key was already used for a different call.', 409);
                }

                return [$existing, true];
            }

            // A transport timeout leaves the outcome unknown (the call may be live), so it stays reserved for the
            // bounded window: it cannot be re-dialled to the same contact or slip past the concurrency cap.
            $active = BusinessVoiceCall::query()->where('business_id', $business->id)
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
                'business_messaging_number_id' => $number->id,
                'contact_id' => $contact->id,
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
                $call, $apiKey, $connectionId, $number->phone_number, $agentSipUri, $webhookUrl, $token,
                ringTimeoutSecs: max(5, (int) config('messaging.voice.browser.agent_ring_timeout_secs')),
                timeLimitSecs: $maxDuration + 90,
                idColumn: 'agent_call_control_id',
            );

            return [$call, false];
        }

        $call = $this->dialer->dial(
            $call, $apiKey, $connectionId, $number->phone_number, $destination, $webhookUrl, $token,
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

    private function assertVoiceReady(?BusinessMessagingNumber $number, string $connectionId): void
    {
        if ($number === null
            || ! $number->isActive()
            || ! $number->is_primary
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
