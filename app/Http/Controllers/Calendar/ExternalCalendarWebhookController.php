<?php

namespace App\Http\Controllers\Calendar;

use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Http\Controllers\Controller;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarWebhookToken;
use App\Models\ExternalCalendarConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Implementation Contract 15 §11/§12.F — inbound Google/Outlook push
 * notification ingestion.
 *
 * REVIEW CORRECTION. The URL-embedded HMAC token
 * (ExternalCalendarWebhookToken, unchanged, still verified first — zero
 * database reads) makes the notification URL unguessable, but Contract 15
 * §11 names a SECOND, provider-issued proof that must also be verified:
 * Google's channel id + resource id + channel token (headers), Microsoft's
 * subscription id + clientState (notification body). Both are compared
 * against ExternalCalendarNotificationRegistrar's own registration record
 * on the connection row — a request that does not match a REAL, currently
 * live provider registration this application itself created is refused,
 * regardless of whether the URL token was correct. This is what makes the
 * connection genuinely "resolved from the verified channel identity" (§11)
 * rather than from routing material alone.
 *
 * Every failure — bad URL token, unknown connection, inactive connection,
 * wrong channel/resource id, wrong token/clientState, or an expired
 * registration — returns the IDENTICAL response, so an unknown registration
 * is indistinguishable from a forged proof.
 *
 * A verified webhook remains a TRIGGER, never a payload: no field read from
 * a notification (Google's headers beyond the proof itself; Microsoft's
 * `resourceData`) is ever written to a busy block. Only the resulting
 * authenticated pull, via ExternalCalendarSyncService, may change busy
 * blocks.
 */
class ExternalCalendarWebhookController extends Controller
{
    private const REJECTED = 404;

    public function __construct(private readonly ExternalCalendarSyncService $sync)
    {
    }

    public function google(Request $request, string $connectionUid, string $token): Response
    {
        if (! ExternalCalendarWebhookToken::isValid($connectionUid, ExternalCalendarProvider::Google->value, $token)) {
            return response('', self::REJECTED);
        }

        $connection = $this->resolveActiveConnection($connectionUid, ExternalCalendarProvider::Google);

        if ($connection === null) {
            return response('', self::REJECTED);
        }

        $channelId = $request->header('X-Goog-Channel-ID');
        $resourceId = $request->header('X-Goog-Resource-ID');
        $channelToken = $request->header('X-Goog-Channel-Token');
        $resourceState = $request->header('X-Goog-Resource-State');

        if (! $this->googleProofValid($connection, $channelId, $resourceId, $channelToken)) {
            return response('', self::REJECTED);
        }

        // https://developers.google.com/calendar/api/guides/push — the
        // `sync` state fires once, immediately on channel creation, to
        // confirm the channel is live. It carries no change; the full sync
        // already run at registration time (or the next scheduled sweep)
        // establishes state, so there is nothing to pull here. Still
        // authenticated above, still answered success, never ignored
        // silently before verification.
        if ($resourceState === 'sync') {
            return response('', 200);
        }

        $this->sync->syncConnection($connection);

        return response('', 200);
    }

    public function outlook(Request $request, string $connectionUid, string $token): Response
    {
        if (! ExternalCalendarWebhookToken::isValid($connectionUid, ExternalCalendarProvider::Outlook->value, $token)) {
            return response('', self::REJECTED);
        }

        // Microsoft Graph's endpoint validation handshake — answered BEFORE
        // any notification-body parsing, and independent of it: this is
        // proving the URL is reachable at subscribe time, not authenticating
        // a later change notification (those still require clientState,
        // checked below, every time).
        $validationToken = $request->query('validationToken');

        if (is_string($validationToken) && $validationToken !== '') {
            return response($validationToken, 200)->header('Content-Type', 'text/plain');
        }

        $connection = $this->resolveActiveConnection($connectionUid, ExternalCalendarProvider::Outlook);

        if ($connection === null) {
            return response('', self::REJECTED);
        }

        $items = (array) ($request->input('value') ?? []);

        // Only ever read to authenticate — subscriptionId/clientState are
        // the proof (§11); every other field (resource, changeType,
        // resourceData) is never inspected, matching "the body is never
        // event truth."
        $authenticated = false;

        foreach ($items as $item) {
            $subscriptionId = is_array($item) ? ($item['subscriptionId'] ?? null) : null;
            $clientState = is_array($item) ? ($item['clientState'] ?? null) : null;

            if (is_string($subscriptionId) && is_string($clientState)
                && $this->microsoftProofValid($connection, $subscriptionId, $clientState)) {
                $authenticated = true;

                break;
            }
        }

        if (! $authenticated) {
            return response('', self::REJECTED);
        }

        $this->sync->syncConnection($connection);

        return response('', 202);
    }

    private function resolveActiveConnection(string $connectionUid, ExternalCalendarProvider $provider): ?ExternalCalendarConnection
    {
        $connection = ExternalCalendarConnection::query()
            ->where('uid', $connectionUid)
            ->where('provider', $provider->value)
            ->first();

        if ($connection === null || $connection->state !== ExternalCalendarConnectionState::Active) {
            return null;
        }

        return $connection;
    }

    private function googleProofValid(
        ExternalCalendarConnection $connection,
        ?string $channelId,
        ?string $resourceId,
        ?string $channelToken
    ): bool {
        if ($channelId === null || $resourceId === null || $channelToken === null) {
            return false;
        }

        if (empty($connection->notification_channel_id) || empty($connection->notification_registration_id)) {
            return false;
        }

        if (! hash_equals((string) $connection->notification_channel_id, $channelId)) {
            return false;
        }

        if (! hash_equals((string) $connection->notification_registration_id, $resourceId)) {
            return false;
        }

        $expectedToken = ExternalCalendarWebhookToken::forConnection((string) $connection->uid, ExternalCalendarProvider::Google->value);

        if (! hash_equals($expectedToken, $channelToken)) {
            return false;
        }

        return $this->registrationNotExpired($connection);
    }

    private function microsoftProofValid(ExternalCalendarConnection $connection, string $subscriptionId, string $clientState): bool
    {
        if (empty($connection->notification_registration_id)) {
            return false;
        }

        if (! hash_equals((string) $connection->notification_registration_id, $subscriptionId)) {
            return false;
        }

        $expectedState = ExternalCalendarWebhookToken::forConnection((string) $connection->uid, ExternalCalendarProvider::Outlook->value);

        if (! hash_equals($expectedState, $clientState)) {
            return false;
        }

        return $this->registrationNotExpired($connection);
    }

    /**
     * §11 — an expired proof must never trigger a pull. Tied to the ACTUAL
     * provider-granted expiration this application stored at registration
     * time (ExternalCalendarNotificationRegistrar), never a separately
     * invented local timestamp.
     */
    private function registrationNotExpired(ExternalCalendarConnection $connection): bool
    {
        return $connection->notification_expires_at !== null && $connection->notification_expires_at->isFuture();
    }
}
