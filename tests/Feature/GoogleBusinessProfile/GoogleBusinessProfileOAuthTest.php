<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\BusinessGoogleConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §9 / §32.3 — OAuth lifecycle and secret handling.
 * Security criteria G-2, G-3, G-4.
 *
 * CORRECTION PASS ITEM 1 — the callback is now ONE FIXED, TENANT-FREE
 * ROUTE (`customer.gbp.oauth.callback`), because Google matches
 * redirect_uri exactly against a registered URI and a per-tenant path can
 * never be registered. Every test here drives that single URL.
 *
 * CORRECTION PASS ITEM 9 — connect initiation is a CSRF-protected POST,
 * because it mutates connection state, the nonce, actor attribution and
 * the ledger.
 *
 * The recurring assertion remains
 * `callCount('exchangeAuthorizationCode') === 0`: every failure before
 * exchange must produce zero token-exchange calls.
 */
class GoogleBusinessProfileOAuthTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /**
     * Item 1 — the fixed callback exists, carries no tenant parameters, and
     * is exactly what the configured redirect must equal.
     */
    public function test_the_callback_route_is_fixed_and_tenant_free(): void
    {
        $url = route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE);

        $this->assertStringEndsWith('/gbp/oauth/callback', $url);
        $this->assertStringNotContainsString('workspace', $url);
        $this->assertStringNotContainsString('business', parse_url($url, PHP_URL_PATH) === null ? '' : substr((string) parse_url($url, PHP_URL_PATH), 0, -19));

        // It is the URL the config validator demands.
        $this->assertSame($url, app(GoogleBusinessProfileOAuthConfig::class)->expectedCallbackUrl());
    }

    /** Item 9 — a plain navigation GET can no longer create OAuth state. */
    public function test_connect_is_not_reachable_by_get(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        // The application's exception handler renders a method mismatch as
        // 404 rather than 405; either is a refusal. What matters is that
        // the GET creates no OAuth state and makes no provider call.
        $this->assertContains(
            $this->get(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))->getStatusCode(),
            [404, 405],
        );

        $this->assertDatabaseCount('business_google_connections', 0);
        $this->assertSame(0, $this->fakeGoogle->callCount('authorizationUrl'));
    }

    public function test_connect_creates_a_pending_connection_and_redirects_to_google(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.test/authorize', $response->headers->get('Location'));

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();

        $this->assertSame(GoogleConnectionState::Pending, $connection->state);
        $this->assertNotNull($connection->oauth_state_nonce);
        $this->assertNull($connection->refresh_token_encrypted);

        // Item 2 — the initiating actor is recorded on the attempt.
        $this->assertSame($customer->user_id, (int) $connection->connected_by_user_id);

        // Item 4 — consent is ALWAYS forced: every reachable starting state
        // holds no usable refresh token.
        $this->assertTrue($this->fakeGoogle->callsTo('authorizationUrl')[0]['force_consent']);

        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'connect_initiated',
        ]);
    }

    /**
     * Item 4 — there is no "reconnect a healthy connection" path. It is
     * refused with NO state change and NO provider call.
     */
    public function test_connecting_an_already_active_connection_is_refused_without_state_change(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $connection = $this->activeConnection($business);
        $this->authenticateAsCustomer($customer);

        $before = DB::table('business_google_connections')->where('id', $connection->id)->first();

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->assertSame('error', session('status'));
        $this->assertEquals($before, DB::table('business_google_connections')->where('id', $connection->id)->first());
        $this->assertSame(0, $this->fakeGoogle->callCount('authorizationUrl'));
    }

    /** T-OAUTH-1 — missing state. */
    public function test_callback_without_state_is_not_found_and_exchanges_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?code=abc')
            ->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /** T-OAUTH-2 — tampered signature. */
    public function test_callback_with_a_tampered_state_is_not_found_and_exchanges_nothing(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $state = $this->issueStateFor($business->id, $customer->user_id);
        $tampered = substr($state, 0, -1) . (str_ends_with($state, 'a') ? 'b' : 'a');

        $this->getCallback($tampered)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /** T-OAUTH-3 — expired state. */
    public function test_expired_state_is_not_found_and_exchanges_nothing(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $state = $this->issueStateFor($business->id, $customer->user_id);

        $this->travel(11)->minutes();

        $this->getCallback($state)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        $this->travelBack();
    }

    /** T-OAUTH-4 — REPLAY. */
    public function test_a_replayed_nonce_is_rejected(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $state = $this->issueStateFor($business->id, $customer->user_id);

        $this->getCallback($state)->assertRedirect();
        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        $this->getCallback($state)->assertNotFound();
        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /** T-OAUTH-5 — a state naming a Business the actor cannot access. */
    public function test_state_for_an_inaccessible_business_is_not_found(): void
    {
        [$customer] = $this->entitledTenant();
        [$otherCustomer, $otherBusiness] = $this->entitledTenant();

        $foreignState = $this->issueStateFor($otherBusiness->id, $otherCustomer->user_id);

        $this->authenticateAsCustomer($customer);

        $this->getCallback($foreignState)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /**
     * T-OAUTH-6 — a Business identifier supplied by GOOGLE in the callback
     * payload is ignored. Only the signed state selects the Business, and
     * the fixed route has no tenant parameter to spoof in the first place.
     */
    public function test_a_business_id_supplied_by_google_is_ignored(): void
    {
        [$customer, $business] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();

        $this->authenticateAsCustomer($customer);
        $state = $this->issueStateFor($business->id, $customer->user_id);

        $this->get(
            route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE)
            . '?code=auth-code&state=' . urlencode($state)
            . '&business_id=' . $otherBusiness->id
            . '&business=' . $otherBusiness->uid,
        )->assertRedirect();

        $this->assertDatabaseHas('business_google_connections', [
            'business_id' => $business->id,
            'state' => 'active',
        ]);
        $this->assertDatabaseMissing('business_google_connections', [
            'business_id' => $otherBusiness->id,
            'state' => 'active',
        ]);
    }

    /** T-OAUTH-8 / T-OAUTH-9 / G-3. */
    public function test_callback_never_creates_a_user_or_touches_verification(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $usersBefore = DB::table('users')->count();
        $verifiedBefore = DB::table('users')->where('id', $customer->user_id)->value('email_verified_at');
        $authenticatedIdBefore = auth()->id();

        $state = $this->issueStateFor($business->id, $customer->user_id);
        $this->getCallback($state)->assertRedirect();

        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->assertSame($verifiedBefore, DB::table('users')->where('id', $customer->user_id)->value('email_verified_at'));
        $this->assertSame($authenticatedIdBefore, auth()->id());
    }

    /** T-SEC-1 / G-4 — encrypted at rest, and the model round-trips it. */
    public function test_the_refresh_token_is_encrypted_at_rest(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->fakeGoogle->refreshTokenToIssue = 'super-secret-refresh-token';

        $state = $this->issueStateFor($business->id, $customer->user_id);
        $this->getCallback($state)->assertRedirect();

        $raw = DB::table('business_google_connections')->where('business_id', $business->id)->value('refresh_token_encrypted');

        $this->assertNotNull($raw);
        $this->assertNotSame('super-secret-refresh-token', $raw);
        $this->assertStringNotContainsString('super-secret-refresh-token', (string) $raw);

        // Item 3 — the token is written by a query-builder conditional
        // update, so this round trip also proves that write stays
        // compatible with the model's `encrypted` cast.
        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('super-secret-refresh-token', $connection->refresh_token_encrypted);
    }

    /** T-SEC-2 — no secret in any response, view or ledger row. */
    public function test_no_secret_reaches_a_response_or_the_ledger(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->fakeGoogle->refreshTokenToIssue = 'super-secret-refresh-token';
        $state = $this->issueStateFor($business->id, $customer->user_id);

        $this->getCallback($state);

        $overview = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))->getContent();
        $settings = $this->get(route('customer.workspaces.businesses.gbp.settings', [$workspace->uid, $business->uid]))->getContent();

        foreach ([$overview, $settings] as $body) {
            $this->assertStringNotContainsString('super-secret-refresh-token', (string) $body);
            $this->assertStringNotContainsString('auth-code', (string) $body);
            $this->assertStringNotContainsString($state, (string) $body);
        }

        $ledger = json_encode(DB::table('business_google_operations')->get());

        $this->assertStringNotContainsString('super-secret-refresh-token', (string) $ledger);
        $this->assertStringNotContainsString('auth-code', (string) $ledger);
        $this->assertStringNotContainsString($state, (string) $ledger);
    }

    /** T-SEC-3 — invalid_grant revokes with exactly one attempt. */
    public function test_invalid_grant_revokes_without_a_retry_storm(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->authenticateAsCustomer($customer);

        $this->fakeGoogle->failRefreshWith = GoogleBusinessProfileProviderException::invalidGrant();

        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $connection->refresh();

        $this->assertSame(GoogleConnectionState::Revoked, $connection->state);
        $this->assertSame('invalid_grant', $connection->failure_classification);
        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeRefreshToken'));

        // Item 4 — revocation DESTROYS the stored token, so a later
        // reconnect can never reactivate on a token Google already killed.
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertNull(DB::table('business_google_connections')->where('id', $connection->id)->value('refresh_token_encrypted'));
    }

    /** Item 4 — a reconnect from revoked forces consent. */
    public function test_reconnecting_a_revoked_connection_forces_consent(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->activeConnection($business, [
            'state' => GoogleConnectionState::Revoked,
            'refresh_token_encrypted' => null,
            'revoked_at' => now(),
        ]);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->assertTrue($this->fakeGoogle->callsTo('authorizationUrl')[0]['force_consent']);
    }

    /** Contract §13.5 / T-DEL-1. */
    public function test_disconnect_destroys_the_stored_authorization(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);

        \App\Models\BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.disconnect', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $connection->refresh();

        $this->assertSame(GoogleConnectionState::Disconnected, $connection->state);
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertNull($connection->granted_scopes);
        $this->assertNull($connection->google_account_email);
        $this->assertNull(DB::table('business_google_connections')->where('id', $connection->id)->value('refresh_token_encrypted'));

        $this->assertSame(0, DB::table('business_google_locations')->where('business_id', $business->id)->count());

        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'disconnected',
            'status' => 'succeeded',
        ]);
    }

    // -----------------------------------------------------------------

    private function issueStateFor(int $businessId, int $actorUserId): string
    {
        $connection = BusinessGoogleConnection::query()->firstOrCreate(
            ['business_id' => $businessId],
            ['state' => GoogleConnectionState::Pending],
        );

        // Item 2 — the attempt is bound to its initiating actor.
        $connection->forceFill(['connected_by_user_id' => $actorUserId])->save();

        return app(GoogleOAuthStateSigner::class)->issue($connection->refresh());
    }

    private function getCallback(string $state, string $code = 'auth-code'): \Illuminate\Testing\TestResponse
    {
        return $this->get(
            route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE)
            . '?code=' . urlencode($code) . '&state=' . urlencode($state),
        );
    }
}
