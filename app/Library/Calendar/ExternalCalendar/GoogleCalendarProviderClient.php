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
use Illuminate\Support\Str;
use Throwable;

/**
 * Implementation Contract 15 §12.F — Google Calendar, read-only free/busy.
 *
 * Uses `events.list` on the primary calendar rather than the `freebusy`
 * endpoint deliberately: `freebusy` returns opaque intervals with no
 * `provider_event_id`, which §5.6's upsert-by-id idempotency and §5.6's
 * per-event tombstone deletion both require. `singleEvents=true` expands
 * recurring events into concrete occurrences, each with its own stable id,
 * which is what a per-occurrence busy block needs.
 *
 * No Google SDK is used — a direct HTTP client keeps this dependency-free
 * and keeps every response shape normalized at this one boundary, per the
 * contract's explicit "no provider SDK/domain object leaks past the
 * adapter boundary" requirement.
 */
final class GoogleCalendarProviderClient implements CalendarProviderClient
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const EVENTS_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const WATCH_ENDPOINT = 'https://www.googleapis.com/calendar/v3/calendars/primary/events/watch';

    private const CHANNELS_STOP_ENDPOINT = 'https://www.googleapis.com/calendar/v3/channels/stop';

    public function provider(): ExternalCalendarProvider
    {
        return ExternalCalendarProvider::Google;
    }

    public function authorizationUrl(string $state, bool $forceConsent): string
    {
        $config = new ExternalCalendarOAuthConfig();

        $query = [
            'client_id' => $config->clientId(ExternalCalendarProvider::Google),
            'redirect_uri' => $config->redirect(ExternalCalendarProvider::Google),
            'response_type' => 'code',
            'scope' => implode(' ', (array) config('calendar_external.google.scopes')),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        if ($forceConsent) {
            // Every reachable starting state (none/pending/disconnected/
            // revoked) holds no usable refresh token — Google returns one
            // only on a fresh consent (mirrors GBP's beginConnect()).
            $query['prompt'] = 'consent';
        }

        return self::AUTH_ENDPOINT . '?' . http_build_query($query);
    }

    public function exchangeAuthorizationCode(string $code): ExternalCalendarTokenGrant
    {
        $config = new ExternalCalendarOAuthConfig();

        $response = $this->post(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $config->clientId(ExternalCalendarProvider::Google),
            'client_secret' => $config->clientSecret(ExternalCalendarProvider::Google),
            'redirect_uri' => $config->redirect(ExternalCalendarProvider::Google),
            'grant_type' => 'authorization_code',
        ]);

        return new ExternalCalendarTokenGrant(
            accessToken: (string) ($response['access_token'] ?? ''),
            refreshToken: $response['refresh_token'] ?? null,
            grantedScopes: $response['scope'] ?? null,
            externalAccountEmail: null,
        );
    }

    public function exchangeRefreshToken(string $refreshToken): ExternalCalendarTokenGrant
    {
        $config = new ExternalCalendarOAuthConfig();

        $response = $this->post(self::TOKEN_ENDPOINT, [
            'refresh_token' => $refreshToken,
            'client_id' => $config->clientId(ExternalCalendarProvider::Google),
            'client_secret' => $config->clientSecret(ExternalCalendarProvider::Google),
            'grant_type' => 'refresh_token',
        ]);

        return new ExternalCalendarTokenGrant(
            accessToken: (string) ($response['access_token'] ?? ''),
            // Google does not necessarily return a new refresh token on a
            // refresh-token exchange; the caller keeps the existing one.
            refreshToken: $response['refresh_token'] ?? null,
            grantedScopes: $response['scope'] ?? null,
            externalAccountEmail: null,
        );
    }

    public function fetchFullBusy(string $accessToken, CarbonInterface $windowStart, CarbonInterface $windowEnd): ExternalCalendarSyncPage
    {
        $events = [];
        $pageToken = null;
        $syncToken = null;

        do {
            $query = [
                'timeMin' => $windowStart->clone()->utc()->toIso8601ZuluString(),
                'timeMax' => $windowEnd->clone()->utc()->toIso8601ZuluString(),
                'singleEvents' => 'true',
                'showDeleted' => 'true',
                'maxResults' => 250,
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->get(self::EVENTS_ENDPOINT, $accessToken, $query);

            foreach ((array) ($response['items'] ?? []) as $item) {
                $events[] = $this->normalizeEvent($item);
            }

            $pageToken = $response['nextPageToken'] ?? null;
            $syncToken = $response['nextSyncToken'] ?? $syncToken;
        } while ($pageToken !== null);

        // A full read that never reached a final page (no nextSyncToken)
        // cannot seed a valid delta cursor — the next sync falls back to
        // full rather than trusting an absent token.
        return new ExternalCalendarSyncPage($events, $syncToken, true);
    }

    public function fetchIncrementalBusy(string $accessToken, string $cursor): ExternalCalendarSyncPage
    {
        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        // https://developers.google.com/calendar/api/guides/sync — Google's
        // documented pagination rule is that page 2+ repeats the SAME
        // request parameters and adds pageToken; it is never a
        // pageToken-only request. An earlier version of this method
        // replaced the query wholesale on page 2, silently dropping
        // syncToken/singleEvents from every page after the first.
        //
        // Review correction — query hardening. `maxResults` is now explicit
        // and stable across every page (was previously only set on the
        // full-sync query). `showDeleted` is explicit and MUST stay true: a
        // syncToken response is exactly where Google reports deletions
        // (§5.6 rule 1's tombstones), so setting it false here would
        // silently stop discovering them. `timeMin`/`timeMax` are
        // DELIBERATELY never included alongside `syncToken` — Google's API
        // explicitly rejects that combination.
        $baseQuery = [
            'syncToken' => $cursor,
            'singleEvents' => 'true',
            'showDeleted' => 'true',
            'maxResults' => 250,
        ];

        do {
            $query = $baseQuery;

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            try {
                $response = $this->get(self::EVENTS_ENDPOINT, $accessToken, $query);
            } catch (ExternalCalendarProviderException $exception) {
                // Google answers 410 Gone when a syncToken has expired or
                // is otherwise invalid — the caller must fall back to a
                // full sync rather than retry this cursor.
                if ($exception->classification === ExternalCalendarProviderException::FAILURE_UNEXPECTED_RESPONSE) {
                    throw ExternalCalendarProviderException::cursorInvalid();
                }

                throw $exception;
            }

            foreach ((array) ($response['items'] ?? []) as $item) {
                $events[] = $this->normalizeEvent($item);
            }

            $pageToken = $response['nextPageToken'] ?? null;
            $nextSyncToken = $response['nextSyncToken'] ?? $nextSyncToken;
        } while ($pageToken !== null);

        return new ExternalCalendarSyncPage($events, $nextSyncToken ?? $cursor, true);
    }

    /**
     * https://developers.google.com/calendar/api/guides/push — `events.watch`
     * on the primary calendar. Google echoes `id` back as `X-Goog-Channel-ID`
     * and `token` back as `X-Goog-Channel-Token` on every notification; the
     * response's `resourceId` (opaque, provider-assigned) is required by
     * `channels.stop` and is NOT the same as `id`. `expiration` is optional
     * on the request (ms-epoch) — Google may grant a shorter one than asked.
     */
    public function registerNotifications(string $accessToken, string $notificationUrl, string $proofToken, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration
    {
        $channelId = (string) Str::uuid();

        $response = $this->postJsonAuthed($accessToken, self::WATCH_ENDPOINT, [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $notificationUrl,
            'token' => $proofToken,
            'expiration' => (string) $requestedExpiry->clone()->utc()->getTimestampMs(),
        ]);

        $resourceId = $response['resourceId'] ?? null;

        if (! is_string($resourceId) || $resourceId === '') {
            throw ExternalCalendarProviderException::unexpectedResponse();
        }

        $grantedExpiration = isset($response['expiration']) && is_string($response['expiration'])
            ? Carbon::createFromTimestampMs((int) $response['expiration'])->utc()
            : $requestedExpiry->clone()->utc();

        return new ExternalCalendarNotificationRegistration($resourceId, $channelId, $grantedExpiration);
    }

    /**
     * https://developers.google.com/calendar/api/guides/push — Google
     * Calendar push channels have NO in-place renewal operation of any
     * kind: the only way to extend coverage is a brand new `events.watch`
     * call with a fresh, unique channel id (registerNotifications()).
     * Always throws registrationNotFound() immediately, before any HTTP
     * call — never attempts to PATCH a watch channel, because no such
     * endpoint exists.
     *
     * @throws ExternalCalendarProviderException always, with classification registrationNotFound()
     */
    public function renewNotifications(string $accessToken, string $registrationId, CarbonInterface $requestedExpiry): ExternalCalendarNotificationRegistration
    {
        throw ExternalCalendarProviderException::registrationNotFound();
    }

    /**
     * https://developers.google.com/calendar/api/guides/push#stopping-notifications
     * — `channels.stop` needs BOTH the channel id and the resourceId; a
     * missing channel id (nothing was ever successfully registered) is a
     * harmless no-op, never an error.
     */
    public function unregisterNotifications(string $accessToken, string $registrationId, ?string $channelId): void
    {
        if ($channelId === null || $channelId === '') {
            return;
        }

        $this->postJsonAuthed($accessToken, self::CHANNELS_STOP_ENDPOINT, [
            'id' => $channelId,
            'resourceId' => $registrationId,
        ]);
    }

    private function normalizeEvent(array $item): ExternalCalendarBusyEvent
    {
        $id = (string) ($item['id'] ?? '');
        $status = (string) ($item['status'] ?? '');

        if ($status === 'cancelled') {
            return new ExternalCalendarBusyEvent($id, deleted: true);
        }

        // Free (transparency=transparent) events are explicitly not busy —
        // never turned into a block that would falsely refuse a booking.
        if (($item['transparency'] ?? 'opaque') === 'transparent') {
            return new ExternalCalendarBusyEvent($id, deleted: true);
        }

        $start = $item['start']['dateTime'] ?? $item['start']['date'] ?? null;
        $end = $item['end']['dateTime'] ?? $item['end']['date'] ?? null;

        if ($start === null || $end === null) {
            return new ExternalCalendarBusyEvent($id, deleted: true);
        }

        return new ExternalCalendarBusyEvent(
            providerEventId: $id,
            deleted: false,
            startAt: Carbon::parse($start)->utc(),
            endAt: Carbon::parse($end)->utc(),
            busyType: (string) ($item['status'] ?? 'busy') === 'tentative' ? 'tentative' : 'busy',
        );
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
    private function get(string $url, string $accessToken, array $query): array
    {
        try {
            $response = Http::withToken($accessToken)
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

        throw match ($response->status()) {
            400, 401 => ExternalCalendarProviderException::invalidGrant(),
            403 => ExternalCalendarProviderException::accessDenied(),
            410 => ExternalCalendarProviderException::unexpectedResponse(),
            429 => ExternalCalendarProviderException::rateLimited(),
            default => $response->serverError()
                ? ExternalCalendarProviderException::providerUnavailable()
                : ExternalCalendarProviderException::unexpectedResponse(),
        };
    }
}
