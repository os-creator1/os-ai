<?php

namespace App\Library\MetaAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsPeriod;
use App\Models\Business;
use App\Models\MetaAdsAccount;
use Carbon\CarbonImmutable;

/**
 * Meta Ads Module V1 contract 24 §5.3 — the reporting periods for a Meta ad
 * account. The period ARITHMETIC (last_7, last_30, this_month,
 * previous_month, comparison window, inclusive local dates) is provider
 * neutral and is REUSED from GoogleAdsPeriod::resolveIn() by composition;
 * GoogleAdsPeriod::resolve() takes a GoogleAdsAccount, so this thin class
 * only supplies the Meta account's time zone (account zone, else Business
 * zone, else UTC) and returns the neutral value object.
 */
final class MetaAdsPeriod
{
    private function __construct()
    {
    }

    public static function resolve(?string $key, MetaAdsAccount $account, ?CarbonImmutable $now = null): GoogleAdsPeriod
    {
        return GoogleAdsPeriod::resolveIn($key, self::timezoneFor($account), $now);
    }

    /** Account zone, else Business zone, else UTC. */
    public static function timezoneFor(MetaAdsAccount $account): string
    {
        $zone = trim((string) $account->time_zone);

        if (GoogleAdsPeriod::isValidTimezone($zone)) {
            return $zone;
        }

        $business = trim((string) Business::query()->whereKey($account->business_id)->value('timezone'));

        return GoogleAdsPeriod::isValidTimezone($business) ? $business : 'UTC';
    }
}
