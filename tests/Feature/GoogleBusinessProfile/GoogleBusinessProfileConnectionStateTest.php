<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConcurrencyException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\BusinessGoogleConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * CORRECTION PASS ITEMS 2, 3 and 4 — actor binding, genuine optimistic
 * locking, and the refresh-token state invariant.
 *
 * These use TWO SEPARATELY AUTHORIZED MANAGE USERS IN THE SAME BUSINESS.
 * Cross-tenant tests cannot reach these failures: both actors legitimately
 * pass the whole §15 chain, so only the per-attempt actor binding
 * distinguishes them.
 */
class GoogleBusinessProfileConnectionStateTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    // -----------------------------------------------------------------
    // Item 2 — every OAuth attempt is bound to its initiating actor
    // -----------------------------------------------------------------

    /**
     * Actor A begins; actor B presents A's state. B is refused, ZERO token
     * exchange happens, and — critically — A's nonce is NOT consumed, so
     * B cannot burn A's live attempt.
     */
    public function test_a_second_actor_cannot_complete_another_actors_attempt(): void
    {
        [$owner, $business, $workspace] = $this->entitledTenant();
        $second = $this->addSecondManageUser($workspace);

        // Actor A initiates.
        $this->authenticateAsCustomer($owner);
        $response = $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));
        $response->assertRedirect();

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $nonceAfterA = $connection->oauth_state_nonce;
        $state = $this->stateFromRedirect($response);

        $this->assertSame($owner->user_id, (int) $connection->connected_by_user_id);

        // Actor B — a legitimate manage user of the SAME Business — tries
        // to complete it.
        $this->authenticateAsUser($second);
        $this->getCallback($state)->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        // A's nonce survives untouched.
        $connection->refresh();
        $this->assertSame($nonceAfterA, $connection->oauth_state_nonce);
        $this->assertSame(GoogleConnectionState::Pending, $connection->state);

        // ...and A can still finish.
        $this->authenticateAsCustomer($owner);
        $this->getCallback($state)->assertRedirect();

        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
        $this->assertSame(GoogleConnectionState::Active, $connection->refresh()->state);
    }

    /**
     * A newer initiation replaces the previous nonce AND the previous
     * actor binding together, so the older actor's state dies with it.
     */
    public function test_a_newer_attempt_invalidates_the_older_actors_state(): void
    {
        [$owner, $business, $workspace] = $this->entitledTenant();
        $second = $this->addSecondManageUser($workspace);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $olderState = $this->stateFor($connection);
        $olderNonce = $connection->oauth_state_nonce;

        // Actor B starts a NEWER attempt.
        $this->authenticateAsUser($second);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))->assertRedirect();

        $connection->refresh();

        $this->assertNotSame($olderNonce, $connection->oauth_state_nonce, 'A new attempt must replace the nonce.');
        $this->assertSame($second->id, (int) $connection->connected_by_user_id, 'A new attempt must re-stamp the actor.');

        // The older actor's older state is now dead.
        $this->authenticateAsCustomer($owner);
        $this->getCallback($olderState)->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));

        // The newer actor's state works.
        $this->authenticateAsUser($second);
        $this->getCallback($this->stateFor($connection))->assertRedirect();
        $this->assertSame(1, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    /**
     * The actor is re-stamped even when the connection is ALREADY pending
     * — the case the previous implementation skipped entirely.
     */
    public function test_the_actor_is_restamped_on_an_already_pending_connection(): void
    {
        [$owner, $business, $workspace] = $this->entitledTenant();
        $second = $this->addSecondManageUser($workspace);

        BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'state' => GoogleConnectionState::Pending,
            'connected_by_user_id' => $owner->user_id,
        ]);

        $this->authenticateAsUser($second);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))->assertRedirect();

        $this->assertSame(
            $second->id,
            (int) BusinessGoogleConnection::query()->where('business_id', $business->id)->value('connected_by_user_id'),
        );
    }

    /** An actor mismatch never authenticates anyone or creates a User. */
    public function test_an_actor_mismatch_changes_no_identity(): void
    {
        [$owner, $business, $workspace] = $this->entitledTenant();
        $second = $this->addSecondManageUser($workspace);

        $this->authenticateAsCustomer($owner);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));
        $state = $this->stateFor(BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail());

        $this->authenticateAsUser($second);

        $usersBefore = DB::table('users')->count();
        $verifiedBefore = DB::table('users')->where('id', $second->id)->value('email_verified_at');

        $this->getCallback($state)->assertNotFound();

        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->assertSame($verifiedBefore, DB::table('users')->where('id', $second->id)->value('email_verified_at'));
        $this->assertSame($second->id, auth()->id());
    }

    // -----------------------------------------------------------------
    // Item 3 — lock_version is genuinely optimistic
    // -----------------------------------------------------------------

    /**
     * Two writers holding the SAME version cannot both succeed. The loser
     * gets GoogleBusinessProfileConcurrencyException, and the winner's
     * state stands.
     */
    public function test_two_writers_on_the_same_version_cannot_both_succeed(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->activeConnection($business);

        // Two independent in-memory models of the SAME row, both at
        // version 0 — exactly the stale-model race.
        $writerA = BusinessGoogleConnection::query()->findOrFail($connection->id);
        $writerB = BusinessGoogleConnection::query()->findOrFail($connection->id);

        $this->assertSame(0, (int) $writerA->lock_version);
        $this->assertSame(0, (int) $writerB->lock_version);

        $manager = app(GoogleBusinessProfileConnectionManager::class);

        // Writer A wins.
        $manager->revoke($writerA);
        $this->assertSame(GoogleConnectionState::Revoked, $writerA->refresh()->state);
        $this->assertSame(1, (int) $writerA->lock_version);

        // Writer B, still holding version 0, loses.
        $this->expectException(GoogleBusinessProfileConcurrencyException::class);
        $manager->disconnect($writerB, null);
    }

    /**
     * A lost race emits NO success event and NO successful ledger outcome:
     * the connection is left exactly as the winner made it.
     */
    public function test_a_lost_race_leaves_no_successful_trace(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->activeConnection($business);

        $writerA = BusinessGoogleConnection::query()->findOrFail($connection->id);
        $writerB = BusinessGoogleConnection::query()->findOrFail($connection->id);

        $manager = app(GoogleBusinessProfileConnectionManager::class);
        $manager->revoke($writerA);

        $ledgerBefore = DB::table('business_google_operations')->count();

        try {
            $manager->disconnect($writerB, null);
            $this->fail('The stale writer should have lost the race.');
        } catch (GoogleBusinessProfileConcurrencyException) {
            // expected
        }

        $this->assertSame($ledgerBefore, DB::table('business_google_operations')->count(), 'A lost race must write no ledger outcome.');
        $this->assertDatabaseHas('business_google_connections', [
            'id' => $connection->id,
            'state' => 'revoked',
        ]);
        $this->assertDatabaseMissing('business_google_operations', ['operation_type' => 'disconnected']);
    }

    /**
     * Item 3 — token storage and the transition to active are ONE
     * conditional update, so a stale writer cannot store a token either.
     */
    public function test_token_storage_and_activation_are_one_conditional_update(): void
    {
        [$customer, $business] = $this->entitledTenant();

        $connection = BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'state' => GoogleConnectionState::Pending,
            'connected_by_user_id' => $customer->user_id,
        ]);

        $stale = BusinessGoogleConnection::query()->findOrFail($connection->id);

        // Someone else advances the row first.
        DB::table('business_google_connections')->where('id', $connection->id)->update(['lock_version' => 5]);

        $this->expectException(GoogleBusinessProfileConcurrencyException::class);

        app(GoogleBusinessProfileConnectionManager::class)->completeConnect($stale, 'auth-code', $customer->user_id);
    }

    /** Every successful transition advances the version by exactly one. */
    public function test_each_successful_transition_increments_the_version(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->activeConnection($business);

        $manager = app(GoogleBusinessProfileConnectionManager::class);

        $this->assertSame(0, (int) $connection->lock_version);

        $manager->revoke($connection);
        $this->assertSame(1, (int) $connection->refresh()->lock_version);

        $manager->disconnect($connection, null);
        $this->assertSame(2, (int) $connection->refresh()->lock_version);
    }

    // -----------------------------------------------------------------
    // Item 4 — the refresh-token state invariant
    // -----------------------------------------------------------------

    /**
     * NO non-active state may retain authorization material. Every
     * transition out of active nulls the token in the same write.
     */
    public function test_no_non_active_state_retains_a_refresh_token(): void
    {
        $manager = app(GoogleBusinessProfileConnectionManager::class);

        // active -> revoked
        [, $revokedBusiness] = $this->entitledTenant();
        $revoked = $this->activeConnection($revokedBusiness);
        $manager->revoke($revoked);
        $this->assertNonActiveHoldsNoToken($revoked->refresh());

        // active -> disconnected
        [, $disconnectedBusiness] = $this->entitledTenant();
        $disconnected = $this->activeConnection($disconnectedBusiness);
        $manager->disconnect($disconnected, null);
        $this->assertNonActiveHoldsNoToken($disconnected->refresh());

        // revoked -> pending (a reconnect initiation)
        [$customer, $pendingBusiness, $pendingWorkspace] = $this->entitledTenant();
        $pending = $this->activeConnection($pendingBusiness, [
            'state' => GoogleConnectionState::Revoked,
            'revoked_at' => now(),
        ]);
        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$pendingWorkspace->uid, $pendingBusiness->uid]));
        $this->assertNonActiveHoldsNoToken($pending->refresh());
    }

    /**
     * THE REVOKED-TOKEN TRAP. A revoked connection is reconnected, and
     * Google returns NO new refresh token. The connection must FAIL CLOSED
     * — it must not go active, and it must never fall back to the token
     * that was already revoked.
     */
    public function test_a_reconnect_without_a_new_refresh_token_fails_closed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();

        // A revoked connection whose old token was destroyed on revoke.
        $connection = $this->activeConnection($business);
        app(GoogleBusinessProfileConnectionManager::class)->revoke($connection);
        $this->assertNull($connection->refresh()->refresh_token_encrypted);

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))->assertRedirect();

        // Google returns an access token but NO refresh token.
        $this->fakeGoogle->refreshTokenToIssue = null;

        $connection->refresh();
        $this->getCallback($this->stateFor($connection))->assertRedirect();

        $connection->refresh();

        $this->assertNotSame(GoogleConnectionState::Active, $connection->state, 'A connection must never activate without a new refresh token.');
        $this->assertSame(GoogleConnectionState::Pending, $connection->state);
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertNull(DB::table('business_google_connections')->where('id', $connection->id)->value('refresh_token_encrypted'));

        $this->assertSame('error', session('status'));
    }

    /** A successful reconnect stores the NEW token and goes active. */
    public function test_a_reconnect_with_a_new_refresh_token_activates(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();

        $connection = $this->activeConnection($business);
        app(GoogleBusinessProfileConnectionManager::class)->revoke($connection);

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]));

        $this->fakeGoogle->refreshTokenToIssue = 'brand-new-token';

        $connection->refresh();
        $this->getCallback($this->stateFor($connection))->assertRedirect();

        $connection->refresh();

        $this->assertSame(GoogleConnectionState::Active, $connection->state);
        $this->assertSame('brand-new-token', $connection->refresh_token_encrypted);
    }

    // -----------------------------------------------------------------

    private function assertNonActiveHoldsNoToken(BusinessGoogleConnection $connection): void
    {
        $this->assertNotSame(GoogleConnectionState::Active, $connection->state);
        $this->assertNull($connection->refresh_token_encrypted, $connection->state->value . ' must hold no refresh token.');
        $this->assertNull($connection->granted_scopes, $connection->state->value . ' must hold no granted scopes.');
        $this->assertNull(
            DB::table('business_google_connections')->where('id', $connection->id)->value('refresh_token_encrypted'),
            $connection->state->value . ' must hold no refresh token in the raw row.',
        );
    }

    private function addSecondManageUser($workspace): User
    {
        $user = $this->createCustomer()->user;

        $this->addMember($workspace, $user, WorkspaceMembershipRole::Admin);

        return $user;
    }

    /**
     * The state THIS connect actually issued, read out of the redirect to
     * Google. Re-issuing one here would overwrite the very nonce under
     * test.
     */
    private function stateFromRedirect($response): string
    {
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertNotEmpty($query['state'] ?? null, 'The connect redirect must carry a signed state.');

        return (string) $query['state'];
    }

    private function stateFor(BusinessGoogleConnection $connection): string
    {
        return app(GoogleOAuthStateSigner::class)->issue($connection);
    }

    private function getCallback(string $state, string $code = 'auth-code'): \Illuminate\Testing\TestResponse
    {
        return $this->get(
            route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE)
            . '?code=' . urlencode($code) . '&state=' . urlencode($state),
        );
    }
}
