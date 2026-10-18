<?php

namespace Tests\Feature\MetaAds;

use App\DTO\MetaAds\MetaTokenGrant;
use App\DTO\MetaAds\MetaUserProfile;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsConcurrencyException;
use App\Exceptions\MetaAds\MetaConfigurationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Library\MetaAds\MetaAdsCallBudget;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Library\MetaAds\MetaAdsOAuthConfig;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Library\MetaAds\MetaOAuthStateSigner;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\MetaAds\Concerns\CreatesMetaAdsFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §3 — the Meta connection state machine on its
 * own `business_meta_connections` authority.
 */
class MetaAdsConnectionManagerTest extends TestCase
{
    use CreatesMetaAdsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeMeta();
    }

    private function manager(): MetaAdsConnectionManager
    {
        return app(MetaAdsConnectionManager::class);
    }

    private function row(int $businessId): ?BusinessMetaConnection
    {
        return BusinessMetaConnection::query()->where('business_id', $businessId)->first();
    }

    private function operation(int $businessId, MetaOperationType $type): ?BusinessMetaOperation
    {
        return BusinessMetaOperation::query()
            ->where('business_id', $businessId)
            ->where('operation_type', $type->value)
            ->latest('id')
            ->first();
    }

    private function rawToken(int $connectionId): ?string
    {
        return DB::table('business_meta_connections')->where('id', $connectionId)->value('access_token_encrypted');
    }

    /** @return array<string, mixed> the query of the dialog URL */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    // ---------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------

    public function test_begin_connect_with_unusable_config_leaves_nothing_behind(): void
    {
        [$customer, $business] = $this->metaTenant();

        $cases = [
            'services.meta_ads.app_id' => null,
            'services.meta_ads.app_secret' => '',
            'services.meta_ads.redirect' => null,
        ];

        foreach ($cases as $key => $value) {
            $this->configureValidMetaOAuth();
            config([$key => $value]);

            try {
                $this->manager()->beginConnect($business, (int) $customer->user_id);
                $this->fail('expected a configuration failure for ' . $key);
            } catch (MetaConfigurationException) {
                $this->assertNull($this->row($business->id));
                $this->assertSame(0, BusinessMetaOperation::count());
            }
        }

        foreach (['http://evil.example/ads/meta/oauth/callback', 'https://x.example/other', url('/ads/meta/oauth/callback') . '?a=1'] as $redirect) {
            $this->configureValidMetaOAuth();
            config(['services.meta_ads.redirect' => $redirect]);
            $this->assertFalse(app(MetaAdsOAuthConfig::class)->isUsable(), $redirect);
        }

        // Permitted local http: only localhost-family hosts, and still only the fixed path.
        config(['app.url' => 'http://localhost']);
        config(['services.meta_ads.redirect' => 'http://localhost/ads/meta/oauth/callback']);
        $this->assertTrue(app(MetaAdsOAuthConfig::class)->isUsable());
    }

    // ---------------------------------------------------------------
    // beginConnect
    // ---------------------------------------------------------------

    public function test_begin_connect_creates_a_pending_row_bound_to_the_actor_and_returns_the_dialog_url(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;

        $url = $this->manager()->beginConnect($business, $actor);

        $row = $this->row($business->id);
        $this->assertSame(MetaConnectionState::Pending, $row->state);
        $this->assertSame($actor, (int) $row->connected_by_user_id);
        $this->assertNotNull($row->oauth_state_nonce);
        $this->assertNull($row->access_token_encrypted);

        $query = $this->query($url);
        $this->assertSame('ads_read,ads_management', $query['scope']);
        $this->assertSame('code', $query['response_type']);

        $payload = app(MetaOAuthStateSigner::class)->verify($query['state']);
        $this->assertSame((int) $business->id, $payload['b']);
        $this->assertSame($actor, $payload['u']);
        $this->assertSame($row->oauth_state_nonce, $payload['n']);

        $this->assertSame(MetaOperationStatus::Pending, $this->operation($business->id, MetaOperationType::ConnectInitiated)->status);
    }

    public function test_begin_connect_is_refused_while_active_and_healthy(): void
    {
        [$customer, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business);

        try {
            $this->manager()->beginConnect($business, (int) $customer->user_id);
            $this->fail('expected a refusal');
        } catch (LogicException) {
            $fresh = $connection->fresh();
            $this->assertSame(MetaConnectionState::Active, $fresh->state);
            $this->assertNull($fresh->oauth_state_nonce);
            $this->assertSame(0, BusinessMetaOperation::count());
        }
    }

    public function test_reauth_is_allowed_when_the_token_is_inside_the_warning_window_and_keeps_the_live_token(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $connection = $this->activeMetaConnection($business, ['token_expires_at' => now()->addDays(3)]);

        $url = $this->manager()->beginConnect($business, $actor);

        $row = $connection->fresh();
        $this->assertSame(MetaConnectionState::Active, $row->state, 're-auth never drops a live connection');
        $this->assertSame('plain-meta-access-token', $row->access_token_encrypted);
        $this->assertNotNull($row->oauth_state_nonce);
        $this->assertNotNull($this->query($url)['state']);
        $this->assertNotNull($this->operation($business->id, MetaOperationType::ConnectInitiated));
    }

    public function test_reauth_can_be_forced_on_a_healthy_connection(): void
    {
        [$customer, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business);

        $this->manager()->beginConnect($business, (int) $customer->user_id, forceReauth: true);

        $this->assertNotNull($connection->fresh()->oauth_state_nonce);
        $this->assertSame(MetaConnectionState::Active, $connection->fresh()->state);
    }

    public function test_a_new_attempt_rebinds_the_actor_and_kills_the_older_state(): void
    {
        [$customer, $business] = $this->metaTenant();
        $first = (int) $customer->user_id;
        [$otherCustomer] = $this->metaTenant('Other');
        $second = (int) $otherCustomer->user_id;

        $signer = app(MetaOAuthStateSigner::class);
        $old = $signer->verify($this->query($this->manager()->beginConnect($business, $first))['state']);
        $this->manager()->beginConnect($business, $second);

        $row = $this->row($business->id);
        $this->assertFalse($this->manager()->attemptBelongsToActor($row, $first));
        $this->assertTrue($this->manager()->attemptBelongsToActor($row, $second));
        $this->assertFalse($signer->consume($old));
    }

    public function test_begin_connect_from_expired_revoked_and_disconnected_goes_through_pending(): void
    {
        foreach ([MetaConnectionState::Expired, MetaConnectionState::Revoked, MetaConnectionState::Disconnected] as $state) {
            [$customer, $business] = $this->metaTenant();
            $connection = $this->activeMetaConnection($business, ['state' => $state, 'access_token_encrypted' => null]);

            $this->manager()->beginConnect($business, (int) $customer->user_id);

            $this->assertSame(MetaConnectionState::Pending, $connection->fresh()->state, $state->value);
        }
    }

    public function test_a_unique_violation_race_on_the_first_connection_continues_on_the_winners_row(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;

        $armed = true;
        BusinessMetaConnection::creating(function (BusinessMetaConnection $model) use (&$armed, $business): void {
            if (! $armed) {
                return;
            }
            $armed = false;
            // The concurrent winner inserts its row between our lookup and our INSERT.
            DB::table('business_meta_connections')->insert([
                'uid' => (string) \Illuminate\Support\Str::uuid(),
                'business_id' => $business->id,
                'state' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $url = $this->manager()->beginConnect($business, $actor);

        $this->assertSame(1, BusinessMetaConnection::where('business_id', $business->id)->count());
        $row = $this->row($business->id);
        $this->assertSame($actor, (int) $row->connected_by_user_id);
        $this->assertNotNull($row->oauth_state_nonce);
        $this->assertNotNull($this->query($url)['state']);
    }

    // ---------------------------------------------------------------
    // completeConnect
    // ---------------------------------------------------------------

    public function test_complete_connect_stores_an_encrypted_token_profile_scopes_and_expiry(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $this->manager()->beginConnect($business, $actor);
        $connection = $this->row($business->id);

        $this->manager()->completeConnect($connection, 'auth-code', $actor);

        $row = $this->row($business->id);
        $this->assertSame(MetaConnectionState::Active, $row->state);
        $this->assertTrue($row->hasStoredAuthorization());
        $this->assertStringStartsWith('fake-long-lived-token-', $row->access_token_encrypted);
        $this->assertStringNotContainsString('fake-long-lived-token', (string) $this->rawToken($row->id), 'encrypted at rest');
        $this->assertSame(MetaPhotoBoothFixture::META_USER_ID, $row->meta_user_id);
        $this->assertSame(MetaPhotoBoothFixture::META_USER_NAME, $row->meta_user_name);
        $this->assertSame('ads_read,ads_management', $row->granted_scopes);
        $this->assertEqualsWithDelta(60, now()->diffInDays($row->token_expires_at, false), 1);
        $this->assertNotNull($row->last_verified_at);
        $this->assertTrue($this->manager()->canManage($row));

        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation($business->id, MetaOperationType::ConnectCompleted)->status);
        $this->assertGreaterThanOrEqual(3, $this->operation($business->id, MetaOperationType::ConnectCompleted)->provider_call_count, 'exchange + profile + permissions are budget-accounted');
        $this->assertArrayNotHasKey('access_token_encrypted', $row->toArray());
    }

    public function test_an_unknown_expiry_falls_back_to_55_days(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $this->manager()->beginConnect($business, $actor);

        $client = new class implements MetaAuthClient
        {
            public function authorizationUrl(string $signedState): string
            {
                return 'https://example.test';
            }

            public function exchangeCode(string $code): MetaTokenGrant
            {
                return new MetaTokenGrant('no-expiry-token');
            }

            public function profile(string $accessToken): MetaUserProfile
            {
                return new MetaUserProfile('555', null);
            }

            public function grantedPermissions(string $accessToken): array
            {
                return ['ads_read', 'ads_management'];
            }
        };

        $manager = new MetaAdsConnectionManager(
            $client, app(MetaOAuthStateSigner::class), app(MetaAdsOperationLedger::class),
            app(MetaAdsOAuthConfig::class), app(MetaAdsCallBudget::class), app(MetaAdsConfig::class),
        );
        $manager->completeConnect($this->row($business->id), 'code', $actor);

        $row = $this->row($business->id);
        $this->assertEqualsWithDelta(55, now()->diffInDays($row->token_expires_at, false), 1);
        $this->assertNull($row->meta_user_name);
    }

    public function test_missing_ads_read_fails_closed_and_stays_pending(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $this->manager()->beginConnect($business, $actor);
        $this->fakeMeta->grantNoAdsRead();

        try {
            $this->manager()->completeConnect($this->row($business->id), 'code', $actor);
            $this->fail('expected access_denied');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::ACCESS_DENIED, $e->classification);
        }

        $row = $this->row($business->id);
        $this->assertSame(MetaConnectionState::Pending, $row->state);
        $this->assertNull($row->access_token_encrypted);
        $this->assertNull($row->meta_user_id);
        $this->assertSame(MetaProviderException::ACCESS_DENIED, $row->failure_classification);

        $failed = $this->operation($business->id, MetaOperationType::ConnectFailed);
        $this->assertSame(MetaOperationStatus::Failed, $failed->status);
        $this->assertSame(MetaProviderException::ACCESS_DENIED, $failed->failure_classification);
        $this->assertSame(MetaOperationStatus::Failed, $this->operation($business->id, MetaOperationType::ConnectCompleted)->status);
    }

    public function test_missing_ads_management_connects_read_only(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $this->manager()->beginConnect($business, $actor);
        $this->fakeMeta->grantScopes(['public_profile', 'ads_read']);

        $this->manager()->completeConnect($this->row($business->id), 'code', $actor);

        $row = $this->row($business->id);
        $this->assertSame(MetaConnectionState::Active, $row->state);
        $this->assertSame('ads_read', $row->granted_scopes, 'records only what was granted');
        $this->assertFalse($this->manager()->canManage($row));
        $this->assertFalse($this->manager()->tokenStatus($row)->canManage);
    }

    public function test_a_provider_failure_during_the_exchange_keeps_pending_and_leaks_nothing(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $this->manager()->beginConnect($business, $actor);
        $this->fakeMeta->failNext('exchangeCode', MetaProviderException::rateLimited(4, 'abc123'));

        try {
            $this->manager()->completeConnect($this->row($business->id), 'SECRET-AUTH-CODE', $actor);
            $this->fail('expected rate_limited');
        } catch (MetaProviderException $e) {
            $this->assertStringNotContainsString('SECRET-AUTH-CODE', $e->getMessage());
        }

        $row = $this->row($business->id);
        $this->assertSame(MetaConnectionState::Pending, $row->state);
        $this->assertSame(MetaOperationStatus::Deferred, $this->operation($business->id, MetaOperationType::ConnectCompleted)->status);
    }

    public function test_no_token_or_code_ever_reaches_the_ledger_or_the_log(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        \Illuminate\Support\Facades\Log::spy();

        $this->manager()->beginConnect($business, $actor);
        $this->manager()->completeConnect($this->row($business->id), 'SECRET-AUTH-CODE', $actor);
        $token = $this->manager()->accessTokenFor($this->row($business->id));
        $this->manager()->markTokenFailure($this->row($business->id), MetaProviderException::invalidToken(463));
        $this->manager()->beginConnect($business, $actor);
        $this->fakeMeta->grantNoAdsRead();
        try {
            $this->manager()->completeConnect($this->row($business->id), 'SECRET-AUTH-CODE-2', $actor);
        } catch (MetaProviderException) {
        }
        $this->manager()->disconnect($this->row($business->id), $actor);

        $dump = json_encode(BusinessMetaOperation::all()->toArray())
            . json_encode(DB::table('business_meta_connections')->get()->map(fn ($r) => (array) $r)->all());

        foreach ([$token, 'SECRET-AUTH-CODE', 'plain-meta-access-token'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dump);
        }

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    public function test_reauth_completion_replaces_the_token_and_expiry_in_one_versioned_update(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $connection = $this->activeMetaConnection($business, ['token_expires_at' => now()->addDays(2)]);
        $this->manager()->beginConnect($business, $actor);
        $versionBefore = (int) $connection->fresh()->lock_version;

        $this->manager()->completeConnect($this->row($business->id), 'code', $actor);

        $row = $this->row($business->id);
        $this->assertSame(MetaConnectionState::Active, $row->state);
        $this->assertStringStartsWith('fake-long-lived-token-', $row->access_token_encrypted);
        $this->assertGreaterThan(50, now()->diffInDays($row->token_expires_at, false));
        $this->assertSame($versionBefore + 1, (int) $row->lock_version);
    }

    public function test_reauth_by_a_different_meta_user_unselects_the_account_but_keeps_it(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $this->activeMetaConnection($business, ['token_expires_at' => now()->addDays(2)]);
        $account = $this->selectedMetaAccount($business);
        $this->fakeMeta->withProfile(new MetaUserProfile('777000777', 'Someone Else'));

        $this->manager()->beginConnect($business, $actor);
        $this->manager()->completeConnect($this->row($business->id), 'code', $actor);

        $this->assertSame('777000777', $this->row($business->id)->meta_user_id);
        $fresh = $account->fresh();
        $this->assertNull($fresh->selected_at, 'never silently re-bound');
        $this->assertSame(MetaPhotoBoothFixture::AD_ACCOUNT_ID, $fresh->ad_account_id, 'row and history are kept');
    }

    public function test_reauth_by_the_same_meta_user_keeps_the_selection(): void
    {
        [$customer, $business] = $this->metaTenant();
        $actor = (int) $customer->user_id;
        $this->activeMetaConnection($business, ['token_expires_at' => now()->addDays(2)]);
        $account = $this->selectedMetaAccount($business);

        $this->manager()->beginConnect($business, $actor);
        $this->manager()->completeConnect($this->row($business->id), 'code', $actor);

        $this->assertNotNull($account->fresh()->selected_at);
    }

    public function test_completing_without_a_pending_attempt_is_an_illegal_transition(): void
    {
        [$customer, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business, ['state' => MetaConnectionState::Disconnected, 'access_token_encrypted' => null]);

        try {
            $this->manager()->completeConnect($connection, 'code', (int) $customer->user_id);
            $this->fail('expected an illegal transition');
        } catch (LogicException) {
            $this->assertSame(MetaConnectionState::Disconnected, $connection->fresh()->state);
            $this->assertNull($connection->fresh()->access_token_encrypted);
        }
    }

    // ---------------------------------------------------------------
    // accessTokenFor / token failure
    // ---------------------------------------------------------------

    public function test_access_token_is_returned_only_for_an_active_unexpired_connection(): void
    {
        [, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business);

        $this->assertSame('plain-meta-access-token', $this->manager()->accessTokenFor($connection));

        foreach ([MetaConnectionState::Pending, MetaConnectionState::Expired, MetaConnectionState::Revoked, MetaConnectionState::Disconnected] as $state) {
            [, $other] = $this->metaTenant();
            $row = $this->activeMetaConnection($other, ['state' => $state]);

            try {
                $this->manager()->accessTokenFor($row);
                $this->fail($state->value . ' must not yield a token');
            } catch (MetaProviderException $e) {
                $this->assertSame(MetaProviderException::TOKEN_EXPIRED, $e->classification);
            }
        }
    }

    public function test_a_token_past_its_expiry_is_refused_and_the_connection_expires_with_the_token_wiped(): void
    {
        [, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business, ['token_expires_at' => now()->subMinute()]);

        try {
            $this->manager()->accessTokenFor($connection);
            $this->fail('expected token_expired');
        } catch (MetaProviderException $e) {
            $this->assertSame(MetaProviderException::TOKEN_EXPIRED, $e->classification);
        }

        $row = $connection->fresh();
        $this->assertSame(MetaConnectionState::Expired, $row->state);
        $this->assertNull($this->rawToken($row->id));
        $this->assertSame(0, $this->fakeMeta->callCount(), 'no provider call for a dead token');
    }

    public function test_token_failures_move_to_expired_or_revoked_and_wipe_the_token(): void
    {
        $cases = [
            'expired 463' => [MetaProviderException::invalidToken(463), MetaConnectionState::Expired],
            'expired helper' => [MetaProviderException::tokenExpired(), MetaConnectionState::Expired],
            'plain 190' => [MetaProviderException::invalidToken(), MetaConnectionState::Expired],
            'revocation 458' => [MetaProviderException::invalidToken(458), MetaConnectionState::Revoked],
        ];

        foreach ($cases as $label => [$exception, $expected]) {
            [, $business] = $this->metaTenant();
            $connection = $this->activeMetaConnection($business);

            $this->manager()->markTokenFailure($connection, $exception);

            $row = $connection->fresh();
            $this->assertSame($expected, $row->state, $label);
            $this->assertNull($this->rawToken($row->id), $label . ' wipes the token');
            $this->assertNull($row->token_expires_at, $label);
            $this->assertSame($exception->classification, $row->failure_classification, $label);
            $this->assertNotNull($this->operation($business->id, MetaOperationType::TokenExpired), $label);
        }

        [, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business);
        $this->manager()->markTokenFailure($connection, MetaProviderException::invalidToken(458));
        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    public function test_other_provider_failures_change_no_state(): void
    {
        [, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business);
        $version = (int) $connection->lock_version;

        foreach ([MetaProviderException::rateLimited(), MetaProviderException::timeout(), MetaProviderException::providerUnavailable(), MetaProviderException::accessDenied()] as $exception) {
            $this->manager()->markTokenFailure($connection, $exception);
        }

        $row = $connection->fresh();
        $this->assertSame(MetaConnectionState::Active, $row->state);
        $this->assertSame('plain-meta-access-token', $row->access_token_encrypted);
        $this->assertSame($version, (int) $row->lock_version);
    }

    public function test_a_token_failure_on_an_already_dead_connection_is_a_no_op(): void
    {
        [, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business);
        $this->manager()->markTokenFailure($connection, MetaProviderException::invalidToken(463));
        $version = (int) $connection->fresh()->lock_version;

        $this->manager()->markTokenFailure($connection->fresh(), MetaProviderException::invalidToken(458));

        $this->assertSame(MetaConnectionState::Expired, $connection->fresh()->state);
        $this->assertSame($version, (int) $connection->fresh()->lock_version);
    }

    // ---------------------------------------------------------------
    // Disconnect
    // ---------------------------------------------------------------

    public function test_disconnect_destroys_the_token_unselects_the_account_and_keeps_history(): void
    {
        [$customer, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business);
        $account = $this->selectedMetaAccount($business);

        $this->manager()->disconnect($connection, (int) $customer->user_id);

        $row = $connection->fresh();
        $this->assertSame(MetaConnectionState::Disconnected, $row->state);
        $this->assertNull($this->rawToken($row->id));
        $this->assertNull($row->token_expires_at);
        $this->assertNull($row->granted_scopes);
        $this->assertNotNull($row->disconnected_at);

        $fresh = $account->fresh();
        $this->assertNull($fresh->selected_at);
        $this->assertSame(MetaPhotoBoothFixture::AD_ACCOUNT_ID, $fresh->ad_account_id);

        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation($business->id, MetaOperationType::Disconnected)->status);
        $this->assertSame(1, MetaAdsAccount::where('business_id', $business->id)->count());
    }

    // ---------------------------------------------------------------
    // Token status / expiry warning
    // ---------------------------------------------------------------

    public function test_token_status_reports_days_left_and_the_reauth_warning(): void
    {
        [, $business] = $this->metaTenant();
        $connection = $this->activeMetaConnection($business, ['token_expires_at' => now()->addDays(30)->addHour()]);

        $healthy = $this->manager()->tokenStatus($connection)->toArray();
        $this->assertSame('active', $healthy['state']);
        $this->assertSame(30, $healthy['days_left']);
        $this->assertFalse($healthy['needs_reauth_soon']);
        $this->assertTrue($healthy['can_manage']);
        $this->assertInstanceOf(CarbonImmutable::class, $healthy['expires_at']);

        DB::table('business_meta_connections')->where('id', $connection->id)->update(['token_expires_at' => now()->addDays(5)->addHour()]);
        $soon = $this->manager()->tokenStatus($connection->fresh())->toArray();
        $this->assertSame(5, $soon['days_left']);
        $this->assertTrue($soon['needs_reauth_soon']);

        $this->assertSame(['state', 'expires_at', 'days_left', 'needs_reauth_soon', 'can_manage'], array_keys($soon));
        $this->assertStringNotContainsString('plain-meta-access-token', json_encode($soon));

        $this->manager()->markTokenFailure($connection->fresh(), MetaProviderException::invalidToken(463));
        $dead = $this->manager()->tokenStatus($connection->fresh())->toArray();
        $this->assertSame('expired', $dead['state']);
        $this->assertNull($dead['days_left']);
        $this->assertFalse($dead['can_manage']);
    }

    // ---------------------------------------------------------------
    // Optimistic locking
    // ---------------------------------------------------------------

    public function test_a_stale_writer_loses_the_race_and_cannot_overwrite_the_winner(): void
    {
        [, $business] = $this->metaTenant();
        $this->activeMetaConnection($business);

        $stale = $this->manager()->findForBusiness($business);
        $winner = $this->manager()->findForBusiness($business);

        $this->manager()->disconnect($winner, null);

        try {
            $this->manager()->markTokenFailure($stale, MetaProviderException::invalidToken(463));
            $this->fail('expected a concurrency failure');
        } catch (MetaAdsConcurrencyException $e) {
            $this->assertNotSame('', $e->userMessage());
        }

        $this->assertSame(MetaConnectionState::Disconnected, $this->row($business->id)->state);
    }

    public function test_the_connection_is_keyed_by_business(): void
    {
        [$customerA, $businessA] = $this->metaTenant('A');
        [, $businessB] = $this->metaTenant('B');
        $this->activeMetaConnection($businessA);

        $this->assertNull($this->manager()->findForBusiness($businessB));
        $this->assertSame((int) $businessA->id, (int) $this->manager()->findForBusiness($businessA)->business_id);
    }
}
