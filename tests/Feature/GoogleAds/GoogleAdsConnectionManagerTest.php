<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsConcurrencyException;
use App\Exceptions\GoogleAds\GoogleAdsConfigurationException;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §3 / D2 — the Ads connection state machine on
 * the shared `business_google_connections` authority, strictly product-scoped.
 */
class GoogleAdsConnectionManagerTest extends TestCase
{
    use CreatesGoogleAdsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeAds();
    }

    private function manager(): GoogleAdsConnectionManager
    {
        return app(GoogleAdsConnectionManager::class);
    }

    private function adsRow(int $businessId): ?BusinessGoogleConnection
    {
        return BusinessGoogleConnection::query()
            ->where('business_id', $businessId)
            ->where('product', 'google_ads')
            ->first();
    }

    private function operation(int $businessId, GoogleOperationType $type): ?BusinessGoogleOperation
    {
        return BusinessGoogleOperation::query()
            ->where('business_id', $businessId)
            ->where('operation_type', $type->value)
            ->latest('id')
            ->first();
    }

    // ---------------------------------------------------------------
    // beginConnect
    // ---------------------------------------------------------------

    public function test_begin_connect_creates_a_pending_google_ads_row_bound_to_the_actor(): void
    {
        [$customer, $business] = $this->adsTenant();

        $url = $this->manager()->beginConnect($business, (int) $customer->user_id);

        $row = $this->adsRow($business->id);
        $this->assertNotNull($row);
        $this->assertSame(GoogleConnectionProduct::GoogleAds, $row->product);
        $this->assertSame(GoogleConnectionState::Pending, $row->state);
        $this->assertSame((int) $customer->user_id, (int) $row->connected_by_user_id);
        $this->assertNotNull($row->oauth_state_nonce);
        $this->assertNull($row->refresh_token_encrypted);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('false', $query['include_granted_scopes']);
        $this->assertSame('https://www.googleapis.com/auth/adwords', $query['scope']);

        // The signed state carries the PRODUCT and the Business, read from the row itself.
        $payload = app(GoogleOAuthStateSigner::class)->verify($query['state']);
        $this->assertSame('google_ads', $payload['p']);
        $this->assertSame((int) $business->id, $payload['b']);
        $this->assertSame($row->oauth_state_nonce, $payload['n']);

        $this->assertSame(
            GoogleOperationStatus::Pending,
            $this->operation($business->id, GoogleOperationType::ConnectInitiated)->status,
        );
    }

    public function test_begin_connect_never_touches_another_products_row(): void
    {
        [$customer, $business] = $this->adsTenant();

        $gbp = BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'product' => GoogleConnectionProduct::BusinessProfile,
            'state' => GoogleConnectionState::Active,
            'refresh_token_encrypted' => 'gbp-refresh-token',
        ]);

        $this->manager()->beginConnect($business, (int) $customer->user_id);

        $fresh = $gbp->fresh();
        $this->assertSame(GoogleConnectionState::Active, $fresh->state);
        $this->assertSame('gbp-refresh-token', $fresh->refresh_token_encrypted);
        $this->assertNull($fresh->oauth_state_nonce);
        $this->assertSame(2, BusinessGoogleConnection::where('business_id', $business->id)->count());
        $this->assertNull($this->manager()->findForBusiness($business)->refresh_token_encrypted);
    }

    public function test_find_for_business_only_sees_the_ads_product(): void
    {
        [, $business] = $this->adsTenant();

        BusinessGoogleConnection::create([
            'business_id' => $business->id,
            'product' => GoogleConnectionProduct::BusinessProfile,
            'state' => GoogleConnectionState::Active,
        ]);

        $this->assertNull($this->manager()->findForBusiness($business));
    }

    public function test_begin_connect_is_refused_while_active(): void
    {
        [$customer, $business] = $this->adsTenant();
        $this->activeAdsConnection($business);

        $this->expectException(LogicException::class);
        $this->manager()->beginConnect($business, (int) $customer->user_id);
    }

    public function test_a_misconfigured_deployment_leaves_nothing_behind(): void
    {
        [$customer, $business] = $this->adsTenant();
        config(['services.google_ads.client_id' => null]);

        try {
            $this->manager()->beginConnect($business, (int) $customer->user_id);
            $this->fail('expected a configuration failure');
        } catch (GoogleAdsConfigurationException) {
            $this->assertSame(0, BusinessGoogleConnection::where('business_id', $business->id)->count());
            $this->assertSame(0, BusinessGoogleOperation::where('business_id', $business->id)->count());
            $this->assertSame(0, $this->fakeAds->callCount());
        }
    }

    public function test_a_newer_attempt_supersedes_the_older_nonce_and_actor(): void
    {
        [$customer, $business] = $this->adsTenant();
        $second = User::create([
            'first_name' => 'Second', 'last_name' => 'Manager', 'email' => 'second' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        $this->manager()->beginConnect($business, (int) $customer->user_id);
        $firstNonce = $this->adsRow($business->id)->oauth_state_nonce;

        $this->manager()->beginConnect($business, (int) $second->id);
        $row = $this->adsRow($business->id);

        $this->assertNotSame($firstNonce, $row->oauth_state_nonce);
        $this->assertTrue($this->manager()->attemptBelongsToActor($row, (int) $second->id));
        $this->assertFalse($this->manager()->attemptBelongsToActor($row, (int) $customer->user_id));
    }

    // ---------------------------------------------------------------
    // completeConnect
    // ---------------------------------------------------------------

    private function pendingAttempt(): array
    {
        [$customer, $business] = $this->adsTenant();
        $this->manager()->beginConnect($business, (int) $customer->user_id);

        return [$customer, $business, $this->adsRow($business->id)];
    }

    public function test_complete_connect_stores_an_encrypted_refresh_token_and_no_access_token(): void
    {
        [$customer, $business, $row] = $this->pendingAttempt();

        $this->manager()->completeConnect($row, 'auth-code', (int) $customer->user_id);

        $fresh = $this->adsRow($business->id);
        $this->assertSame(GoogleConnectionState::Active, $fresh->state);
        $this->assertSame('fake-refresh-token', $fresh->refresh_token_encrypted, 'the model decrypts');
        $this->assertSame(GoogleConnectionProduct::GoogleAds->scope(), $fresh->granted_scopes);

        $raw = (string) DB::table('business_google_connections')->where('id', $row->id)->value('refresh_token_encrypted');
        $this->assertNotSame('fake-refresh-token', $raw, 'the column holds ciphertext');
        $this->assertStringNotContainsString('fake-refresh-token', $raw);
        $this->assertSame('fake-refresh-token', Crypt::decryptString($raw));

        // No access token in the connection row, nor in any ledger row.
        $this->assertStringNotContainsString('fake-access-token', json_encode(DB::table('business_google_connections')->where('id', $row->id)->first()));
        $this->assertStringNotContainsString('fake-access-token', json_encode(DB::table('business_google_operations')->get()));

        // The serialised model never carries the token.
        $this->assertArrayNotHasKey('refresh_token_encrypted', $fresh->toArray());

        $op = $this->operation($business->id, GoogleOperationType::ConnectCompleted);
        $this->assertSame(GoogleOperationStatus::Succeeded, $op->status);
        $this->assertSame(1, (int) $op->provider_call_count, 'the code exchange is budget-accounted');
    }

    public function test_complete_connect_fails_closed_when_google_returns_no_refresh_token(): void
    {
        [$customer, $business, $row] = $this->pendingAttempt();
        $this->fakeAds->grantNoRefreshToken();

        try {
            $this->manager()->completeConnect($row, 'auth-code', (int) $customer->user_id);
            $this->fail('expected unexpected_response');
        } catch (GoogleAdsProviderException $e) {
            $this->assertSame(GoogleAdsProviderException::UNEXPECTED_RESPONSE, $e->classification);
        }

        $fresh = $this->adsRow($business->id);
        $this->assertSame(GoogleConnectionState::Pending, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertSame(GoogleOperationStatus::Failed, $this->operation($business->id, GoogleOperationType::ConnectCompleted)->status);
    }

    public function test_complete_connect_fails_closed_when_the_adwords_scope_was_not_granted(): void
    {
        [$customer, $business, $row] = $this->pendingAttempt();
        $this->fakeAds->grantScopes('https://www.googleapis.com/auth/business.manage');

        try {
            $this->manager()->completeConnect($row, 'auth-code', (int) $customer->user_id);
            $this->fail('expected access_denied');
        } catch (GoogleAdsProviderException $e) {
            $this->assertSame(GoogleAdsProviderException::ACCESS_DENIED, $e->classification);
        }

        $fresh = $this->adsRow($business->id);
        $this->assertSame(GoogleConnectionState::Pending, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
    }

    public function test_a_space_separated_scope_list_containing_adwords_is_accepted(): void
    {
        [$customer, $business, $row] = $this->pendingAttempt();
        $this->fakeAds->grantScopes('openid https://www.googleapis.com/auth/adwords');

        $this->manager()->completeConnect($row, 'auth-code', (int) $customer->user_id);

        $this->assertSame(GoogleConnectionState::Active, $this->adsRow($business->id)->state);
    }

    public function test_a_rate_limited_code_exchange_is_deferred_not_failed(): void
    {
        [$customer, $business, $row] = $this->pendingAttempt();
        $this->fakeAds->failNext('exchangeAuthorizationCode', GoogleAdsProviderException::rateLimited());

        try {
            $this->manager()->completeConnect($row, 'auth-code', (int) $customer->user_id);
            $this->fail('expected rate_limited');
        } catch (GoogleAdsProviderException $e) {
            $this->assertTrue($e->isDeferrable());
        }

        $this->assertSame(GoogleOperationStatus::Deferred, $this->operation($business->id, GoogleOperationType::ConnectCompleted)->status);
        $this->assertSame(GoogleConnectionState::Pending, $this->adsRow($business->id)->state);
    }

    // ---------------------------------------------------------------
    // accessTokenFor / revoke
    // ---------------------------------------------------------------

    public function test_access_token_is_derived_per_unit_of_work_and_never_persisted(): void
    {
        [, $business] = $this->adsTenant();
        $connection = $this->activeAdsConnection($business);

        $token = $this->inAdsOperation($connection, fn () => $this->manager()->accessTokenFor($connection));

        $this->assertStringStartsWith('fake-access-token-', $token);
        $this->assertNotNull($connection->fresh()->last_refreshed_at);
        $this->assertStringNotContainsString($token, json_encode(DB::table('business_google_connections')->get()));
        $this->assertSame(1, $this->fakeAds->callCount('exchangeRefreshToken'));
    }

    public function test_invalid_grant_revokes_the_connection_and_destroys_the_token(): void
    {
        [, $business] = $this->adsTenant();
        $connection = $this->activeAdsConnection($business);
        $this->fakeAds->failNext('exchangeRefreshToken', GoogleAdsProviderException::invalidGrant());

        try {
            $this->inAdsOperation($connection, fn () => $this->manager()->accessTokenFor($connection));
            $this->fail('expected invalid_grant');
        } catch (GoogleAdsProviderException $e) {
            $this->assertTrue($e->isRevocation());
        }

        $fresh = $connection->fresh();
        $this->assertSame(GoogleConnectionState::Revoked, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertNull($fresh->granted_scopes);
        $this->assertNotNull($fresh->revoked_at);
        $this->assertSame('invalid_grant', $fresh->failure_classification);

        // A revoked connection does not call Google again (no retry storm).
        $calls = $this->fakeAds->callCount();
        try {
            $this->inAdsOperation($fresh, fn () => $this->manager()->accessTokenFor($fresh));
            $this->fail('expected invalid_grant');
        } catch (GoogleAdsProviderException) {
        }
        $this->assertSame($calls, $this->fakeAds->callCount());
    }

    public function test_a_transient_refresh_failure_keeps_the_connection_active(): void
    {
        [, $business] = $this->adsTenant();
        $connection = $this->activeAdsConnection($business);
        $this->fakeAds->failNext('exchangeRefreshToken', GoogleAdsProviderException::rateLimited());

        try {
            $this->inAdsOperation($connection, fn () => $this->manager()->accessTokenFor($connection));
            $this->fail('expected rate_limited');
        } catch (GoogleAdsProviderException) {
        }

        $fresh = $connection->fresh();
        $this->assertSame(GoogleConnectionState::Active, $fresh->state);
        $this->assertSame('plain-ads-refresh-token', $fresh->refresh_token_encrypted);
        $this->assertSame('rate_limited', $fresh->failure_classification);
    }

    public function test_an_inactive_connection_cannot_produce_a_token_and_makes_no_call(): void
    {
        [$customer, $business] = $this->adsTenant();
        $this->manager()->beginConnect($business, (int) $customer->user_id);
        $pending = $this->adsRow($business->id);
        $calls = $this->fakeAds->callCount();

        try {
            $this->manager()->accessTokenFor($pending);
            $this->fail('expected invalid_grant');
        } catch (GoogleAdsProviderException $e) {
            $this->assertTrue($e->isRevocation());
        }

        $this->assertSame($calls, $this->fakeAds->callCount());
    }

    public function test_revoke_is_idempotent(): void
    {
        [, $business] = $this->adsTenant();
        $connection = $this->activeAdsConnection($business);

        $this->manager()->revoke($connection);
        $version = (int) $connection->fresh()->lock_version;
        $this->manager()->revoke($connection->fresh());

        $this->assertSame($version, (int) $connection->fresh()->lock_version);
        $this->assertSame(GoogleConnectionState::Revoked, $connection->fresh()->state);
    }

    // ---------------------------------------------------------------
    // disconnect
    // ---------------------------------------------------------------

    public function test_disconnect_destroys_authorization_writes_a_ledger_row_and_keeps_the_account(): void
    {
        [$customer, $business] = $this->adsTenant();
        $connection = $this->activeAdsConnection($business);
        $account = GoogleAdsAccount::create([
            'business_id' => $business->id,
            'business_google_connection_id' => $connection->id,
            'customer_id' => '1234567890',
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'selected_at' => now(),
        ]);

        $this->manager()->disconnect($connection, (int) $customer->user_id);

        $fresh = $connection->fresh();
        $this->assertSame(GoogleConnectionState::Disconnected, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertNull($fresh->granted_scopes);
        $this->assertNull($fresh->google_account_email);
        $this->assertNull(DB::table('business_google_connections')->where('id', $connection->id)->value('refresh_token_encrypted'));
        $this->assertNotNull($fresh->disconnected_at);

        $op = $this->operation($business->id, GoogleOperationType::Disconnected);
        $this->assertSame(GoogleOperationStatus::Succeeded, $op->status);
        $this->assertSame((int) $customer->user_id, (int) $op->actor_user_id);

        $this->assertNotNull(GoogleAdsAccount::find($account->id), 'the account row is retained for history');
    }

    public function test_a_disconnected_business_can_connect_again_from_a_clean_slate(): void
    {
        [$customer, $business] = $this->adsTenant();
        $connection = $this->activeAdsConnection($business);
        $this->manager()->disconnect($connection, (int) $customer->user_id);

        $this->manager()->beginConnect($business, (int) $customer->user_id);

        $row = $this->adsRow($business->id);
        $this->assertSame(GoogleConnectionState::Pending, $row->state);
        $this->assertNull($row->refresh_token_encrypted);
    }

    // ---------------------------------------------------------------
    // Product guard
    // ---------------------------------------------------------------

    public function test_every_method_refuses_a_row_of_another_product_before_touching_anything(): void
    {
        [$customer, $business] = $this->adsTenant();

        foreach ([GoogleConnectionProduct::BusinessProfile, GoogleConnectionProduct::SearchConsole] as $product) {
            $foreign = BusinessGoogleConnection::create([
                'business_id' => $business->id,
                'product' => $product,
                'state' => GoogleConnectionState::Active,
                'refresh_token_encrypted' => 'foreign-token-' . $product->value,
                'connected_by_user_id' => $customer->user_id,
            ]);

            $attempts = [
                'attemptBelongsToActor' => fn () => $this->manager()->attemptBelongsToActor($foreign, (int) $customer->user_id),
                'completeConnect' => fn () => $this->manager()->completeConnect($foreign, 'code', (int) $customer->user_id),
                'accessTokenFor' => fn () => $this->manager()->accessTokenFor($foreign),
                'revoke' => fn () => $this->manager()->revoke($foreign),
                'disconnect' => fn () => $this->manager()->disconnect($foreign, (int) $customer->user_id),
                'markFailure' => fn () => $this->manager()->markFailure($foreign, GoogleAdsProviderException::timeout()),
            ];

            foreach ($attempts as $name => $attempt) {
                try {
                    $attempt();
                    $this->fail($name . ' must refuse a ' . $product->value . ' row');
                } catch (LogicException $e) {
                    $this->assertStringContainsString($product->value, $e->getMessage());
                }
            }

            $untouched = $foreign->fresh();
            $this->assertSame(GoogleConnectionState::Active, $untouched->state);
            $this->assertSame('foreign-token-' . $product->value, $untouched->refresh_token_encrypted);
            $this->assertSame(0, (int) $untouched->lock_version);
            $this->assertSame(0, $this->fakeAds->callCount(), 'no provider call may be made for a foreign row');
            $this->assertSame(0, BusinessGoogleOperation::where('business_id', $business->id)->count(), 'no ledger entry either');
        }
    }

    // ---------------------------------------------------------------
    // Optimistic locking
    // ---------------------------------------------------------------

    public function test_a_stale_writer_loses_the_race_and_cannot_overwrite_the_winner(): void
    {
        [, $business] = $this->adsTenant();
        $this->activeAdsConnection($business);

        $stale = $this->manager()->findForBusiness($business);
        $winner = $this->manager()->findForBusiness($business);

        $this->manager()->disconnect($winner, null);

        try {
            $this->manager()->revoke($stale);
            $this->fail('expected a concurrency failure');
        } catch (GoogleAdsConcurrencyException $e) {
            $this->assertNotSame('', $e->userMessage());
        }

        $this->assertSame(GoogleConnectionState::Disconnected, $this->adsRow($business->id)->state);
    }
}
