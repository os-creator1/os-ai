<?php

namespace Tests\Feature\Calendar\ExternalCalendar\Fakes;

use App\DTO\Calendar\ExternalCalendarNotificationRegistration;
use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\DTO\Calendar\ExternalCalendarTokenGrant;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\Contracts\CalendarProviderClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Implementation Contract 15 §12.F — a deterministic fake provider client
 * bound over the container's GoogleCalendarProviderClient/
 * MicrosoftCalendarProviderClient keys in tests. Makes zero real HTTP calls.
 *
 * Every behavior is scripted via public properties/queues so a test can
 * assert exact idempotency, deletion/reconciliation, outage and
 * cursor-invalidation behavior without touching a real provider.
 */
final class FakeCalendarProviderClient implements CalendarProviderClient
{
    public ?ExternalCalendarTokenGrant $codeExchangeGrant = null;

    public ?ExternalCalendarTokenGrant $refreshExchangeGrant = null;

    public ?ExternalCalendarProviderException $throwOnCodeExchange = null;

    public ?ExternalCalendarProviderException $throwOnRefreshExchange = null;

    /** @var array<int, ExternalCalendarSyncPage|ExternalCalendarProviderException> */
    public array $fullBusyQueue = [];

    /** @var array<int, ExternalCalendarSyncPage|ExternalCalendarProviderException> */
    public array $incrementalBusyQueue = [];

    /** Runs while a fetch is "in flight", before it returns — simulates the world changing during the provider call. */
    public ?\Closure $whileFetching = null;

    /** Runs while a refresh exchange is "in flight", before it returns. */
    public ?\Closure $whileRefreshing = null;

    public int $fullBusyCalls = 0;

    public int $incrementalBusyCalls = 0;

    public int $codeExchangeCalls = 0;

    public int $refreshExchangeCalls = 0;

    /** @var array<int, string> access tokens the caller passed to a fetch* call. */
    public array $observedAccessTokens = [];

    public ?ExternalCalendarNotificationRegistration $registrationResult = null;

    public ?ExternalCalendarProviderException $throwOnRegister = null;

    public int $registerCalls = 0;

    /** @var array<int, array{notificationUrl: string, proofToken: string, requestedExpiry: CarbonInterface}> */
    public array $registerRequests = [];

    public int $unregisterCalls = 0;

    /** @var array<int, array{registrationId: string, channelId: ?string}> */
    public array $unregisterRequests = [];

    public ?ExternalCalendarProviderException $throwOnUnregister = null;

    public ?ExternalCalendarNotificationRegistration $renewalResult = null;

    public ?ExternalCalendarProviderException $throwOnRenew = null;

    public int $renewCalls = 0;

    /** @var array<int, array{registrationId: string, requestedExpiry: CarbonInterface}> */
    public array $renewRequests = [];

    public function __construct(private readonly ExternalCalendarProvider $providerEnum)
    {
    }

    public function provider(): ExternalCalendarProvider
    {
        return $this->providerEnum;
    }

    public function authorizationUrl(string $state, bool $forceConsent): string
    {
        return 'https://fake-provider.test/authorize?state=' . urlencode($state) . '&force_consent=' . ($forceConsent ? '1' : '0');
    }

    public function exchangeAuthorizationCode(string $code): ExternalCalendarTokenGrant
    {
        $this->codeExchangeCalls++;

        if ($this->throwOnCodeExchange !== null) {
            throw $this->throwOnCodeExchange;
        }

        return $this->codeExchangeGrant ?? new ExternalCalendarTokenGrant(
            accessToken: 'fake-access-token',
            refreshToken: 'fake-refresh-token-' . $code,
            grantedScopes: 'calendar.readonly',
            externalAccountEmail: 'staff@example.test',
        );
    }

    public function exchangeRefreshToken(string $refreshToken): ExternalCalendarTokenGrant
    {
        $this->refreshExchangeCalls++;

        if ($this->whileRefreshing !== null) {
            ($this->whileRefreshing)();
        }

        if ($this->throwOnRefreshExchange !== null) {
            throw $this->throwOnRefreshExchange;
        }

        return $this->refreshExchangeGrant ?? new ExternalCalendarTokenGrant(
            accessToken: 'fake-access-token-for-' . $refreshToken,
            refreshToken: null,
            grantedScopes: null,
            externalAccountEmail: null,
        );
    }

    public function fetchFullBusy(string $accessToken, CarbonInterface $windowStart, CarbonInterface $windowEnd): ExternalCalendarSyncPage
    {
        $this->fullBusyCalls++;
        $this->observedAccessTokens[] = $accessToken;

        if ($this->whileFetching !== null) {
            ($this->whileFetching)();
        }

        return $this->dequeue($this->fullBusyQueue, new ExternalCalendarSyncPage([], null, true));
    }

    public function fetchIncrementalBusy(string $accessToken, string $cursor): ExternalCalendarSyncPage
    {
        $this->incrementalBusyCalls++;
        $this->observedAccessTokens[] = $accessToken;

        if ($this->whileFetching !== null) {
            ($this->whileFetching)();
        }

        return $this->dequeue($this->incrementalBusyQueue, new ExternalCalendarSyncPage([], $cursor, true));
    }

    public function registerNotifications(string $accessToken, string $notificationUrl, string $proofToken, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration
    {
        $this->registerCalls++;
        $this->registerRequests[] = ['notificationUrl' => $notificationUrl, 'proofToken' => $proofToken, 'requestedExpiry' => $requestedExpiry];

        if ($this->throwOnRegister !== null) {
            throw $this->throwOnRegister;
        }

        return $this->registrationResult ?? new ExternalCalendarNotificationRegistration(
            registrationId: 'fake-registration-' . Str::uuid(),
            channelId: $this->providerEnum === ExternalCalendarProvider::Google ? 'fake-channel-' . Str::uuid() : null,
            expiresAt: $requestedExpiry,
        );
    }

    public function unregisterNotifications(string $accessToken, string $registrationId, ?string $channelId): void
    {
        $this->unregisterCalls++;
        $this->unregisterRequests[] = ['registrationId' => $registrationId, 'channelId' => $channelId];

        if ($this->throwOnUnregister !== null) {
            throw $this->throwOnUnregister;
        }
    }

    /**
     * Default behavior mirrors the real clients' own contract: Google
     * mechanically has no renewal at all (always registrationNotFound(),
     * before "touching" anything); Microsoft renews IN PLACE, keeping the
     * SAME registrationId, unless a test overrides `renewalResult`/
     * `throwOnRenew` to simulate a 404-gone subscription.
     */
    public function renewNotifications(string $accessToken, string $registrationId, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration
    {
        $this->renewCalls++;
        $this->renewRequests[] = ['registrationId' => $registrationId, 'requestedExpiry' => $requestedExpiry];

        if ($this->throwOnRenew !== null) {
            throw $this->throwOnRenew;
        }

        if ($this->providerEnum === ExternalCalendarProvider::Google) {
            throw ExternalCalendarProviderException::registrationNotFound();
        }

        return $this->renewalResult ?? new ExternalCalendarNotificationRegistration(
            registrationId: $registrationId,
            channelId: null,
            expiresAt: $requestedExpiry,
        );
    }

    /** @param array<int, ExternalCalendarSyncPage|ExternalCalendarProviderException> $queue */
    private function dequeue(array &$queue, ExternalCalendarSyncPage $default): ExternalCalendarSyncPage
    {
        if ($queue === []) {
            return $default;
        }

        $next = array_shift($queue);

        if ($next instanceof ExternalCalendarProviderException) {
            throw $next;
        }

        return $next;
    }
}
