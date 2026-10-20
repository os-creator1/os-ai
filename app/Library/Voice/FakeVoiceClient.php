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

    /** @var array<string, array{exists: bool, active: bool}> */
    public array $connections = [];

    public ?VoiceProviderException $failNext = null;

    private int $sequence = 0;

    public function createTelephonyCredential(string $apiKey, string $connectionId, string $name, string $tag): array
    {
        $this->record('createTelephonyCredential', compact('apiKey', 'connectionId', 'name', 'tag'));
        $this->maybeFail();
        $this->sequence++;

        return ['id' => 'cred-' . $this->sequence, 'sip_username' => 'gencred' . $this->sequence];
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

        return $this->numbers[$e164] ?? ['owned' => false, 'status' => null, 'connection_id' => null];
    }

    public function inspectCredentialConnection(string $apiKey, string $connectionId): array
    {
        $this->record('inspectCredentialConnection', compact('apiKey', 'connectionId'));
        $this->maybeFail();

        return $this->connections[$connectionId] ?? ['exists' => false, 'active' => false];
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
