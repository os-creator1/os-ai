<?php

namespace Tests\Feature\GoogleAds\Sync\Concerns;

use App\Enums\Business\BusinessStatus;
use App\Library\GoogleAds\Contracts\GoogleAdsAuthClient;
use App\Library\GoogleAds\Contracts\GoogleAdsReadClient;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Library\GoogleAds\Sync\GoogleAdsSyncCoordinator;
use App\Library\GoogleAds\Sync\GoogleAdsSyncEligibility;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\GoogleAdsAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleAds\Concerns\CreatesGoogleAdsFixtures;
use Tests\Feature\GoogleAds\Sync\Support\HookedAdsClient;
use Tests\Feature\GoogleAds\Sync\Support\StubbedSyncEligibility;

/**
 * Shared setup for the sync suites. Call prepareSync() from setUp(): it pins
 * time to the PhotoBooth fixture day, binds the fake provider (behind a hook
 * decorator) and the stubbed entitlement.
 */
trait PreparesAdsSync
{
    use CreatesGoogleAdsFixtures;

    protected HookedAdsClient $hook;

    protected PhotoBoothFixture $fixture;

    protected function prepareSync(): void
    {
        Carbon::setTestNow(self::FIXTURE_TODAY . ' 12:00:00');
        StubbedSyncEligibility::$deniedBusinessIds = [];

        $this->bindFakeAds();
        $this->fixture = new PhotoBoothFixture(self::FIXTURE_TODAY);
        $this->hook = new HookedAdsClient($this->fakeAds);

        $this->app->instance(GoogleAdsReadClient::class, $this->hook);
        $this->app->instance(GoogleAdsAuthClient::class, $this->hook);
        $this->app->bind(GoogleAdsSyncEligibility::class, StubbedSyncEligibility::class);
    }

    /** @return array{0: Business, 1: BusinessGoogleConnection, 2: GoogleAdsAccount} */
    protected function syncableAccount(string $name = 'Snap Booth Co', string $customerId = PhotoBoothFixture::CUSTOMER_ID): array
    {
        [, $business] = $this->adsTenant($name);

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        $business = $business->fresh();
        $connection = $this->activeAdsConnection($business);

        $account = GoogleAdsAccount::create([
            'business_id' => $business->id,
            'business_google_connection_id' => $connection->id,
            'customer_id' => $customerId,
            'login_customer_id' => $customerId === PhotoBoothFixture::CUSTOMER_ID ? PhotoBoothFixture::MANAGER_ID : null,
            'descriptive_name' => 'Old name',
            'currency_code' => PhotoBoothFixture::CURRENCY,
            'time_zone' => PhotoBoothFixture::TIME_ZONE,
            'selected_at' => now(),
        ]);

        return [$business, $connection, $account];
    }

    protected function coordinator(): GoogleAdsSyncCoordinator
    {
        return app(GoogleAdsSyncCoordinator::class);
    }

    /** Moves the clock forward (a later run must not share the previous run's second). */
    protected function advance(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
    }

    /**
     * A content checksum of one account's synced facts, excluding the
     * timestamps a re-run legitimately refreshes.
     *
     * @return array<string, string>
     */
    protected function factChecksums(int $accountId): array
    {
        $sums = [];

        foreach (['google_ads_campaigns', 'google_ads_ad_groups', 'google_ads_keywords', 'google_ads_daily_metrics', 'google_ads_search_terms'] as $table) {
            $rows = DB::table($table)->where('google_ads_account_id', $accountId)->orderBy('id')->get()
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
