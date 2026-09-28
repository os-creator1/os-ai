<?php

namespace App\Library\Calendar\ExternalCalendar\Contracts;

use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\DTO\Calendar\ExternalCalendarTokenGrant;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use Carbon\CarbonInterface;

/**
 * Implementation Contract 15 §12.F — the provider-neutral gateway boundary.
 * No provider SDK type or provider-specific shape may leak past this
 * interface: Google and Outlook implementations each normalize into the
 * same application-owned DTOs (ExternalCalendarTokenGrant,
 * ExternalCalendarSyncPage/ExternalCalendarBusyEvent).
 *
 * Every method may throw ExternalCalendarProviderException; nothing else
 * escapes an implementation (no provider SDK exception, no Guzzle exception).
 */
interface CalendarProviderClient
{
    public function provider(): ExternalCalendarProvider;

    /** @throws ExternalCalendarProviderException */
    public function authorizationUrl(string $state, bool $forceConsent): string;

    /** @throws ExternalCalendarProviderException */
    public function exchangeAuthorizationCode(string $code): ExternalCalendarTokenGrant;

    /** @throws ExternalCalendarProviderException */
    public function exchangeRefreshToken(string $refreshToken): ExternalCalendarTokenGrant;

    /**
     * A complete, paginated read of every busy interval in
     * [$windowStart, $windowEnd). Must throw rather than return a partial
     * page as complete=true.
     *
     * @throws ExternalCalendarProviderException
     */
    public function fetchFullBusy(string $accessToken, CarbonInterface $windowStart, CarbonInterface $windowEnd): ExternalCalendarSyncPage;

    /**
     * A delta read against a previously stored cursor. Throws
     * ExternalCalendarProviderException::cursorInvalid() when the provider
     * reports the cursor as no longer valid (Google 410, Graph
     * resyncRequired) — the caller falls back to fetchFullBusy().
     *
     * @throws ExternalCalendarProviderException
     */
    public function fetchIncrementalBusy(string $accessToken, string $cursor): ExternalCalendarSyncPage;
}
