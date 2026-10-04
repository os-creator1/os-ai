<?php

namespace Tests\Feature\GoogleAds;

use App\Exceptions\GoogleAds\GoogleAdsConfigurationException;
use App\Library\GoogleAds\FakeGoogleAdsClient;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Library\GoogleAds\GoogleAdsOAuthConfig;
use App\Library\GoogleAds\Contracts\GoogleAdsAuthClient;
use App\Library\GoogleAds\Contracts\GoogleAdsMutationClient;
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;
use App\Library\GoogleAds\HttpGoogleAdsAuthClient;
use App\Library\GoogleAds\HttpGoogleAdsMutationClient;
use App\Library\GoogleAds\HttpGoogleAdsReadClient;
use App\Library\GoogleAds\PhotoBoothFixture;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Google Ads Module V1 — config/google_ads.php is read ONLY through
 * GoogleAdsConfig, which fails CLOSED to documented safe defaults.
 */
class GoogleAdsConfigTest extends TestCase
{
    private function config(): GoogleAdsConfig
    {
        return new GoogleAdsConfig();
    }

    public function test_shipped_defaults_match_the_contract(): void
    {
        $c = $this->config();

        $this->assertSame('v25', $c->apiVersion());
        $this->assertSame('https://googleads.googleapis.com', $c->baseUrl());
        $this->assertSame('http', $c->driver());
        $this->assertNull($c->developerToken(), 'the developer-token header is OFF unless configured');
        $this->assertSame(24, $c->syncMinIntervalHours());
        $this->assertSame(62, $c->metricsLookbackDays());
        $this->assertSame(30, $c->searchTermLookbackDays());
        $this->assertSame(60, $c->manualRefreshMinMinutes());
        $this->assertSame(7, $c->pacingMinDays());
        $this->assertEqualsWithDelta(0.15, $c->pacingTolerance(), 1e-9);
        $this->assertSame(90, $c->attributionCookieDays());
        $this->assertTrue($c->attributionCaptureEnabled());
        $this->assertGreaterThan(0, $c->wasteMinSpendMicros());
        $this->assertGreaterThanOrEqual(1.0, $c->cplOverFactor());
    }

    public function test_sync_interval_is_floored_at_twenty_hours(): void
    {
        foreach ([19, 0, -5, 'abc', null, 1.5, 99999] as $bad) {
            config(['google_ads.sync.min_interval_hours' => $bad]);
            $this->assertSame(24, $this->config()->syncMinIntervalHours(), var_export($bad, true));
        }

        config(['google_ads.sync.min_interval_hours' => 20]);
        $this->assertSame(20, $this->config()->syncMinIntervalHours());

        config(['google_ads.sync.min_interval_hours' => '36']);
        $this->assertSame(36, $this->config()->syncMinIntervalHours(), 'a digit-only env string is accepted');
    }

    public function test_numeric_settings_fail_closed_to_safe_defaults_never_unlimited(): void
    {
        $cases = [
            ['google_ads.sync.max_calls_per_business_per_hour', 'maxCallsPerBusinessPerHour', 60],
            ['google_ads.sync.max_pages_per_report', 'maxPagesPerReport', 20],
            ['google_ads.sync.max_rows_per_report', 'maxRowsPerReport', 100000],
            ['google_ads.sync.breaker_threshold', 'breakerThreshold', 20],
            ['google_ads.sync.breaker_cooldown_minutes', 'breakerCooldownMinutes', 30],
            ['google_ads.sync.metrics_lookback_days', 'metricsLookbackDays', 62],
            ['google_ads.sync.search_term_lookback_days', 'searchTermLookbackDays', 30],
            ['google_ads.pacing.min_days', 'pacingMinDays', 7],
        ];

        foreach ($cases as [$key, $method, $default]) {
            foreach ([0, -1, 'unlimited', null, false, 10 ** 12] as $bad) {
                config([$key => $bad]);
                $this->assertSame($default, $this->config()->{$method}(), $key . ' = ' . var_export($bad, true));
            }
        }
    }

    public function test_floats_are_range_checked(): void
    {
        foreach ([0, 0.0, -0.1, 1.5, 'x', null] as $bad) {
            config(['google_ads.pacing.tolerance' => $bad]);
            $this->assertEqualsWithDelta(0.15, $this->config()->pacingTolerance(), 1e-9, var_export($bad, true));
        }

        config(['google_ads.pacing.tolerance' => '0.2']);
        $this->assertEqualsWithDelta(0.2, $this->config()->pacingTolerance(), 1e-9);

        // "over target" must mean strictly over: a factor below 1 is rejected.
        config(['google_ads.recommendations.cpl_over_factor' => 0.5]);
        $this->assertEqualsWithDelta(1.25, $this->config()->cplOverFactor(), 1e-9);
    }

    public function test_base_url_is_https_only_and_a_bad_value_falls_back(): void
    {
        foreach (['http://googleads.googleapis.com', 'ftp://x.test', 'not a url', 'https://', '', null, 'https://u:p@evil.test', 'https://x.test?x=1'] as $bad) {
            config(['google_ads.base_url' => $bad]);
            $this->assertSame('https://googleads.googleapis.com', $this->config()->baseUrl(), var_export($bad, true));
        }

        config(['google_ads.base_url' => 'https://Ads-Proxy.example.test:8443/base/']);
        $this->assertSame('https://ads-proxy.example.test:8443/base', $this->config()->baseUrl());
    }

    public function test_api_version_must_look_like_a_version(): void
    {
        foreach (['25', 'v25/../x', 'latest', '', null] as $bad) {
            config(['google_ads.api_version' => $bad]);
            $this->assertSame('v25', $this->config()->apiVersion(), var_export($bad, true));
        }

        config(['google_ads.api_version' => 'v26']);
        $this->assertSame('v26', $this->config()->apiVersion());
    }

    public function test_developer_token_is_only_present_when_non_empty(): void
    {
        foreach ([null, '', '   ', 123, false] as $absent) {
            config(['google_ads.developer_token' => $absent]);
            $this->assertNull($this->config()->developerToken(), var_export($absent, true));
        }

        config(['google_ads.developer_token' => ' tok-123 ']);
        $this->assertSame('tok-123', $this->config()->developerToken());
    }

    public function test_the_fake_driver_is_refused_in_production(): void
    {
        config(['google_ads.driver' => 'fake']);

        $this->assertSame('fake', $this->config()->driver(), 'the fake driver is allowed outside production');

        $this->app['env'] = 'production';

        try {
            $this->config()->driver();
            $this->fail('driver=fake must be refused in production');
        } catch (GoogleAdsConfigurationException $e) {
            $this->assertSame(GoogleAdsConfigurationException::FAKE_DRIVER_IN_PRODUCTION, $e->reason);
            $this->assertStringNotContainsString('fake-', $e->customerMessage());
        }

        // And the container refuses to hand out a client rather than serve fixtures.
        $this->expectException(GoogleAdsConfigurationException::class);
        app(GoogleAdsReadClient::class);
    }

    public function test_an_unknown_driver_value_means_the_real_http_driver(): void
    {
        foreach (['FAKE ', 'Fake'] as $spelling) {
            config(['google_ads.driver' => $spelling]);
            $this->assertSame('fake', $this->config()->driver());
        }

        foreach (['live', '', null, 42, 'httpp'] as $other) {
            config(['google_ads.driver' => $other]);
            $this->assertSame('http', $this->config()->driver(), var_export($other, true));
        }
    }

    public function test_the_container_binds_the_interface_by_driver(): void
    {
        config(['google_ads.driver' => 'http']);
        $this->assertInstanceOf(HttpGoogleAdsAuthClient::class, app(GoogleAdsAuthClient::class));
        $this->assertInstanceOf(HttpGoogleAdsReadClient::class, app(GoogleAdsReadClient::class));
        $this->assertInstanceOf(HttpGoogleAdsMutationClient::class, app(GoogleAdsMutationClient::class));

        config(['google_ads.driver' => 'fake']);
        $this->assertInstanceOf(FakeGoogleAdsClient::class, app(GoogleAdsReadClient::class));
        $this->assertSame(app(GoogleAdsAuthClient::class), app(GoogleAdsReadClient::class), 'one shared in-memory provider');
        $this->assertSame(app(GoogleAdsMutationClient::class), app(GoogleAdsReadClient::class));
        $this->assertNotEmpty(
            app(FakeGoogleAdsClient::class)->keywordsOf(PhotoBoothFixture::CUSTOMER_ID),
            'the fake driver is preloaded with the PhotoBooth fixture',
        );
    }

    // -------- OAuth config --------

    private function oauth(): GoogleAdsOAuthConfig
    {
        if (! Route::has(GoogleAdsOAuthConfig::CALLBACK_ROUTE)) {
            Route::get('ads/oauth/callback', fn () => 'ok')->name(GoogleAdsOAuthConfig::CALLBACK_ROUTE);
            app('router')->getRoutes()->refreshNameLookups();
        }

        return new GoogleAdsOAuthConfig();
    }

    public function test_oauth_config_requires_every_credential_and_the_exact_fixed_callback(): void
    {
        $oauth = $this->oauth();
        $callback = route(GoogleAdsOAuthConfig::CALLBACK_ROUTE);

        $this->assertSame('ads/oauth/callback', ltrim((string) parse_url($callback, PHP_URL_PATH), '/'));

        $set = fn (?string $id, ?string $secret, ?string $redirect) => config([
            'services.google_ads.client_id' => $id,
            'services.google_ads.client_secret' => $secret,
            'services.google_ads.redirect' => $redirect,
        ]);

        $expect = function (string $reason) use ($oauth): void {
            try {
                $oauth->assertUsable();
                $this->fail('expected ' . $reason);
            } catch (GoogleAdsConfigurationException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertFalse($oauth->isUsable());
            }
        };

        $set(null, 's', $callback);
        $expect(GoogleAdsConfigurationException::MISSING_CLIENT_ID);

        $set('id', '  ', $callback);
        $expect(GoogleAdsConfigurationException::MISSING_CLIENT_SECRET);

        $set('id', 's', null);
        $expect(GoogleAdsConfigurationException::MISSING_REDIRECT);

        $set('id', 's', 'http://app.example.test/ads/oauth/callback');
        $expect(GoogleAdsConfigurationException::REDIRECT_NOT_HTTPS);

        $set('id', 's', 'https://app.example.test/ads/oauth/callback/extra');
        $expect(GoogleAdsConfigurationException::REDIRECT_MISMATCH);

        $set('id', 's', $callback . '?x=1');
        $expect(GoogleAdsConfigurationException::REDIRECT_MISMATCH);

        // A tenant-scoped path can never match the one fixed callback.
        $set('id', 's', 'https://' . parse_url($callback, PHP_URL_HOST) . '/ws/biz/ads/oauth/callback');
        $expect(GoogleAdsConfigurationException::REDIRECT_MISMATCH);

        $set('id', 's', $callback);
        $oauth->assertUsable();
        $this->assertTrue($oauth->isUsable());
    }

    public function test_configuration_failures_never_reveal_a_credential_value(): void
    {
        config(['services.google_ads.client_secret' => 'super-secret-value', 'services.google_ads.client_id' => null]);

        try {
            $this->oauth()->assertUsable();
            $this->fail('expected a configuration failure');
        } catch (GoogleAdsConfigurationException $e) {
            $this->assertStringNotContainsString('super-secret-value', $e->getMessage() . $e->operatorMessage() . $e->customerMessage());
            $this->assertStringNotContainsString('GOOGLE_ADS', $e->customerMessage(), 'a customer is never told which setting is wrong');
        }
    }
}
