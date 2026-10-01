<?php

namespace Tests\Feature\BusinessEmail\Support;

use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\DTO\BusinessEmail\BusinessEmailProviderCapabilities;
use App\DTO\BusinessEmail\BusinessEmailProviderSendResult;
use App\DTO\BusinessEmail\BusinessEmailTokenGrant;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\Contracts\BusinessEmailProvider;
use Closure;

/**
 * A scriptable in-memory provider. No network, ever. Records every call so a
 * test can assert exactly how many provider calls a scenario made.
 */
final class FakeBusinessEmailProvider implements BusinessEmailProvider
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @var list<BusinessEmailOutbound> */
    public array $sent = [];

    /** @var list<BusinessEmailProviderException|null> consumed one per send(); null = success */
    public array $sendScript = [];

    public ?BusinessEmailProviderException $refreshFailure = null;

    public ?string $rotatedRefreshToken = null;

    public ?string $codeExchangeRefreshToken = 'fake-refresh-token';

    public ?string $codeExchangeScopes = 'fake-send-scope';

    public ?BusinessEmailProviderException $codeExchangeFailure = null;

    public string $mailbox = 'owner@business.test';

    /** Runs inside send(), e.g. to simulate a re-entrant duplicate request. */
    public ?Closure $duringSend = null;

    /** Runs inside exchangeRefreshToken(), e.g. to disconnect mid-refresh. */
    public ?Closure $duringRefresh = null;

    public function __construct(private readonly BusinessEmailProviderType $type)
    {
    }

    public function callCount(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    private function record(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }

    public function provider(): BusinessEmailProviderType
    {
        return $this->type;
    }

    public function capabilities(): BusinessEmailProviderCapabilities
    {
        return new BusinessEmailProviderCapabilities();
    }

    public function authorizationUrl(string $state, bool $forceConsent): string
    {
        $this->record('authorizationUrl');

        return 'https://accounts.' . $this->type->value . '.test/authorize?' . http_build_query(['state' => $state]);
    }

    public function exchangeAuthorizationCode(string $code): BusinessEmailTokenGrant
    {
        $this->record('exchangeAuthorizationCode');

        if ($this->codeExchangeFailure !== null) {
            throw $this->codeExchangeFailure;
        }

        return new BusinessEmailTokenGrant(
            accessToken: 'fake-access-token',
            refreshToken: $this->codeExchangeRefreshToken,
            grantedScopes: $this->codeExchangeScopes,
            accountEmail: $this->mailbox,
            accountId: 'acct-1',
            displayName: 'Owner Name',
        );
    }

    public function exchangeRefreshToken(string $refreshToken): BusinessEmailTokenGrant
    {
        $this->record('exchangeRefreshToken');

        if ($this->duringRefresh !== null) {
            ($this->duringRefresh)();
        }

        if ($this->refreshFailure !== null) {
            throw $this->refreshFailure;
        }

        return new BusinessEmailTokenGrant('fake-access-token', $this->rotatedRefreshToken, null);
    }

    public function send(string $accessToken, BusinessEmailOutbound $email): BusinessEmailProviderSendResult
    {
        $this->record('send');
        $this->sent[] = $email;

        if ($this->duringSend !== null) {
            ($this->duringSend)();
        }

        $next = array_shift($this->sendScript);

        if ($next instanceof BusinessEmailProviderException) {
            throw $next;
        }

        return new BusinessEmailProviderSendResult('pm-' . $this->callCount('send'), 'thread-' . $this->callCount('send'), '<id' . $this->callCount('send') . '@fake>');
    }

    public function revokeGrant(string $refreshToken): void
    {
        $this->record('revokeGrant');
    }
}
