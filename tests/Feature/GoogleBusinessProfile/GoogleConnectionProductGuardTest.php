<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileCandidateTokenSigner;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileEnumerator;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use App\DTO\GoogleBusinessProfile\GoogleLocationCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * SEO Contract 18 §7.3 / §15.B — BEHAVIOURAL proof that Business Profile
 * code refuses a Search Console connection row.
 *
 * The product discriminator scoped the LOOKUPS, which protects every path
 * that starts by finding a connection. It did not protect the paths that
 * start with a connection the caller already holds: revoke(), disconnect(),
 * accessTokenFor() and their siblings took whatever row they were handed.
 * Because both products share one table and one row shape, a Search Console
 * row passed to any of them would have had its refresh token wiped, its
 * state transitioned, or its grant spent against Google — silently, and with
 * no query a source scan could flag, because those methods never query the
 * table at all.
 *
 * Every test below therefore asserts the same three things: the call is
 * refused, the row is byte-for-byte unchanged (token included), and no
 * provider call was made.
 *
 * No Search Console feature exists yet, so each `search_console` row here is
 * a bare directly-inserted fixture — the shared authority stack is exercised
 * exactly as a future Search Console connection would exercise it, with no
 * Search Console product code added.
 *
 * NOTHING HERE TALKS TO GOOGLE: the read client is the in-repo fake, and
 * each test additionally asserts the fake recorded zero calls.
 */
class GoogleConnectionProductGuardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGoogleBusinessProfileFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeGoogleClient();
    }

    private function manager(): GoogleBusinessProfileConnectionManager
    {
        return app(GoogleBusinessProfileConnectionManager::class);
    }

    /** A Search Console row with a real-looking stored grant. */
    private function searchConsoleConnection(Business $business): BusinessGoogleConnection
    {
        return $this->activeConnection($business, [
            'product' => GoogleConnectionProduct::SearchConsole,
            'google_account_email' => 'search-console-owner@example.test',
            'refresh_token_encrypted' => 'search-console-refresh-token',
            'granted_scopes' => 'https://www.googleapis.com/auth/webmasters.readonly',
        ]);
    }

    /** Every column of the row, as persisted. */
    private function rowSnapshot(int $connectionId): array
    {
        return (array) DB::table('business_google_connections')->where('id', $connectionId)->first();
    }

    /**
     * Runs $call against a Search Console row and proves it was refused
     * without touching that row, its token, or Google.
     */
    private function assertRefusedAndUntouched(BusinessGoogleConnection $connection, callable $call): void
    {
        $before = $this->rowSnapshot((int) $connection->id);
        $operationsBefore = BusinessGoogleOperation::query()->count();

        try {
            $call();
            $this->fail('A Business Profile operation accepted a search_console connection.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('business_profile', $e->getMessage());
        }

        $after = $this->rowSnapshot((int) $connection->id);

        $this->assertSame($before, $after, 'The search_console row must be byte-for-byte unchanged.');
        $this->assertSame(
            'search-console-refresh-token',
            $this->decryptedToken((int) $connection->id),
            'The Search Console refresh token must survive untouched.',
        );
        $this->assertSame(
            GoogleConnectionState::Active->value,
            (string) $after['state'],
            'The Search Console connection must still be active.',
        );
        $this->assertSame([], $this->fakeGoogle->calls, 'No provider call may be made for a refused product.');
        $this->assertSame(
            $operationsBefore,
            BusinessGoogleOperation::query()->count(),
            'A refused call must not open a ledger operation.',
        );
    }

    /**
     * The stored token, read back through the model so the assertion covers
     * what the application would actually use, not just the ciphertext.
     */
    private function decryptedToken(int $connectionId): ?string
    {
        $connection = BusinessGoogleConnection::query()->find($connectionId);

        return $connection?->refresh_token_encrypted;
    }

    // -----------------------------------------------------------------
    // The three the review named explicitly.
    // -----------------------------------------------------------------

    public function test_revoke_refuses_a_search_console_connection_and_leaves_its_token_intact(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched($connection, fn () => $this->manager()->revoke($connection));

        $this->assertNull($this->rowSnapshot((int) $connection->id)['revoked_at'], 'revoke() must not have stamped the row.');
    }

    public function test_disconnect_refuses_a_search_console_connection_and_destroys_nothing(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched($connection, fn () => $this->manager()->disconnect($connection, null));

        $row = $this->rowSnapshot((int) $connection->id);
        $this->assertNull($row['disconnected_at']);
        $this->assertNotNull($row['google_account_email'], 'disconnect() must not have cleared the account email.');
    }

    public function test_access_token_for_refuses_a_search_console_connection_and_spends_no_grant(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched($connection, fn () => $this->manager()->accessTokenFor($connection));

        $this->assertSame(
            0,
            $this->fakeGoogle->callCount('exchangeRefreshToken'),
            'The Search Console refresh token must never be exchanged by GBP code.',
        );
    }

    // -----------------------------------------------------------------
    // ...and every other connection-accepting method on the manager.
    // -----------------------------------------------------------------

    public function test_complete_connect_refuses_a_search_console_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched(
            $connection,
            fn () => $this->manager()->completeConnect($connection, 'an-authorization-code', 1),
        );

        $this->assertSame(0, $this->fakeGoogle->callCount('exchangeAuthorizationCode'));
    }

    public function test_claim_refresh_refuses_a_search_console_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched($connection, fn () => $this->manager()->claimRefresh($connection));

        $this->assertNull($this->rowSnapshot((int) $connection->id)['refresh_claimed_at']);
    }

    public function test_release_refresh_claim_refuses_a_search_console_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched($connection, fn () => $this->manager()->releaseRefreshClaim($connection));
    }

    public function test_mark_failure_refuses_a_search_console_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched(
            $connection,
            fn () => $this->manager()->markFailure($connection, GoogleBusinessProfileProviderException::invalidGrant()),
        );

        $this->assertNull(
            $this->rowSnapshot((int) $connection->id)['failure_classification'],
            'A GBP failure must never be recorded against a Search Console row.',
        );
    }

    public function test_attempt_belongs_to_actor_refuses_a_search_console_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        // Refused rather than answering true/false: a predicate that
        // answered would let a caller proceed on a foreign row.
        $this->assertRefusedAndUntouched(
            $connection,
            fn () => $this->manager()->attemptBelongsToActor($connection, 1),
        );
    }

    // -----------------------------------------------------------------
    // The other Business-Profile services that accept a connection.
    // -----------------------------------------------------------------

    public function test_the_enumerator_refuses_a_search_console_connection_before_opening_a_ledger_row(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);

        $this->assertRefusedAndUntouched(
            $connection,
            fn () => app(GoogleBusinessProfileEnumerator::class)->enumerate($business, $connection, null),
        );

        $this->assertSame(0, $this->fakeGoogle->callCount('listAccounts'));
    }

    public function test_the_candidate_token_signer_refuses_a_search_console_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $connection = $this->searchConsoleConnection($business);
        $signer = app(GoogleBusinessProfileCandidateTokenSigner::class);

        $candidate = new GoogleLocationCandidate(
            resourceName: 'locations/1',
            accountResourceName: 'accounts/1',
            title: 'A Location',
            storeCode: null,
            localityHint: null,
            regionCode: null,
        );

        $this->assertRefusedAndUntouched(
            $connection,
            fn () => $signer->issue($business, $connection, 1, $candidate),
        );

        $this->assertRefusedAndUntouched(
            $connection,
            fn () => $signer->verify('a.token', $business, $connection, 1),
        );
    }

    // -----------------------------------------------------------------
    // The guard must not disturb the product it IS for.
    // -----------------------------------------------------------------

    public function test_a_business_profile_connection_is_still_operated_on_normally(): void
    {
        [, $business] = $this->entitledTenant();

        // The Search Console row exists alongside and must be ignored.
        $searchConsole = $this->searchConsoleConnection($business);
        $businessProfile = $this->activeConnection($business, [
            'product' => GoogleConnectionProduct::BusinessProfile,
        ]);

        $searchConsoleBefore = $this->rowSnapshot((int) $searchConsole->id);

        $this->manager()->revoke($businessProfile);

        $this->assertSame(
            GoogleConnectionState::Revoked->value,
            (string) $this->rowSnapshot((int) $businessProfile->id)['state'],
            'The Business Profile connection must still revoke normally.',
        );
        $this->assertSame(
            $searchConsoleBefore,
            $this->rowSnapshot((int) $searchConsole->id),
            'Revoking Business Profile must leave the Search Console row untouched.',
        );
    }
}
