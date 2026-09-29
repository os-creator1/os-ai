<?php

namespace App\Library\Calendar\ExternalCalendar\Contracts;

use App\DTO\Calendar\ExternalCalendarNotificationRegistration;
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

    /**
     * Review correction, §11/§12.F — establish the provider's own push
     * mechanism (Google `events.watch`, Microsoft Graph `POST /subscriptions`)
     * so the provider actually calls this application's webhook endpoint.
     * $proofToken is echoed back by the provider on every later notification
     * (Google's channel `token` header, Microsoft's `clientState`) and MUST
     * be verified there before any pull.
     *
     * @throws ExternalCalendarProviderException
     */
    public function registerNotifications(string $accessToken, string $notificationUrl, string $proofToken, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration;

    /**
     * Best-effort provider-side teardown (Google `channels.stop`, Microsoft
     * Graph `DELETE /subscriptions/{id}`). The caller treats any failure
     * here as non-fatal — local credential/state destruction must never
     * depend on this succeeding.
     *
     * @throws ExternalCalendarProviderException
     */
    public function unregisterNotifications(string $accessToken, string $registrationId, ?string $channelId): void;

    /**
     * Review correction — an IN-PLACE renewal of an existing registration,
     * extending its expiration without changing its identity (Microsoft
     * Graph `PATCH /subscriptions/{id}`). Google Calendar push channels
     * mechanically have no such operation; that implementation throws
     * ExternalCalendarProviderException::registrationNotFound() immediately,
     * without any HTTP call, so the two providers share one caller-side
     * fallback path (ExternalCalendarNotificationRegistrar) rather than the
     * caller branching per provider.
     *
     * MUST throw ExternalCalendarProviderException::registrationNotFound()
     * — never any other classification — whenever the provider reports the
     * specific registration id as gone (Microsoft 404) or the operation is
     * mechanically unsupported (Google, always). The caller's only correct
     * response to that classification is a fresh registerNotifications()
     * call; it must never retry the same renewal.
     *
     * @throws ExternalCalendarProviderException
     */
    public function renewNotifications(string $accessToken, string $registrationId, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration;
}
