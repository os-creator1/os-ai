<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager;
use App\Models\ExternalCalendarConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §5.5/§12.F — the connection lifecycle, its
 * pending-slot rules, the OAuth callback's security order, and the "no
 * access token is ever persisted anywhere" invariant.
 *
 * Covers the task's explicit security requirements: an OAuth callback
 * cannot bind another User; expired/unknown state fails closed; a provider
 * account identity cannot be supplied by browser input to target another
 * User; a revoked connection is handled safely; disconnect prevents further
 * sync (proven here at the state level; ExternalCalendarSyncTest proves the
 * sync-service no-op); no token appears in a rendered view or a
 * serialized model.
 */
class ExternalCalendarConnectionTest extends TestCase
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

    // -----------------------------------------------------------------
    // Pending-connection lifecycle (§5.5)
    // -----------------------------------------------------------------

    public function test_beginning_a_connection_creates_a_pending_row_and_redirects_to_the_provider(): void
    {
        $this->authenticate($this->staff);

        $response = $this->post(route('customer.calendar-connection.connect', ['google']));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://fake-provider.test/authorize', $response->headers->get('Location'));

        $connection = ExternalCalendarConnection::where('user_id', $this->staff->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame(ExternalCalendarConnectionState::Pending, $connection->state);
        $this->assertNotNull($connection->oauth_state_nonce);
        $this->assertNotNull($connection->oauth_state_expires_at);
    }

    public function test_a_second_simultaneous_initiation_is_refused_while_pending(): void
    {
        $this->authenticate($this->staff);
        $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'existing-nonce',
            'oauth_state_expires_at' => now()->addMinutes(5),
        ]);

        $this->post(route('customer.calendar-connection.connect', ['outlook']));

        $this->assertSame(1, ExternalCalendarConnection::where('user_id', $this->staff->id)->count());
        $this->assertSame(
            ExternalCalendarProvider::Google,
            ExternalCalendarConnection::where('user_id', $this->staff->id)->first()->provider
        );
    }

    public function test_a_second_simultaneous_initiation_is_refused_while_active(): void
    {
        $this->authenticate($this->staff);
        $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $this->post(route('customer.calendar-connection.connect', ['outlook']));

        $this->assertSame(1, ExternalCalendarConnection::where('user_id', $this->staff->id)->count());
        $this->assertSame(
            ExternalCalendarConnectionState::Active,
            ExternalCalendarConnection::where('user_id', $this->staff->id)->first()->state
        );
    }

    public function test_an_expired_pending_attempt_is_released_and_does_not_block_the_user_forever(): void
    {
        $this->authenticate($this->staff);
        $stale = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'stale-nonce',
            'oauth_state_expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->post(route('customer.calendar-connection.connect', ['outlook']));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://fake-provider.test/authorize', $response->headers->get('Location'));

        $this->assertSame(ExternalCalendarConnectionState::Disconnected, $stale->fresh()->state);

        $fresh = ExternalCalendarConnection::where('user_id', $this->staff->id)
            ->where('state', ExternalCalendarConnectionState::Pending->value)
            ->first();
        $this->assertNotNull($fresh);
        $this->assertSame(ExternalCalendarProvider::Outlook, $fresh->provider);
    }

    public function test_two_live_pending_connections_for_one_user_cannot_coexist_at_the_database_level(): void
    {
        $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google);

        $this->expectException(QueryException::class);

        DB::table('external_calendar_connections')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->staff->id,
            'provider' => ExternalCalendarProvider::Outlook->value,
            'state' => ExternalCalendarConnectionState::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_successful_callback_activates_the_connection_and_encrypts_the_refresh_token(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'n1',
            'oauth_state_expires_at' => now()->addMinutes(5),
        ]);
        $state = $this->signedStateFor($connection);

        $response = $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'google',
            'state' => $state,
            'code' => 'auth-code-123',
        ]));

        $response->assertRedirect(route('customer.calendar-connection.show'));

        $fresh = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Active, $fresh->state);
        $this->assertNotNull($fresh->refresh_token_encrypted);
        $this->assertSame('fake-refresh-token-auth-code-123', $fresh->refresh_token_encrypted);
        $this->assertNull($fresh->oauth_state_nonce);
        $this->assertNull($fresh->sync_cursor);

        // The encrypted cast round-trips; the RAW column is never the plaintext.
        $raw = DB::table('external_calendar_connections')->where('id', $fresh->id)->value('refresh_token_encrypted');
        $this->assertStringNotContainsString('fake-refresh-token-auth-code-123', (string) $raw);
    }

    public function test_a_callback_with_no_refresh_token_fails_closed_and_stays_pending(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'n2',
            'oauth_state_expires_at' => now()->addMinutes(5),
        ]);
        $state = $this->signedStateFor($connection);

        $this->fakeGoogle->codeExchangeGrant = new \App\DTO\Calendar\ExternalCalendarTokenGrant(
            accessToken: 'access-only',
            refreshToken: null,
            grantedScopes: null,
            externalAccountEmail: null,
        );

        $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'google',
            'state' => $state,
            'code' => 'auth-code-456',
        ]));

        $fresh = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Pending, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        // The nonce was already consumed before the exchange — the pending
        // row is not reusable for a retry without a fresh connect().
        $this->assertNull($fresh->oauth_state_nonce);
    }

    // -----------------------------------------------------------------
    // "OAuth callback cannot bind another User" / "provider account
    // identity cannot be supplied by browser input to target another User"
    // -----------------------------------------------------------------

    public function test_the_callback_actor_must_be_the_user_who_initiated_the_attempt(): void
    {
        $connection = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'n3',
            'oauth_state_expires_at' => now()->addMinutes(5),
        ]);
        $state = $this->signedStateFor($connection);

        // A DIFFERENT authenticated user tries to complete the staff
        // member's OAuth attempt using the correctly signed state.
        $this->authenticate($this->admin);

        $response = $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'google',
            'state' => $state,
            'code' => 'stolen-code',
        ]));

        $response->assertNotFound();

        $fresh = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Pending, $fresh->state);
        // The nonce must survive an attacker's attempt — the rightful
        // actor's own later callback must still work.
        $this->assertNotNull($fresh->oauth_state_nonce);
        $this->assertSame(0, $this->fakeGoogle->codeExchangeCalls);
    }

    public function test_an_expired_signed_state_fails_closed(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'n4',
            'oauth_state_expires_at' => now()->subSecond(),
        ]);

        // Sign a state whose own embedded expiry is already in the past —
        // verify() must reject it purely on the payload, before any DB read.
        $expiredState = $this->buildExpiredState($connection);

        $response = $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'google',
            'state' => $expiredState,
            'code' => 'irrelevant',
        ]));

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->codeExchangeCalls);
    }

    public function test_an_unknown_or_tampered_state_fails_closed(): void
    {
        $this->authenticate($this->staff);

        $response = $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'google',
            'state' => 'not-a-real-signed-state',
            'code' => 'irrelevant',
        ]));

        $response->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->codeExchangeCalls);
    }

    public function test_a_state_signed_for_one_provider_cannot_complete_the_other_providers_callback(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createPendingConnection($this->staff, ExternalCalendarProvider::Google, [
            'oauth_state_nonce' => 'n5',
            'oauth_state_expires_at' => now()->addMinutes(5),
        ]);
        $state = $this->signedStateFor($connection);

        $response = $this->get(route('customer.calendar-connection.oauth.callback', [
            'provider' => 'outlook',
            'state' => $state,
            'code' => 'irrelevant',
        ]));

        $response->assertNotFound();
        $this->assertSame(ExternalCalendarConnectionState::Pending, $connection->fresh()->state);
    }

    // -----------------------------------------------------------------
    // Disconnect / revoke safety
    // -----------------------------------------------------------------

    public function test_disconnect_destroys_credentials_and_purges_the_busy_block_cache(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'cursor-1']);

        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connection->id,
            'provider_event_id' => 'evt-1',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post(route('customer.calendar-connection.disconnect'));

        $fresh = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Disconnected, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertNull($fresh->sync_cursor);
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
    }

    public function test_a_revoked_connection_is_handled_safely(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connection->id,
            'provider_event_id' => 'evt-revoke',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeGoogle->throwOnRefreshExchange = ExternalCalendarProviderException::invalidGrant();

        $exception = null;
        try {
            app(ExternalCalendarConnectionManager::class)->accessTokenFor($connection);
        } catch (ExternalCalendarProviderException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertTrue($exception->isRevocation());

        $fresh = $connection->fresh();
        $this->assertSame(ExternalCalendarConnectionState::Revoked, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertSame(0, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
    }

    // -----------------------------------------------------------------
    // No token/secret ever appears in logs, exceptions, views, or a
    // serialized model.
    // -----------------------------------------------------------------

    public function test_no_access_token_column_exists_and_the_refresh_token_is_hidden_from_serialization(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('external_calendar_connections', 'access_token'));

        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $array = $connection->toArray();
        $this->assertArrayNotHasKey('refresh_token_encrypted', $array);
        $this->assertArrayNotHasKey('oauth_state_nonce', $array);

        $json = $connection->toJson();
        $this->assertStringNotContainsString('refresh_token_encrypted', $json);
    }

    public function test_the_connection_settings_view_never_renders_the_refresh_token(): void
    {
        $this->authenticate($this->staff);
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $rawEncrypted = DB::table('external_calendar_connections')->where('id', $connection->id)->value('refresh_token_encrypted');

        $response = $this->get(route('customer.calendar-connection.show'));

        $response->assertOk();
        $response->assertDontSee('seeded-refresh-token', false);
        $response->assertDontSee($rawEncrypted, false);
    }

    public function test_provider_exception_messages_are_a_closed_classification_never_a_credential_value(): void
    {
        // Every constructor is a named classification, not a raw provider
        // message — the exception's ->getMessage() is always exactly one
        // of these closed strings, which is what makes it safe to log.
        $this->assertSame('invalid_grant', ExternalCalendarProviderException::invalidGrant()->getMessage());
        $this->assertSame('rate_limited', ExternalCalendarProviderException::rateLimited()->getMessage());
        $this->assertSame('cursor_invalid', ExternalCalendarProviderException::cursorInvalid()->getMessage());
    }

    private function buildExpiredState(ExternalCalendarConnection $connection): string
    {
        // Build the payload directly (bypassing issue(), which always signs
        // a future expiry) to exercise verify()'s own expiry check.
        $payload = [
            'u' => (int) $connection->user_id,
            'p' => $connection->provider->value,
            'n' => (string) $connection->oauth_state_nonce,
            'e' => now()->subMinute()->getTimestamp(),
        ];

        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $key = str_starts_with((string) config('app.key'), 'base64:')
            ? base64_decode(substr((string) config('app.key'), 7))
            : (string) config('app.key');
        $signature = hash_hmac('sha256', $encoded, $key);

        return $encoded . '.' . $signature;
    }
}
