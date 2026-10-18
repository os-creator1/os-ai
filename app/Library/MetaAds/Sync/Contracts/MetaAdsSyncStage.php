<?php

namespace App\Library\MetaAds\Sync\Contracts;

use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Sync\MetaAdsStageReport;
use App\Library\MetaAds\Sync\MetaAdsStageResult;
use App\Library\MetaAds\Sync\MetaAdsSyncContext;

/**
 * One step of the sync. The two halves are separate so the coordinator can
 * run fetch() inside the call-budget operation context and persist() with no
 * provider access and no surrounding transaction: contract 24 §6 forbids a
 * network call inside a DB transaction.
 */
interface MetaAdsSyncStage
{
    /** Stable key (`campaigns`, `ad_sets`, ...) passed to sync observers. */
    public function key(): string;

    /**
     * The ONLY place a stage talks to Meta.
     *
     * @throws MetaProviderException
     */
    public function fetch(MetaAdsSyncContext $context): MetaAdsStageReport;

    /** Writes the fetched rows in short, chunked transactions. */
    public function persist(MetaAdsSyncContext $context, MetaAdsStageReport $report): MetaAdsStageResult;
}
