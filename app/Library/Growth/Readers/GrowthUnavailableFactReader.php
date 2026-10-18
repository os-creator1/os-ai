<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Growth\GrowthFactStatus;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * The activation seam for a domain whose module is NOT on this branch's main
 * (or has no Growth reader yet): Google Ads (`ads`) and Search Console
 * (`search_console`). It reports the domain as Unavailable — which EXCLUDES
 * its rules and its score category — instead of reading zero. (`rank` has its
 * own reader, GrowthRankFactReader, over the rank module's stored observations.)
 *
 * When one of those modules merges, replace the registration of the matching
 * instance in GrowthFactSnapshotBuilder with a reader that returns that
 * module's NORMALIZED, CACHED facts (and reports NotConnected for a Business
 * that has not connected it). That reader must read the module's own stored
 * rows only: Growth never calls Google Ads, Search Console, GBP or DataForSEO
 * itself, because evaluating the Growth Center must cost nothing in provider
 * usage (Growth Center §15, §79, §80).
 *
 * It deliberately never produces a customer-facing "Connect Ads" opportunity;
 * an unavailable module is silent.
 */
final class GrowthUnavailableFactReader implements GrowthFactReader
{
    public function __construct(private readonly string $domain)
    {
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function feature(): ?PlatformFeature
    {
        return null;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        return GrowthFactSet::withStatus($this->domain, GrowthFactStatus::Unavailable);
    }

    public function alwaysUnavailable(): bool
    {
        return true;
    }
}
