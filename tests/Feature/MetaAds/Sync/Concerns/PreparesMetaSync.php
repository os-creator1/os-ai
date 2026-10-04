<?php

namespace Tests\Feature\MetaAds\Sync\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Library\MetaAds\Contracts\MetaReadClient;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Library\MetaAds\Sync\MetaAdsSyncCoordinator;
use App\Library\MetaAds\Sync\MetaAdsSyncEligibility;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\MetaAdsAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Concerns\CreatesMetaAdsFixtures;
use Tests\Feature\MetaAds\Sync\Support\HookedMetaClient;
use Tests\Feature\MetaAds\Sync\Support\StubbedMetaSyncEligibility;

/**
 * Shared setup for the Meta sync suites. Call prepareSync() from setUp(): it
 * pins time to a fixed day, binds the fake provider (behind a hook decorator)
 * and the stubbed entitlement.
 */
trait PreparesMetaSync
{
    use CreatesMetaAdsFixtures;

    protected const FIXTURE_TODAY = '2026-10-04';

    protected HookedMetaClient $hook;

    protected MetaPhotoBoothFixture $fixture;

    protected function prepareSync(): void
    {
        Carbon::setTestNow(self::FIXTURE_TODAY . ' 12:00:00');
        StubbedMetaSyncEligibility::$deniedBusinessIds = [];

        $this->bindFakeMeta();
        $this->fixture = new MetaPhotoBoothFixture();
        $this->hook = new HookedMetaClient($this->fakeMeta);

        $this->app->instance(MetaReadClient::class, $this->hook);
        $this->app->instance(MetaAuthClient::class, $this->hook);
        $this->app->bind(MetaAdsSyncEligibility::class, StubbedMetaSyncEligibility::class);
    }

    /** @return array{0: Business, 1: BusinessMetaConnection, 2: MetaAdsAccount} */
    protected function syncableAccount(string $name = 'Snap Booth Co', string $adAccountId = MetaPhotoBoothFixture::AD_ACCOUNT_ID): array
    {
        [, $business] = $this->metaTenant($name);

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        $business = $business->fresh();
        $connection = $this->activeMetaConnection($business);
        $account = $this->selectedMetaAccount($business, ['ad_account_id' => $adAccountId, 'name' => 'Old name']);

        return [$business, $connection, $account];
    }

    protected function coordinator(): MetaAdsSyncCoordinator
    {
        return app(MetaAdsSyncCoordinator::class);
    }

    /** Moves the clock forward (a later run must not share the previous run's second). */
    protected function advance(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    /**
     * A content checksum of one account's synced facts, excluding the
     * timestamps and surrogate ids a re-run legitimately refreshes.
     *
     * @return array<string, string>
     */
    protected function factChecksums(int $accountId): array
    {
        $sums = [];

        foreach (['meta_ads_campaigns', 'meta_ads_ad_sets', 'meta_ads_ads', 'meta_ads_daily_insights', 'meta_ads_daily_results'] as $table) {
            $rows = DB::table($table)->where('meta_ads_account_id', $accountId)->orderBy('id')->get()
                ->map(static function ($row): array {
                    $row = (array) $row;
                    unset($row['id'], $row['uid'], $row['created_at'], $row['updated_at'], $row['last_synced_at']);
                    ksort($row);

                    return $row;
                })->all();

            $sums[$table] = count($rows) . ':' . md5(json_encode($rows));
        }

        return $sums;
    }
}
