<?php

namespace Tests\Feature\MetaAds;

use App\Exceptions\MetaAds\MetaConfigurationException;
use App\Library\MetaAds\MetaAdsConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Meta Ads Module V1 — config/meta_ads.php is read ONLY through
 * MetaAdsConfig, which fails CLOSED to documented safe defaults.
 */
class MetaAdsConfigTest extends TestCase
{
    private function config(): MetaAdsConfig
    {
        return new MetaAdsConfig();
    }

    public function test_shipped_defaults_match_the_contract(): void
    {
        $c = $this->config();

        $this->assertSame('v26.0', $c->apiVersion());
        $this->assertSame('https://graph.facebook.com', $c->baseUrl());
        $this->assertSame('https://graph.facebook.com/v26.0', $c->versionBase());
        $this->assertSame('https://www.facebook.com', $c->oauthDialogBaseUrl());
        $this->assertSame('http', $c->driver());
        $this->assertSame(7, $c->reauthWarningDays());
        $this->assertSame(24, $c->syncMinIntervalHours());
        $this->assertSame(62, $c->metricsLookbackDays());
        $this->assertSame(500, $c->pageSize());
        $this->assertSame(120, $c->maxCallsPerBusinessPerHour());
        $this->assertSame(60, $c->manualRefreshMinMinutes());
        $this->assertSame(85, $c->usageStopPercent());
        $this->assertSame(7, $c->pacingMinDays());
        $this->assertEqualsWithDelta(0.15, $c->pacingTolerance(), 1e-9);
        $this->assertSame(50_000_000, $c->zeroResultMinSpendMicros());
        $this->assertEqualsWithDelta(1.25, $c->cprOverFactor(), 1e-9);
        $this->assertSame(3, $c->strongMinResults());
        $this->assertEqualsWithDelta(3.0, $c->frequencyThreshold(), 1e-9);
        $this->assertEqualsWithDelta(1.3, $c->fatigueCprWorseningFactor(), 1e-9);
        $this->assertSame(3, $c->fatigueMinResults());
        $this->assertSame(20_000_000, $c->fatigueMinSpendMicros());
        $this->assertSame(['ads_read', 'ads_management'], MetaAdsConfig::SCOPES);
        $this->assertNull($c->appId());
        $this->assertNull($c->appSecret());
        $this->assertNull($c->redirectUri());
    }

    #[DataProvider('badVersions')]
    public function test_a_malformed_api_version_falls_back_to_the_pinned_one(mixed $configured): void
    {
        config(['meta_ads.api_version' => $configured]);

        $this->assertSame('v26.0', $this->config()->apiVersion());
    }

    /** @return array<string, array{0: mixed}> */
    public static function badVersions(): array
    {
        return [
            'no minor' => ['v26'],
            'no v' => ['26.0'],
            'injection' => ['v26.0/../x'],
            'empty' => [''],
            'null' => [null],
            'array' => [['v26.0']],
        ];
    }

    public function test_a_valid_api_version_is_used(): void
    {
        config(['meta_ads.api_version' => 'v27.1']);

        $this->assertSame('v27.1', $this->config()->apiVersion());
    }

    #[DataProvider('badUrls')]
    public function test_a_non_https_or_malformed_base_url_is_replaced_by_the_default(mixed $configured): void
    {
        config(['meta_ads.base_url' => $configured, 'meta_ads.oauth_dialog_base_url' => $configured]);

        $this->assertSame('https://graph.facebook.com', $this->config()->baseUrl());
        $this->assertSame('https://www.facebook.com', $this->config()->oauthDialogBaseUrl());
    }

    /** @return array<string, array{0: mixed}> */
    public static function badUrls(): array
    {
        return [
            'plain http' => ['http://graph.facebook.com'],
            'with query' => ['https://graph.facebook.com?x=1'],
            'with credentials' => ['https://user@graph.facebook.com'],
            'fragment' => ['https://graph.facebook.com#a'],
            'not a url' => ['graph.facebook.com'],
            'null' => [null],
        ];
    }

    public function test_a_valid_https_base_url_is_normalised(): void
    {
        config(['meta_ads.base_url' => ' https://Graph.Example.test/ ']);

        $this->assertSame('https://graph.example.test', $this->config()->baseUrl());
    }

    public function test_the_fake_driver_is_allowed_outside_production_and_refused_in_production(): void
    {
        config(['meta_ads.driver' => 'fake']);
        $this->assertSame('fake', $this->config()->driver());

        config(['meta_ads.driver' => ' FAKE ']);
        $this->assertSame('fake', $this->config()->driver());

        $this->app['env'] = 'production';

        $this->expectException(MetaConfigurationException::class);

        try {
            $this->config()->driver();
        } catch (MetaConfigurationException $e) {
            $this->assertSame(MetaConfigurationException::FAKE_DRIVER_IN_PRODUCTION, $e->reason);

            throw $e;
        }
    }

    public function test_anything_but_fake_means_the_real_driver(): void
    {
        foreach (['http', 'HTTP', 'whatever', '', null, 5] as $value) {
            config(['meta_ads.driver' => $value]);
            $this->assertSame('http', $this->config()->driver());
        }
    }

    public function test_sync_interval_is_floored_at_twenty_hours(): void
    {
        foreach ([19, 1, 0, -5, '19', 'abc', null, 99999] as $value) {
            config(['meta_ads.sync.min_interval_hours' => $value]);
            $this->assertSame(24, $this->config()->syncMinIntervalHours(), 'rejected: ' . var_export($value, true));
        }

        foreach ([20, '20', 48] as $value) {
            config(['meta_ads.sync.min_interval_hours' => $value]);
            $this->assertSame((int) $value, $this->config()->syncMinIntervalHours());
        }
    }

    public function test_the_page_size_is_capped_at_500(): void
    {
        foreach ([501, 100000, 0, -1, 'x', null] as $value) {
            config(['meta_ads.sync.page_size' => $value]);
            $this->assertSame(500, $this->config()->pageSize());
        }

        config(['meta_ads.sync.page_size' => '100']);
        $this->assertSame(100, $this->config()->pageSize());
    }

    public function test_numeric_settings_fail_closed_to_their_defaults_when_out_of_range(): void
    {
        config([
            'meta_ads.sync.max_calls_per_business_per_hour' => 0,
            'meta_ads.sync.usage_stop_percent' => 101,
            'meta_ads.sync.metrics_lookback_days' => 'many',
            'meta_ads.token.reauth_warning_days' => -3,
            'meta_ads.pacing.tolerance' => 0,
            'meta_ads.recommendations.cpr_over_factor' => 0.5,
            'meta_ads.recommendations.fatigue_cpr_worsening_factor' => 'x',
            'meta_ads.recommendations.zero_result_min_spend_micros' => -1,
        ]);

        $c = $this->config();

        $this->assertSame(120, $c->maxCallsPerBusinessPerHour());
        $this->assertSame(85, $c->usageStopPercent());
        $this->assertSame(62, $c->metricsLookbackDays());
        $this->assertSame(7, $c->reauthWarningDays());
        $this->assertEqualsWithDelta(0.15, $c->pacingTolerance(), 1e-9);
        $this->assertEqualsWithDelta(1.25, $c->cprOverFactor(), 1e-9, '"over target" can never mean at-or-under');
        $this->assertEqualsWithDelta(1.3, $c->fatigueCprWorseningFactor(), 1e-9);
        $this->assertSame(50_000_000, $c->zeroResultMinSpendMicros());
    }

    public function test_numeric_env_strings_are_accepted_when_valid(): void
    {
        config([
            'meta_ads.sync.max_calls_per_business_per_hour' => '60',
            'meta_ads.recommendations.frequency_threshold' => '4.5',
            'meta_ads.recommendations.zero_result_min_spend_micros' => '10000000',
        ]);

        $this->assertSame(60, $this->config()->maxCallsPerBusinessPerHour());
        $this->assertEqualsWithDelta(4.5, $this->config()->frequencyThreshold(), 1e-9);
        $this->assertSame(10_000_000, $this->config()->zeroResultMinSpendMicros());
    }

    public function test_the_result_type_allow_list_is_the_documented_five(): void
    {
        $types = $this->config()->resultTypes();

        $this->assertSame([
            'lead',
            'onsite_conversion.lead_grouped',
            'offsite_conversion.fb_pixel_lead',
            'onsite_conversion.messaging_conversation_started_7d',
            'link_click',
        ], array_keys($types));

        foreach ($types as $label) {
            $this->assertNotSame('', $label);
        }

        $this->assertTrue($this->config()->isResultType('lead'));
        $this->assertFalse($this->config()->isResultType('post_engagement'));
        $this->assertFalse($this->config()->isResultType('purchase'));
    }

    public function test_malformed_result_types_are_dropped_and_an_empty_list_falls_back(): void
    {
        config(['meta_ads.result_types' => [
            'lead' => 'Leads',
            'Bad Type!' => 'Nope',
            'empty_label' => '  ',
            'array_label' => ['x'],
            5 => 'numeric key',
            'purchase' => 'Purchases',
        ]]);

        $this->assertSame(['lead' => 'Leads', 'purchase' => 'Purchases'], $this->config()->resultTypes());

        config(['meta_ads.result_types' => []]);
        $this->assertSame(MetaAdsConfig::DEFAULT_RESULT_TYPES, $this->config()->resultTypes());

        config(['meta_ads.result_types' => 'lead']);
        $this->assertSame(MetaAdsConfig::DEFAULT_RESULT_TYPES, $this->config()->resultTypes());
    }

    public function test_the_thumbnail_host_allow_list(): void
    {
        $c = $this->config();

        $this->assertSame('https://scontent.xx.fbcdn.net/a.jpg', $c->allowedThumbnailUrl('https://scontent.xx.fbcdn.net/a.jpg'));
        $this->assertNull($c->allowedThumbnailUrl('http://scontent.xx.fbcdn.net/a.jpg'));
        $this->assertNull($c->allowedThumbnailUrl('https://example.com/a.jpg'));
        $this->assertNull($c->allowedThumbnailUrl(null));
        $this->assertNull($c->allowedThumbnailUrl('https://scontent.xx.fbcdn.net/' . str_repeat('a', 2100)));

        config(['meta_ads.thumbnail_hosts' => ['Images.Example.test', '*.cdn.example.test', 'not a host', '*.', 42]]);

        $this->assertSame(['images.example.test', '*.cdn.example.test'], $this->config()->thumbnailHosts());
        $this->assertNotNull($this->config()->allowedThumbnailUrl('https://images.example.test/x.png'));
        $this->assertNotNull($this->config()->allowedThumbnailUrl('https://a.cdn.example.test/x.png'));
        $this->assertNull($this->config()->allowedThumbnailUrl('https://cdn.example.test/x.png'));
        $this->assertNull($this->config()->allowedThumbnailUrl('https://scontent.xx.fbcdn.net/x.png'), 'a configured list replaces the defaults');

        config(['meta_ads.thumbnail_hosts' => []]);
        $this->assertSame(MetaAdsConfig::DEFAULT_THUMBNAIL_HOSTS, $this->config()->thumbnailHosts());
    }

    public function test_credentials_come_from_services_and_blank_means_unset(): void
    {
        config(['services.meta_ads.app_id' => ' 123 ', 'services.meta_ads.app_secret' => '', 'services.meta_ads.redirect' => 'https://x.test/cb']);

        $c = $this->config();

        $this->assertSame('123', $c->appId());
        $this->assertNull($c->appSecret());
        $this->assertSame('https://x.test/cb', $c->redirectUri());
    }
}
