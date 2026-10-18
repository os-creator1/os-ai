<?php

namespace Tests\Feature\MetaAds\Reporting\Concerns;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Enums\MetaAds\MetaConnectionState;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\Customer;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use App\Models\MetaAdsDailyInsight;
use App\Models\MetaAdsDailyResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;

/**
 * Seeds NORMALISED Meta Ads rows directly (no provider, no sync code) so the
 * reporting readers are tested in isolation. Self-contained on purpose: it
 * does not depend on any other Meta fixtures trait.
 *
 * The clock is pinned by tests to FIXTURE_NOW (2026-10-04 12:00 UTC = 08:00 on
 * the 4th in the default New York account zone). Entities are keyed by LOCAL
 * ids exactly as the readers expect (entity_id = local campaign / ad set / ad
 * id). Provider ids are generated unique per account.
 */
trait SeedsMetaAdsReportingData
{
    use CreatesBusinessTestData;

    protected const FIXTURE_NOW = '2026-10-04 12:00:00';

    protected const RESULT_TYPE = 'onsite_conversion.lead_grouped';

    private int $metaSeedCounter = 0;

    protected function pinMetaClock(string $now = self::FIXTURE_NOW): CarbonImmutable
    {
        $at = CarbonImmutable::parse($now, 'UTC');
        CarbonImmutable::setTestNow($at);
        \Illuminate\Support\Carbon::setTestNow($at);

        return $at;
    }

    protected function unpinMetaClock(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
    }

    /** @return array{0: Customer, 1: Business} */
    protected function metaReportingTenant(string $name = 'Snap Booth Co'): array
    {
        $this->ensureMetaReportingConfigRows();

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

    protected function ensureMetaReportingConfigRows(): void
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

    protected function metaReportingAccountFor(Business $business, array $overrides = []): MetaAdsAccount
    {
        $connection = BusinessMetaConnection::create([
            'business_id' => $business->id,
            'state' => MetaConnectionState::Active,
            'access_token_encrypted' => 'plain-meta-test-token',
            'granted_scopes' => 'ads_read,ads_management',
            'meta_user_id' => 'meta-user-' . $business->id,
            'meta_user_name' => 'Owner',
            'connected_at' => now(),
            'connected_by_user_id' => $business->customer_id,
        ]);

        return MetaAdsAccount::create(array_merge([
            'business_id' => $business->id,
            'business_meta_connection_id' => $connection->id,
            'ad_account_id' => (string) (1000000000 + $business->id),
            'name' => 'Snap Booth Ads',
            'currency_code' => 'USD',
            'time_zone' => 'America/New_York',
            'account_status' => 1,
            'result_action_type' => self::RESULT_TYPE,
            'selected_at' => now(),
        ], $overrides));
    }

    protected function seedMetaCampaign(MetaAdsAccount $account, string $name, array $overrides = []): MetaAdsCampaign
    {
        return MetaAdsCampaign::create(array_merge([
            'business_id' => $account->business_id,
            'meta_ads_account_id' => $account->id,
            'external_campaign_id' => 'camp-' . $account->id . '-' . (++$this->metaSeedCounter),
            'name' => $name,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'objective' => 'OUTCOME_LEADS',
            'daily_budget_minor' => 4000,
            'lifetime_budget_minor' => null,
        ], $overrides));
    }

    protected function seedMetaAdSet(MetaAdsCampaign $campaign, string $name, array $overrides = []): MetaAdsAdSet
    {
        return MetaAdsAdSet::create(array_merge([
            'business_id' => $campaign->business_id,
            'meta_ads_account_id' => $campaign->meta_ads_account_id,
            'meta_ads_campaign_id' => $campaign->id,
            'external_ad_set_id' => 'adset-' . $campaign->meta_ads_account_id . '-' . (++$this->metaSeedCounter),
            'name' => $name,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
            'optimization_goal' => 'LEAD_GENERATION',
        ], $overrides));
    }

    protected function seedMetaAd(MetaAdsAdSet $adSet, string $name, array $overrides = []): MetaAdsAd
    {
        return MetaAdsAd::create(array_merge([
            'business_id' => $adSet->business_id,
            'meta_ads_account_id' => $adSet->meta_ads_account_id,
            'meta_ads_campaign_id' => $adSet->meta_ads_campaign_id,
            'meta_ads_ad_set_id' => $adSet->id,
            'external_ad_id' => 'ad-' . $adSet->meta_ads_account_id . '-' . (++$this->metaSeedCounter),
            'name' => $name,
            'status' => 'ACTIVE',
            'effective_status' => 'ACTIVE',
        ], $overrides));
    }

    /** One per-day insight row ($linkClicks null = Meta returned none). */
    protected function seedMetaInsight(
        MetaAdsAccount $account,
        MetaAdsLevel $level,
        int $entityId,
        string $date,
        int $spendMicros,
        int $impressions = 1000,
        int $clicks = 20,
        ?int $linkClicks = 10,
    ): MetaAdsDailyInsight {
        return MetaAdsDailyInsight::create([
            'business_id' => $account->business_id,
            'meta_ads_account_id' => $account->id,
            'level' => $level,
            'entity_id' => $entityId,
            'metric_date' => $date,
            'spend_micros' => $spendMicros,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'link_clicks' => $linkClicks,
        ]);
    }

    /** One per-day typed result row. */
    protected function seedMetaResult(
        MetaAdsAccount $account,
        MetaAdsLevel $level,
        int $entityId,
        string $date,
        string|int|float $results,
        ?string $value = null,
        string $actionType = self::RESULT_TYPE,
    ): MetaAdsDailyResult {
        return MetaAdsDailyResult::create([
            'business_id' => $account->business_id,
            'meta_ads_account_id' => $account->id,
            'level' => $level,
            'entity_id' => $entityId,
            'metric_date' => $date,
            'action_type' => $actionType,
            'results' => $results,
            'result_value' => $value,
        ]);
    }

    /**
     * Insight rows for every day from $from to $to inclusive (and result rows
     * when $results is not null; $results is per day).
     */
    protected function seedMetaDays(
        MetaAdsAccount $account,
        MetaAdsLevel $level,
        int $entityId,
        string $from,
        string $to,
        int $spendMicros,
        string|int|null $results = null,
        ?int $linkClicks = 10,
        int $impressions = 1000,
        int $clicks = 20,
        ?string $value = null,
    ): void {
        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $this->seedMetaInsight($account, $level, $entityId, $day->format('Y-m-d'), $spendMicros, $impressions, $clicks, $linkClicks);

            if ($results !== null) {
                $this->seedMetaResult($account, $level, $entityId, $day->format('Y-m-d'), $results, $value);
            }
        }
    }
}
