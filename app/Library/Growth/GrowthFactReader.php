<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\Business;
use Carbon\CarbonImmutable;

/**
 * One bounded domain reader (Growth Center §49). It turns a Business's rows
 * into a plain-array fact set ONCE per evaluation; rules never query.
 *
 * A reader reads platform-owned tables and canonical module readers ONLY. It
 * must never call a provider or an AI (Growth Center §15, §79, §80) — the
 * Ads / rank / Search Console seams below are interfaces precisely so that
 * when those modules merge, their NORMALIZED CACHED facts are read, never
 * their providers.
 */
interface GrowthFactReader
{
    /** The fact domain this reader fills (matches GrowthRuleDefinition::$domain). */
    public function domain(): string;

    /**
     * The entitlement this domain needs, or null when it needs nothing beyond
     * the AI COO feature the Growth Center itself sits behind. A Business
     * without it gets NotEntitled — and the module being not-yet-Available in
     * PlatformFeatureRegistry yields Unavailable.
     */
    public function feature(): ?PlatformFeature;

    /**
     * Read the facts. Only called when the domain is available and entitled.
     * Throwing marks the domain failed for this evaluation (the dependent
     * worker run fails; other workers and stored Opportunities are untouched).
     */
    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet;
}
