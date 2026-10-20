<?php

namespace App\Library\Voice;

/**
 * Deterministic in-memory Telnyx for tests: never makes a network call, records what it was asked.
 * Bind with $this->app->instance(VoiceProviderClient::class, new FakeVoiceClient()).
 */
final class FakeVoiceClient implements VoiceProviderClient
{
    /** @var list<array{0: string, 1: array}> */
    public array $calls = [];

    /** @var array<string, array{owned: bool, status: ?string, connection_id: ?string}> */
    public array $numbers = [];

    /** @var array<string, array{exists: bool, active: bool, outbound_known: bool, outbound_profile_id: ?string}> */
    public array $connections = [];

    /** @var array<string, array{exists: bool, active: bool, webhook_url: ?string, outbound_known: bool, outbound_profile_id: ?string}> */
    public array $apps = [];

    public ?VoiceProviderException $failNext = null;

    /**
     * Browser-verification mode (TELNYX_VOICE_DRIVER=fake): every unknown connection/application/number is
     * reported healthy and locked, and call ids are random, so a real app server can run against it. Never
     * true in tests of the safety checks themselves, and never used in production.
     */
    public function __construct(public readonly bool $permissive = false)
    {
    }

    private int $sequence = 0;

    public function createTelephonyCredential(string $apiKey, string $connectionId, string $name, string $tag): array
    {
        $this->record('createTelephonyCredential', compact('apiKey', 'connectionId', 'name', 'tag'));
        $this->maybeFail();
        $this->sequence++;

        $id = $this->permissive ? bin2hex(random_bytes(4)) : (string) $this->sequence;

        return ['id' => 'cred-' . $id, 'sip_username' => 'gencred' . $id];
    }

    public function mintToken(string $apiKey, string $credentialId): string
    {
        $this->record('mintToken', compact('apiKey', 'credentialId'));
        $this->maybeFail();

        return 'aaa.bbb.' . $credentialId;
    }

    public function deleteTelephonyCredential(string $apiKey, string $credentialId): void
    {
        $this->record('deleteTelephonyCredential', compact('apiKey', 'credentialId'));
    }

    public function inspectNumber(string $apiKey, string $e164): array
    {
        $this->record('inspectNumber', compact('apiKey', 'e164'));
        $this->maybeFail();

        return $this->numbers[$e164] ?? ($this->permissive ? ['owned' => true, 'status' => 'active', 'connection_id' => null] : ['owned' => false, 'status' => null, 'connection_id' => null]);
    }

    public function inspectCredentialConnection(string $apiKey, string $connectionId): array
    {
        $this->record('inspectCredentialConnection', compact('apiKey', 'connectionId'));
        $this->maybeFail();

        return $this->connections[$connectionId] ?? ['exists' => $this->permissive, 'active' => $this->permissive, 'outbound_known' => $this->permissive, 'outbound_profile_id' => null];
    }

    public function inspectCallControlApp(string $apiKey, string $appId): array
    {
        $this->record('inspectCallControlApp', compact('apiKey', 'appId'));
        $this->maybeFail();

        return $this->apps[$appId] ?? ['exists' => false, 'active' => false, 'webhook_url' => null, 'outbound_known' => false, 'outbound_profile_id' => null];
    }

    public function dial(string $apiKey, string $appId, string $to, string $from, string $clientState, int $timeoutSeconds): array
    {
        $this->record('dial', compact('apiKey', 'appId', 'to', 'from', 'clientState', 'timeoutSeconds'));
        $this->maybeFail();
        $this->sequence++;
        $id = $this->permissive ? bin2hex(random_bytes(6)) : (string) $this->sequence;

        return ['call_control_id' => 'dial-cc-' . $id, 'call_session_id' => 'dial-sess-' . $id, 'call_leg_id' => 'dial-leg-' . $id];
    }

    public function hangupCall(string $apiKey, string $callControlId): void
    {
        $this->record('hangupCall', compact('apiKey', 'callControlId'));
    }

    public function transferToSip(string $apiKey, string $callControlId, string $sipUri, string $from): void
    {
        $this->record('transferToSip', compact('apiKey', 'callControlId', 'sipUri', 'from'));
        $this->maybeFail();
    }

    public function rejectCall(string $apiKey, string $callControlId, string $cause): void
    {
        $this->record('rejectCall', compact('apiKey', 'callControlId', 'cause'));
        $this->maybeFail();
    }

    /** @return list<array> */
    public function callsTo(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === $method)));
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];
    }

    private function maybeFail(): void
    {
        if ($this->failNext !== null) {
            $e = $this->failNext;
            $this->failNext = null;

            throw $e;
        }
    }
}
