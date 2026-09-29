<?php

namespace App\Library\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarBusyEvent;
use App\DTO\Calendar\ExternalCalendarNotificationRegistration;
use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\DTO\Calendar\ExternalCalendarTokenGrant;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\Contracts\CalendarProviderClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Implementation Contract 15 §12.F — Microsoft Outlook / Graph, read-only
 * free/busy via the calendarView delta query
 * (https://learn.microsoft.com/graph/delta-query-events).
 *
 * The delta query on `/me/calendarview/delta` is used for BOTH the initial
 * full read and every subsequent incremental read: the first call (no
 * `$deltatoken`) returns the full set of occurrences in the window plus a
 * final `@odata.deltaLink` carrying the cursor for next time; a later call
 * with that cursor returns only what changed, including `@removed` entries
 * for deletions. This is the one in-repo precedent this codebase has for
 * Microsoft Graph of any kind — written from scratch per the contract.
 */
final class MicrosoftCalendarProviderClient implements CalendarProviderClient
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private const AUTH_BASE = 'https://login.microsoftonline.com';

    public function provider(): ExternalCalendarProvider
    {
        return ExternalCalendarProvider::Outlook;
    }

    public function authorizationUrl(string $state, bool $forceConsent): string
    {
        $config = new ExternalCalendarOAuthConfig();

        $query = [
            'client_id' => $config->clientId(ExternalCalendarProvider::Outlook),
            'redirect_uri' => $config->redirect(ExternalCalendarProvider::Outlook),
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => implode(' ', (array) config('calendar_external.outlook.scopes')),
            'state' => $state,
        ];

        if ($forceConsent) {
            $query['prompt'] = 'consent';
        }

        return $this->authEndpoint('authorize') . '?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code): ExternalCalendarTokenGrant
    {
        $config = new ExternalCalendarOAuthConfig();

        $response = $this->post($this->authEndpoint('token'), [
            'code' => $code,
            'client_id' => $config->clientId(ExternalCalendarProvider::Outlook),
            'client_secret' => $config->clientSecret(ExternalCalendarProvider::Outlook),
            'redirect_uri' => $config->redirect(ExternalCalendarProvider::Outlook),
            'grant_type' => 'authorization_code',
            'scope' => implode(' ', (array) config('calendar_external.outlook.scopes')),
        ]);

        $accessToken = (string) ($response['access_token'] ?? '');

        return new ExternalCalendarTokenGrant(
            accessToken: $accessToken,
            refreshToken: $response['refresh_token'] ?? null,
            grantedScopes: $response['scope'] ?? null,
            externalAccountEmail: $this->accountEmail($accessToken),
        );
    }

    public function exchangeRefreshToken(string $refreshToken): ExternalCalendarTokenGrant
    {
        $config = new ExternalCalendarOAuthConfig();

        $response = $this->post($this->authEndpoint('token'), [
            'refresh_token' => $refreshToken,
            'client_id' => $config->clientId(ExternalCalendarProvider::Outlook),
            'client_secret' => $config->clientSecret(ExternalCalendarProvider::Outlook),
            'grant_type' => 'refresh_token',
            'scope' => implode(' ', (array) config('calendar_external.outlook.scopes')),
        ]);

        return new ExternalCalendarTokenGrant(
            accessToken: (string) ($response['access_token'] ?? ''),
            // Graph DOES normally rotate the refresh token on every use;
            // if absent, the caller keeps the existing one.
            refreshToken: $response['refresh_token'] ?? null,
            grantedScopes: $response['scope'] ?? null,
            externalAccountEmail: null,
        );
    }

    public function fetchFullBusy(string $accessToken, CarbonInterface $windowStart, CarbonInterface $windowEnd): ExternalCalendarSyncPage
    {
        $url = self::GRAPH_BASE . '/me/calendarview/delta';

        $query = [
            'startDateTime' => $windowStart->clone()->utc()->toIso8601ZuluString(),
            'endDateTime' => $windowEnd->clone()->utc()->toIso8601ZuluString(),
        ];

        return $this->walkDelta($accessToken, $url, $query);
    }

    public function fetchIncrementalBusy(string $accessToken, string $cursor): ExternalCalendarSyncPage
    {
        try {
            return $this->walkDelta($accessToken, $cursor, []);
        } catch (ExternalCalendarProviderException $exception) {
            // Graph answers 410 Gone with an `Location` resync header, or a
            // 400 "resyncRequired" error code, when a deltaLink can no
            // longer be honored.
            if ($exception->classification === ExternalCalendarProviderException::FAILURE_UNEXPECTED_RESPONSE) {
                throw ExternalCalendarProviderException::cursorInvalid();
            }

            throw $exception;
        }
    }

    private function walkDelta(string $accessToken, string $url, array $query): ExternalCalendarSyncPage
    {
        $events = [];
        $deltaLink = null;
        $nextUrl = $url;
        $nextQuery = $query;

        do {
            $response = $this->get($accessToken, $nextUrl, $nextQuery);

            foreach ((array) ($response['value'] ?? []) as $item) {
                $events[] = $this->normalizeEvent($item);
            }

            if (isset($response['@odata.nextLink'])) {
                $nextUrl = (string) $response['@odata.nextLink'];
                $nextQuery = [];

                continue;
            }

            $deltaLink = $response['@odata.deltaLink'] ?? null;

            break;
        } while (true);

        return new ExternalCalendarSyncPage($events, $deltaLink, true);
    }

    private function normalizeEvent(array $item): ExternalCalendarBusyEvent
    {
        $id = (string) ($item['id'] ?? '');

        if (array_key_exists('@removed', $item)) {
            return new ExternalCalendarBusyEvent($id, deleted: true);
        }

        // Graph's own "Free" showAs is explicitly not busy.
        if (($item['showAs'] ?? 'busy') === 'free') {
            return new ExternalCalendarBusyEvent($id, deleted: true);
        }

        $start = $item['start']['dateTime'] ?? null;
        $end = $item['end']['dateTime'] ?? null;

        if ($start === null || $end === null) {
            return new ExternalCalendarBusyEvent($id, deleted: true);
        }

        // Graph returns start/end in the timezone named by start.timeZone
        // (UTC when the request used Prefer: outlook.timezone="UTC", which
        // this client always sends) — parsed as UTC either way, matching
        // this repository's UTC storage rule for start_at/end_at.
        return new ExternalCalendarBusyEvent(
            providerEventId: $id,
            deleted: false,
            startAt: Carbon::parse($start, 'UTC')->utc(),
            endAt: Carbon::parse($end, 'UTC')->utc(),
            busyType: (string) ($item['showAs'] ?? 'busy') === 'tentative' ? 'tentative' : 'busy',
        );
    }

    /**
     * https://learn.microsoft.com/graph/api/subscription-post-subscriptions
     * — `POST /subscriptions` on `me/events`. `clientState` is REQUIRED and
     * is echoed back verbatim on every notification body — this application
     * sends its own deterministic ExternalCalendarWebhookToken HMAC as the
     * value, so nothing new needs to be stored to verify it later. The
     * granted `expirationDateTime` may be shorter than requested (the
     * documented maximum for the `event` resource is 10,080 minutes / 7
     * days) and is what the caller must persist and renew against.
     */
    public function registerNotifications(string $accessToken, string $notificationUrl, string $proofToken, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration
    {
        $response = $this->postJsonAuthed($accessToken, self::GRAPH_BASE . '/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => $notificationUrl,
            'resource' => 'me/events',
            'expirationDateTime' => $requestedExpiry->clone()->utc()->toIso8601ZuluString(),
            'clientState' => $proofToken,
        ]);

        $id = $response['id'] ?? null;
        $expirationDateTime = $response['expirationDateTime'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($expirationDateTime)) {
            throw ExternalCalendarProviderException::unexpectedResponse();
        }

        return new ExternalCalendarNotificationRegistration($id, null, Carbon::parse($expirationDateTime)->utc());
    }

    /**
     * https://learn.microsoft.com/graph/api/subscription-update
     * — `PATCH /subscriptions/{id}` with a fresh `expirationDateTime` ONLY.
     * Graph keeps the SAME subscription id and clientState; this never
     * POSTs a replacement. A 404 means the subscription no longer exists
     * (expired past grace, or removed provider-side) and CANNOT be
     * renewed — that specific case is reported as
     * registrationNotFound() so the caller falls back to
     * registerNotifications() exactly once, never retried here.
     */
    public function renewNotifications(string $accessToken, string $registrationId, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration
    {
        $response = $this->patchJsonAuthed($accessToken, self::GRAPH_BASE . '/subscriptions/' . $registrationId, [
            'expirationDateTime' => $requestedExpiry->clone()->utc()->toIso8601ZuluString(),
        ]);

        $id = $response['id'] ?? null;
        $expirationDateTime = $response['expirationDateTime'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($expirationDateTime)) {
            throw ExternalCalendarProviderException::unexpectedResponse();
        }

        return new ExternalCalendarNotificationRegistration($id, null, Carbon::parse($expirationDateTime)->utc());
    }

    /**
     * https://learn.microsoft.com/graph/api/subscription-delete
     * — `DELETE /subscriptions/{id}`, 204 on success. A missing/already-gone
     * subscription id is a harmless no-op, never an error.
     */
    public function unregisterNotifications(string $accessToken, string $registrationId, ?string $channelId): void
    {
        if ($registrationId === '') {
            return;
        }

        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout((int) config('calendar_external.http.connect_timeout_seconds'))
                ->timeout((int) config('calendar_external.http.request_timeout_seconds'))
                ->delete(self::GRAPH_BASE . '/subscriptions/' . $registrationId);
        } catch (Throwable) {
            throw ExternalCalendarProviderException::timeout();
        }

        // 404 here means the subscription is already gone (expired,
        // provider-side cleanup, or already deleted) — not a failure.
        if ($response->status() === 404) {
            return;
        }

        $this->classified($response);
    }

    private function authEndpoint(string $segment): string
    {
        $tenant = (string) config('calendar_external.outlook.tenant', 'common');

        return self::AUTH_BASE . '/' . $tenant . '/oauth2/v2.0/' . $segment;
    }

    /** Best-effort only — a failure here never breaks the connection itself. */
    private function accountEmail(string $accessToken): ?string
    {
        try {
            $response = $this->get($accessToken, self::GRAPH_BASE . '/me', []);
        } catch (ExternalCalendarProviderException) {
            return null;
        }

        $email = $response['mail'] ?? $response['userPrincipalName'] ?? null;

        return is_string($email) ? $email : null;
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $form): array
    {
        try {
            $response = Http::asForm()
                ->connectTimeout((int) config('calendar_external.http.connect_timeout_seconds'))
                ->timeout((int) config('calendar_external.http.request_timeout_seconds'))
                ->post($url, $form);
        } catch (Throwable) {
            throw ExternalCalendarProviderException::timeout();
        }

        return $this->classified($response);
    }

    /** @return array<string, mixed> */
    private function postJsonAuthed(string $accessToken, string $url, array $body): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout((int) config('calendar_external.http.connect_timeout_seconds'))
                ->timeout((int) config('calendar_external.http.request_timeout_seconds'))
                ->post($url, $body);
        } catch (Throwable) {
            throw ExternalCalendarProviderException::timeout();
        }

        return $this->classified($response);
    }

    /** @return array<string, mixed> */
    private function patchJsonAuthed(string $accessToken, string $url, array $body): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout((int) config('calendar_external.http.connect_timeout_seconds'))
                ->timeout((int) config('calendar_external.http.request_timeout_seconds'))
                ->patch($url, $body);
        } catch (Throwable) {
            throw ExternalCalendarProviderException::timeout();
        }

        return $this->classified($response);
    }

    /** @return array<string, mixed> */
    private function get(string $accessToken, string $url, array $query): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Prefer' => 'outlook.timezone="UTC"'])
                ->connectTimeout((int) config('calendar_external.http.connect_timeout_seconds'))
                ->timeout((int) config('calendar_external.http.request_timeout_seconds'))
                ->get($url, $query);
        } catch (Throwable) {
            throw ExternalCalendarProviderException::timeout();
        }

        return $this->classified($response);
    }

    /** @return array<string, mixed> */
    private function classified(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        return match ($response->status()) {
            400, 401 => throw ExternalCalendarProviderException::invalidGrant(),
            403 => throw ExternalCalendarProviderException::accessDenied(),
            // A 404 on any Graph call this client makes means the named
            // resource (most relevantly: the subscription a PATCH/DELETE
            // targeted) no longer exists — never renewable, only
            // re-creatable.
            404 => throw ExternalCalendarProviderException::registrationNotFound(),
            410 => throw ExternalCalendarProviderException::unexpectedResponse(),
            429 => throw ExternalCalendarProviderException::rateLimited(),
            default => throw ($response->serverError()
                ? ExternalCalendarProviderException::providerUnavailable()
                : ExternalCalendarProviderException::unexpectedResponse()),
        };
    }
}
