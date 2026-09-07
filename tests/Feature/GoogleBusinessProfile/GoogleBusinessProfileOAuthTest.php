<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\BusinessGoogleConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §9 / §32.3 — the OAuth lifecycle and secret
 * handling. Security criteria G-2, G-3, G-4.
 *
 * The recurring assertion in this file is
 * `callCount('exchangeAuthorizationCode') === 0`: contract §9.5 requires
 * that a missing, altered, expired, replayed, foreign-Business or
 * inaccessible state 404s BEFORE ANY TOKEN EXCHANGE.
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

    public function test_connect_creates_a_pending_connection_and_redirects_to_google(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.test/authorize', $response->headers->get('Location'));

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();

        $this->assertSame(GoogleConnectionState::Pending, $connection->state);
        $this->assertNotNull($connection->oauth_state_nonce);
        $this->assertNotNull($connection->oauth_state_expires_at);
        $this->assertNull($connection->refresh_token_encrypted);

        // Contract §9.3 — a brand-new connection forces consent, because
        // Google returns a refresh token only on the first exchange.
        $this->assertTrue($this->fakeGoogle->callsTo('authorizationUrl')[0]['force_consent']);

        // Contract §27 — the ledger row exists before the redirect.
        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'connect_initiated',
        ]);
    }

    /** T-OAUTH-1 — missing state. */
    public function test_callback_without_state_is_not_found_and_exchanges_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->get(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        $this->get(route('customer.workspaces.businesses.gbp.callback', [$workspace->uid, $business->uid]) . '?code=abc')
            ->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /** T-OAUTH-2 — tampered signature. */
    public function test_callback_with_a_tampered_state_is_not_found_and_exchanges_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $state = $this->issueStateFor($business->id);
        $tampered = substr($state, 0, -1) . (str_ends_with($state, 'a') ? 'b' : 'a');

        $this->getCallback($workspace->uid, $business->uid, $tampered)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /** T-OAUTH-3 — expired state. */
    public function test_expired_state_is_not_found_and_exchanges_nothing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $state = $this->issueStateFor($business->id);

        $this->travel(11)->minutes();

        $this->getCallback($workspace->uid, $business->uid, $state)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        $this->travelBack();
    }

    /**
     * T-OAUTH-4 — REPLAY. The first callback succeeds; the second, with
     * the identical state, 404s and performs no second exchange.
     */
    public function test_a_replayed_nonce_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $state = $this->issueStateFor($business->id);

        $this->getCallback($workspace->uid, $business->uid, $state)->assertRedirect();
        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        $this->getCallback($workspace->uid, $business->uid, $state)->assertNotFound();
        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /**
     * T-OAUTH-5 — a state naming a Business the actor cannot access. The
     * state is genuinely signed, but for someone else's Business.
     */
    public function test_state_for_an_inaccessible_business_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();

        $this->authenticateAsCustomer($customer);

        $foreignState = $this->issueStateFor($otherBusiness->id);

        $this->getCallback($workspace->uid, $business->uid, $foreignState)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /**
     * T-OAUTH-6 — a Business identifier supplied by GOOGLE in the callback
     * payload is ignored entirely. Only the signed state selects the
     * Business.
     */
    public function test_a_business_id_supplied_by_google_is_ignored(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        [, $otherBusiness] = $this->entitledTenant();

        $this->authenticateAsCustomer($customer);
        $state = $this->issueStateFor($business->id);

        $url = route('customer.workspaces.businesses.gbp.callback', [$workspace->uid, $business->uid])
            . '?code=auth-code&state=' . urlencode($state)
            . '&business_id=' . $otherBusiness->id
            . '&business=' . $otherBusiness->uid;

        $this->get($url)->assertRedirect();

        // The connection landed on OUR Business, not the one Google named.
        $this->assertDatabaseHas('business_google_connections', [
            'business_id' => $business->id,
            'state' => 'active',
        ]);
        $this->assertDatabaseMissing('business_google_connections', [
            'business_id' => $otherBusiness->id,
            'state' => 'active',
        ]);
    }

    /**
     * T-OAUTH-8 / T-OAUTH-9 / security criterion G-3 — the callback never
     * authenticates, never creates a User, and never touches
     * email_verified_at.
     */
    public function test_callback_never_creates_a_user_or_touches_verification(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $usersBefore = DB::table('users')->count();
        $verifiedBefore = DB::table('users')->where('id', $customer->user_id)->value('email_verified_at');
        $authenticatedIdBefore = auth()->id();

        $state = $this->issueStateFor($business->id);
        $this->getCallback($workspace->uid, $business->uid, $state)->assertRedirect();

        $this->assertSame($usersBefore, DB::table('users')->count(), 'The OAuth callback must never create a User.');
        $this->assertSame($verifiedBefore, DB::table('users')->where('id', $customer->user_id)->value('email_verified_at'));
        $this->assertSame($authenticatedIdBefore, auth()->id());
    }

    /**
     * T-SEC-1 / security criterion G-4 — a RAW database read of the token
     * column must not equal the plaintext.
     */
    public function test_the_refresh_token_is_encrypted_at_rest(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->fakeGoogle->refreshTokenToIssue = 'super-secret-refresh-token';

        $state = $this->issueStateFor($business->id);
        $this->getCallback($workspace->uid, $business->uid, $state)->assertRedirect();

        $raw = DB::table('business_google_connections')->where('business_id', $business->id)->value('refresh_token_encrypted');

        $this->assertNotNull($raw);
        $this->assertNotSame('super-secret-refresh-token', $raw);
        $this->assertStringNotContainsString('super-secret-refresh-token', (string) $raw);

        // ...and the cast still round-trips it for the application.
        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('super-secret-refresh-token', $connection->refresh_token_encrypted);
    }

    /**
     * T-SEC-2 — the token, the authorization code and the raw state appear
     * in no response body and no ledger row.
     */
    public function test_no_secret_reaches_a_response_or_the_ledger(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->fakeGoogle->refreshTokenToIssue = 'super-secret-refresh-token';
        $state = $this->issueStateFor($business->id);

        $this->getCallback($workspace->uid, $business->uid, $state);

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

    /**
     * T-SEC-3 — invalid_grant transitions to revoked with EXACTLY ONE
     * provider attempt: no retry storm.
     */
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
    }

    /**
     * Contract §9.3 — a reconnect of a REVOKED connection forces consent,
     * because a fresh refresh token is needed.
     */
    public function test_reconnecting_a_revoked_connection_forces_consent(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->activeConnection($business, [
            'state' => GoogleConnectionState::Revoked,
            'refresh_token_encrypted' => null,
            'revoked_at' => now(),
        ]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $this->assertTrue($this->fakeGoogle->callsTo('authorizationUrl')[0]['force_consent']);
    }

    /** Contract §13.5 / T-DEL-1 — disconnect destroys authorization material. */
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

        // The bindings went with it.
        $this->assertSame(0, DB::table('business_google_locations')->where('business_id', $business->id)->count());

        // T-AUDIT-3 — the disconnect ledger row SURVIVES the disconnect
        // that deleted the connection (business_id is denormalized with no
        // foreign key, contract §11.3.1).
        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'disconnected',
            'status' => 'succeeded',
        ]);
    }

    // -----------------------------------------------------------------

    private function issueStateFor(int $businessId): string
    {
        $connection = BusinessGoogleConnection::query()->firstOrCreate(
            ['business_id' => $businessId],
            ['state' => GoogleConnectionState::Pending],
        );

        return app(GoogleOAuthStateSigner::class)->issue($connection);
    }

    private function getCallback(string $workspaceUid, string $businessUid, string $state, string $code = 'auth-code'): \Illuminate\Testing\TestResponse
    {
        return $this->get(
            route('customer.workspaces.businesses.gbp.callback', [$workspaceUid, $businessUid])
            . '?code=' . urlencode($code) . '&state=' . urlencode($state),
        );
    }
}
