<?php

namespace App\Library\GoogleAds\Sync\Contracts;

use App\DTO\GoogleAds\GoogleAdsReportResult;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Sync\GoogleAdsStageResult;
use App\Library\GoogleAds\Sync\GoogleAdsSyncContext;

/**
 * One step of the sync. The two halves are separate so the coordinator can
 * run fetch() inside the call-budget operation context and persist() with no
 * provider access and no surrounding transaction: contract §5 forbids a
 * network call inside a DB transaction.
 */
interface GoogleAdsSyncStage
{
    /** Stable key (`campaigns`, `keywords`, ...) passed to sync observers. */
    public function key(): string;

    /**
     * The ONLY place a stage talks to Google.
     *
     * @throws GoogleAdsProviderException
     */
    public function fetch(GoogleAdsSyncContext $context): GoogleAdsReportResult;

    /** Writes the fetched rows in short, chunked transactions. */
    public function persist(GoogleAdsSyncContext $context, GoogleAdsReportResult $report): GoogleAdsStageResult;
}
