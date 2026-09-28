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
 * AUTHENTICITY IS VERIFIED FIRST, before any parsing, any content-keyed
 * database read, and any side effect (§11) — mirroring
 * AgencyProspectingWebhookController's URL-embedded HMAC token precedent,
 * the closest one this repository has: the connection uid and an
 * application-key-backed token (ExternalCalendarWebhookToken, verified with
 * zero database reads) both travel in the URL itself, which is the exact
 * notification URL registered with each provider for this one connection.
 *
 * A verified webhook is a TRIGGER, never a payload — the request body is
 * never trusted as event data (§11). It causes exactly one authenticated
 * pull via ExternalCalendarSyncService, using the connection's own stored
 * credentials; only that pull's result may change busy blocks.
 *
 * Every response is `response('', ...)`, matching this codebase's other
 * webhook controllers: a failed authenticity check and a webhook naming an
 * unknown/disconnected connection are BOTH indistinguishable failures with
 * no side effect and no information disclosure.
 */
class ExternalCalendarWebhookController extends Controller
{
    public function __construct(private readonly ExternalCalendarSyncService $sync)
    {
    }

    public function google(Request $request, string $connectionUid, string $token): Response
    {
        return $this->handle($request, $connectionUid, $token, ExternalCalendarProvider::Google);
    }

    public function outlook(Request $request, string $connectionUid, string $token): Response
    {
        return $this->handle($request, $connectionUid, $token, ExternalCalendarProvider::Outlook);
    }

    private function handle(Request $request, string $connectionUid, string $token, ExternalCalendarProvider $provider): Response
    {
        // Verified FIRST — a pure computation over the URL's own segments
        // and the application key, zero database reads either way. A
        // missing, malformed or forged token is rejected identically to an
        // unknown channel: no side effect, no information disclosure.
        if (! ExternalCalendarWebhookToken::isValid($connectionUid, $provider->value, $token)) {
            return response('', 404);
        }

        // Microsoft Graph's subscription-validation handshake: answered
        // AFTER authenticity so an attacker cannot use it to probe for a
        // valid connection/token pair without already holding one.
        $validationToken = $request->query('validationToken');

        if (is_string($validationToken) && $validationToken !== '') {
            return response($validationToken, 200)->header('Content-Type', 'text/plain');
        }

        $connection = ExternalCalendarConnection::query()
            ->where('uid', $connectionUid)
            ->where('provider', $provider->value)
            ->first();

        // A webhook resolving to an unknown, disconnected or revoked
        // connection is discarded — provider-safe 200, no distinguishing
        // information, no side effect.
        if ($connection === null || $connection->state !== ExternalCalendarConnectionState::Active) {
            return response('', 200);
        }

        // The request body/headers supply NO event data here by design —
        // only the trigger to pull, authenticated with the connection's own
        // stored credentials (§11).
        $this->sync->syncConnection($connection);

        return response('', 200);
    }
}
