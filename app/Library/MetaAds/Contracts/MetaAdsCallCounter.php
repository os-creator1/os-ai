<?php

namespace App\Library\MetaAds\Contracts;

use App\Exceptions\MetaAds\MetaProviderException;

/**
 * Meta Ads Module V1 contract §6 — the per-Business hourly call budget seam.
 * Every real client AND the Fake calls reserve() immediately BEFORE every
 * outbound request (one per page). The implementation (built by the sync lane)
 * throws MetaProviderException::budgetExhausted() when the budget is spent, so
 * an exhausted budget makes ZERO provider calls.
 */
interface MetaAdsCallCounter
{
    /**
     * @throws MetaProviderException budget_exhausted
     */
    public function reserve(): void;
}
