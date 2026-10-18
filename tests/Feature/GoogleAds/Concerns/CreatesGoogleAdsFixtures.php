<?php

namespace Tests\Feature\GoogleAds\Concerns;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Library\GoogleAds\Contracts\GoogleAdsAuthClient;
use App\Library\GoogleAds\Contracts\GoogleAdsMutationClient;
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;
use App\Library\GoogleAds\FakeGoogleAdsClient;
use App\Library\GoogleAds\GoogleAdsCallBudget;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Library\GoogleAds\GoogleAdsOAuthConfig;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Shared fixtures for the Google Ads suites.
 *
 * EVERY test either binds FakeGoogleAdsClient or fakes the Http facade, so no
 * real request to any googleapis.com host is ever attempted and no Google
 * credential is required. The fixture date is pinned so the PhotoBooth data is
 * identical on every run.
 */
trait CreatesGoogleAdsFixtures
{
    use CreatesBusinessTestData;

    protected FakeGoogleAdsClient $fakeAds;

    protected const FIXTURE_TODAY = '2026-10-04';

    /**
     * Binds ONE FakeGoogleAdsClient (with the shared call budget, exactly as
     * the container does for driver=fake) behind all three provider
     * interfaces and configures a valid OAuth setup.
     */
    protected function bindFakeAds(): FakeGoogleAdsClient
    {
        $this->configureValidAdsOAuth();

        $this->fakeAds = (new FakeGoogleAdsClient(app(GoogleAdsCallBudget::class), app(GoogleAdsConfig::class)))
            ->usePhotoBoothFixture(new PhotoBoothFixture(self::FIXTURE_TODAY));

        foreach ([GoogleAdsAuthClient::class, GoogleAdsReadClient::class, GoogleAdsMutationClient::class] as $contract) {
            $this->app->instance($contract, $this->fakeAds);
        }

        return $this->fakeAds;
    }

    /**
     * The fixed tenant-free callback route is registered by the routing
     * phase, not this one, so the suite registers a stand-in under the same
     * name; the OAuth config compares the configured redirect to it.
     */
    protected function configureValidAdsOAuth(): void
    {
        if (! Route::has(GoogleAdsOAuthConfig::CALLBACK_ROUTE)) {
            Route::get('ads/oauth/callback', fn () => 'ok')->name(GoogleAdsOAuthConfig::CALLBACK_ROUTE);
            app('router')->getRoutes()->refreshNameLookups();
        }

        config([
            'services.google_ads.client_id' => 'test-ads-client-id',
            'services.google_ads.client_secret' => 'test-ads-client-secret',
            'services.google_ads.redirect' => route(GoogleAdsOAuthConfig::CALLBACK_ROUTE),
        ]);
    }

    /** @return array{0: Customer, 1: Business} */
    protected function adsTenant(string $name = 'Snap Booth Co'): array
    {
        $this->ensureAdsConfigRowsExist();

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

    protected function ensureAdsConfigRowsExist(): void
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

    protected function activeAdsConnection(Business $business, array $overrides = []): BusinessGoogleConnection
    {
        return BusinessGoogleConnection::create(array_merge([
            'business_id' => $business->id,
            'product' => GoogleConnectionProduct::GoogleAds,
            'state' => GoogleConnectionState::Active,
            'refresh_token_encrypted' => 'plain-ads-refresh-token',
            'granted_scopes' => GoogleConnectionProduct::GoogleAds->scope(),
            'google_account_email' => 'owner@example.test',
            'connected_at' => now(),
            'connected_by_user_id' => $business->customer_id,
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
    protected function inAdsOperation(BusinessGoogleConnection $connection, callable $work): mixed
    {
        $operation = app(GoogleAdsOperationLedger::class)->open(
            (int) $connection->business_id,
            GoogleOperationType::AdsSync,
            null,
            'test operation',
        );

        return app(GoogleAdsCallBudget::class)->withinOperation($connection, $operation, $work);
    }
}
