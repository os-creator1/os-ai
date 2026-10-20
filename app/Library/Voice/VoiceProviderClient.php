<?php

namespace App\Library\Voice;

/**
 * Everything MotionGrove asks Telnyx for voice. One seam so tests run against a fake and a real
 * account is never touched. Every method takes the API key explicitly: the caller decides whose key
 * (managed platform key or an Agency's own) and nothing here reads config or logs it.
 *
 * Failures are reported as a VoiceProviderException carrying a stable `reason` and a customer-safe
 * message — never a raw provider body.
 */
interface VoiceProviderClient
{
    /** @return array{id: string, sip_username: string} */
    public function createTelephonyCredential(string $apiKey, string $connectionId, string $name, string $tag): array;

    /** A short-lived WebRTC JWT for one telephony credential. */
    public function mintToken(string $apiKey, string $credentialId): string;

    public function deleteTelephonyCredential(string $apiKey, string $credentialId): void;

    /**
     * Read-only voice facts about a number in the account behind $apiKey.
     *
     * @return array{owned: bool, status: ?string, connection_id: ?string}
     */
    public function inspectNumber(string $apiKey, string $e164): array;

    /**
     * Read-only facts about a credential connection. `outbound_known` is false when Telnyx did not return
     * the outbound settings at all — which must be treated as "cannot confirm", never as "locked".
     *
     * @return array{exists: bool, active: bool, outbound_known: bool, outbound_profile_id: ?string}
     */
    public function inspectCredentialConnection(string $apiKey, string $connectionId): array;

    /**
     * Read-only facts about a Call Control application.
     *
     * @return array{exists: bool, active: bool, webhook_url: ?string, outbound_known: bool, outbound_profile_id: ?string}
     */
    public function inspectCallControlApp(string $apiKey, string $appId): array;

    /**
     * Place a call from MotionGrove's own Call Control application (POST /calls). Used to ring the user's
     * browser identity; the PSTN leg is only ever created later by transfer to the VALIDATED destination.
     *
     * @return array{call_control_id: string, call_session_id: ?string, call_leg_id: ?string}
     */
    public function dial(string $apiKey, string $appId, string $to, string $from, string $clientState, int $timeoutSeconds): array;

    public function hangupCall(string $apiKey, string $callControlId): void;

    /** Inbound routing: ring one SIP user (their browser) for a call this app controls. */
    public function transferToSip(string $apiKey, string $callControlId, string $sipUri, string $from): void;

    /** Inbound routing: end a call nobody can take (assigned user offline). */
    public function rejectCall(string $apiKey, string $callControlId, string $cause): void;
}
