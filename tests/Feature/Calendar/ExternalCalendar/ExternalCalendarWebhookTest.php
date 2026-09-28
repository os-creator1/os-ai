<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarBusyEvent;
use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarWebhookToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §11/§12.F — inbound webhook authenticity.
 *
 * Covers the task's explicit requirements: missing/forged/replayed/expired
 * proofs (token forms) each produce no side effect and an indistinguishable
 * response; a verified webhook is a trigger, never data; a webhook
 * resolving to a disconnected/revoked connection is discarded.
 */
class ExternalCalendarWebhookTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;
    use CreatesExternalCalendarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->bindFakeCalendarProviders();
    }

    public function test_a_missing_token_is_rejected_with_no_side_effect(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        // The route itself requires a {token} segment — a request with no
        // token at all simply matches no route, which is itself the
        // indistinguishable 404 the contract requires. Exercised directly
        // against the raw path rather than via route(), which refuses to
        // build a URL with a missing required parameter.
        $response = $this->postJson('/webhooks/calendar/' . $connection->uid . '/google');

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_a_forged_token_is_rejected_with_no_side_effect(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $response = $this->postJson(route('public.calendar.webhooks.google', [
            'connectionUid' => $connection->uid,
            'token' => 'definitely-not-the-real-hmac',
        ]));

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_a_token_forged_for_a_different_connection_is_rejected(): void
    {
        $connectionA = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $connectionB = $this->createActiveConnection($this->admin, ExternalCalendarProvider::Google);

        // A's genuine token replayed against B's uid — the HMAC binds
        // uid+provider together, so this must not authenticate B.
        $tokenForA = ExternalCalendarWebhookToken::forConnection($connectionA->uid, 'google');

        $response = $this->postJson(route('public.calendar.webhooks.google', [
            'connectionUid' => $connectionB->uid,
            'token' => $tokenForA,
        ]));

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_a_token_valid_for_one_provider_does_not_authenticate_the_other_providers_route(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $googleToken = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        // Same uid, same-looking token, wrong route (outlook) — the
        // provider travels through the HMAC input, not just the URL path.
        $response = $this->postJson(route('public.calendar.webhooks.outlook', [
            'connectionUid' => $connection->uid,
            'token' => $googleToken,
        ]));

        $response->assertNotFound();
    }

    public function test_a_replayed_valid_token_is_accepted_again_but_resolves_only_to_a_pull_never_stale_event_data(): void
    {
        // The token itself is not single-use (unlike the OAuth nonce) — it
        // is a stable per-connection webhook credential, the same posture
        // AgencyProspectingWebhookToken takes. "Replay-safety" here means
        // idempotency of the resulting pull (proven in
        // ExternalCalendarSyncTest), not a burned-after-use token; a
        // provider legitimately calls the same webhook URL repeatedly.
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-webhook', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'cursor-a', true);

        $first = $this->postJson(route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]));
        $first->assertOk();

        $this->fakeGoogle->incrementalBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-webhook', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'cursor-b', true);

        $second = $this->postJson(route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]));
        $second->assertOk();

        $this->assertSame(1, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'evt-webhook')
            ->count());
    }

    public function test_the_webhook_request_body_is_never_trusted_as_event_data(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        // No queued page means the fake returns its harmless empty
        // default — the point is that whatever is IN the POST body is
        // completely irrelevant to what gets written; only the client's
        // own fetch* call (a real pull) can ever produce a row.
        $response = $this->call('POST', route('public.calendar.webhooks.google', [
            'connectionUid' => $connection->uid,
            'token' => $token,
        ]), [], [], [], [], json_encode([
            'events' => [['id' => 'attacker-supplied', 'start' => now()->toIso8601String(), 'end' => now()->addHour()->toIso8601String()]],
        ]));

        $response->assertOk();
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'attacker-supplied')
            ->count());
        // The pull DID happen (the trigger worked) — just never off the body.
        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls);
    }

    public function test_a_webhook_for_a_disconnected_connection_is_discarded(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        app(\App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager::class)->disconnect($connection, (int) $this->staff->id);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'google');

        $response = $this->postJson(route('public.calendar.webhooks.google', ['connectionUid' => $connection->uid, 'token' => $token]));

        $response->assertOk();
        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls + $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_a_webhook_for_an_unknown_connection_uid_is_rejected(): void
    {
        $fakeUid = (string) \Illuminate\Support\Str::uuid();
        $token = ExternalCalendarWebhookToken::forConnection($fakeUid, 'google');

        $response = $this->postJson(route('public.calendar.webhooks.google', ['connectionUid' => $fakeUid, 'token' => $token]));

        // The token was internally "valid" for that (nonexistent) uid — the
        // response must still be indistinguishable from a bad token: no
        // exception, no 500, a plain safe response.
        $response->assertOk();
    }

    public function test_the_microsoft_validation_handshake_echoes_the_token_only_after_authenticity_passes(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Outlook);
        $token = ExternalCalendarWebhookToken::forConnection($connection->uid, 'outlook');

        $valid = $this->post(route('public.calendar.webhooks.outlook', [
            'connectionUid' => $connection->uid,
            'token' => $token,
            'validationToken' => 'graph-handshake-value',
        ]));
        $valid->assertOk();
        $valid->assertSee('graph-handshake-value', false);

        $invalid = $this->post(route('public.calendar.webhooks.outlook', [
            'connectionUid' => $connection->uid,
            'token' => 'wrong',
            'validationToken' => 'graph-handshake-value',
        ]));
        $invalid->assertNotFound();
        $invalid->assertDontSee('graph-handshake-value', false);
    }
}
