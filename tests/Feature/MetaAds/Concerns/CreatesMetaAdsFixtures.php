<?php

namespace Tests\Feature\MetaAds\Concerns;

use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationType;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Library\MetaAds\Contracts\MetaMutationClient;
use App\Library\MetaAds\Contracts\MetaReadClient;
use App\Library\MetaAds\FakeMetaClient;
use App\Library\MetaAds\MetaAdsCallBudget;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\MetaAdsOAuthConfig;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\Customer;
use App\Models\MetaAdsAccount;
use App\Models\User;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Shared fixtures for ALL Meta Ads suites.
 *
 * EVERY test binds FakeMetaClient (Photo Booth fixture), so no request to any
 * Facebook / Graph host is ever attempted and no Meta credential is required.
 */
trait CreatesMetaAdsFixtures
{
    use CreatesBusinessTestData;

    protected FakeMetaClient $fakeMeta;

    /**
     * Binds ONE FakeMetaClient (with the shared call budget, exactly as the
     * container does for driver=fake) behind all three provider interfaces,
     * sets config meta_ads.driver=fake and a valid OAuth configuration.
     */
    protected function bindFakeMeta(): FakeMetaClient
    {
        config(['meta_ads.driver' => 'fake']);
        $this->configureValidMetaOAuth();

        $this->fakeMeta = (new FakeMetaClient(app(MetaAdsCallBudget::class), app(MetaAdsConfig::class)))
            ->usePhotoBoothFixture();

        $this->app->instance(FakeMetaClient::class, $this->fakeMeta);

        foreach ([MetaAuthClient::class, MetaReadClient::class, MetaMutationClient::class] as $contract) {
            $this->app->instance($contract, $this->fakeMeta);
        }

        return $this->fakeMeta;
    }

    /** The redirect is the ONE fixed callback; the route itself belongs to the UI lane. */
    protected function configureValidMetaOAuth(): void
    {
        config([
            'services.meta_ads.app_id' => 'test-meta-app-id',
            'services.meta_ads.app_secret' => 'test-meta-app-secret',
            'services.meta_ads.redirect' => url(MetaAdsOAuthConfig::CALLBACK_PATH),
        ]);
    }

    /** @return array{0: Customer, 1: Business} */
    protected function metaTenant(string $name = 'Snap Booth Co'): array
    {
        $this->ensureMetaConfigRowsExist();

        if (User::query()->count() === 0) {
            // User id 1 short-circuits permission checks; burn it on a platform admin.
            User::create([
                'first_name' => 'Platform', 'last_name' => 'Admin',
                'email' => 'platform-admin' . uniqid('', true) . '@example.test',
                'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
            ]);
        }

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => $name]));

        return [$customer, $business];
    }

    protected function ensureMetaConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }

    /** An ACTIVE connection with a token 50 days from expiry and both scopes. */
    protected function activeMetaConnection(Business $business, array $overrides = []): BusinessMetaConnection
    {
        return BusinessMetaConnection::create(array_merge([
            'business_id' => $business->id,
            'state' => MetaConnectionState::Active,
            'access_token_encrypted' => 'plain-meta-access-token',
            'token_expires_at' => now()->addDays(50),
            'granted_scopes' => 'ads_read,ads_management',
            'meta_user_id' => MetaPhotoBoothFixture::META_USER_ID,
            'meta_user_name' => MetaPhotoBoothFixture::META_USER_NAME,
            'connected_at' => now(),
            'last_verified_at' => now(),
            'connected_by_user_id' => $business->customer_id,
        ], $overrides));
    }

    /**
     * A SELECTED account consistent with the Fake fixture's active USD account,
     * bound to the Business's connection (an active one is created if absent).
     */
    protected function selectedMetaAccount(Business $business, array $overrides = []): MetaAdsAccount
    {
        $connection = BusinessMetaConnection::query()->where('business_id', $business->id)->first()
            ?? $this->activeMetaConnection($business);

        return MetaAdsAccount::create(array_merge([
            'business_id' => $business->id,
            'business_meta_connection_id' => $connection->id,
            'ad_account_id' => MetaPhotoBoothFixture::AD_ACCOUNT_ID,
            'name' => 'Photo Booth Co - Ads',
            'currency_code' => MetaPhotoBoothFixture::CURRENCY,
            'time_zone' => MetaPhotoBoothFixture::TIME_ZONE,
            'account_status' => 1,
            'selected_at' => now(),
            'selected_by_user_id' => $business->customer_id,
            'selected_meta_user_id' => $connection->meta_user_id,
        ], $overrides));
    }

    /**
     * Runs $work inside a call-budget context bound to a throwaway ledger
     * operation, as every real provider call must be.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    protected function inMetaOperation(BusinessMetaConnection $connection, callable $work): mixed
    {
        $operation = app(MetaAdsOperationLedger::class)->open(
            (int) $connection->business_id,
            MetaOperationType::MetaAdsSync,
            null,
            'test operation',
        );

        return app(MetaAdsCallBudget::class)->withinOperation($connection, $operation, $work);
    }
}
