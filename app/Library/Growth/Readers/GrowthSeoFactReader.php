<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\SeoAuditSeverity;
use App\Enums\Seo\SeoKeywordCoverageStatus;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Seo\SeoAuditPageReader;
use App\Library\Seo\SeoKeywordCoverageReader;
use App\Library\Seo\SeoPublishedContentReader;
use App\Models\Business;
use App\Models\SeoKeyword;
use Carbon\CarbonImmutable;

/**
 * SEO facts, built ONLY from the canonical SEO readers' own outputs
 * (SeoKeywordCoverageReader, SeoPublishedContentReader, SeoAuditPageReader).
 * Growth adds no SEO logic of its own and calls no provider: coverage is
 * judged against the immutable published Website revision, and audit findings
 * are rows the audit already wrote.
 *
 * Constant queries: active keywords (1), the published content (2 at most),
 * the audit page (3 at most) — independent of keyword or page count.
 *
 * Fact shape (domain `seo`):
 *   keyword_count     int   active tracked keywords (capped)
 *   coverage_known    bool  a published Website exists to judge coverage against
 *   covered_count     int
 *   not_covered       array<int, array{count, phrases}>  keyed by Location id (0 = none)
 *   audit_ran         bool  an audit has run for the published revision
 *   audit_findings    array{critical: int, warning: int, rules: array<int,string>}
 *
 * RANK 11-20 / RANK DROP / SEARCH CONSOLE are NOT here: current main stores no
 * rank observations and no Search Console data. When those modules merge they
 * add NORMALIZED CACHED facts to this reader (never a provider call).
 */
final class GrowthSeoFactReader implements GrowthFactReader
{
    private const KEYWORD_CAP = 500;

    private const PHRASE_CAP = 5;

    public function __construct(
        private readonly SeoKeywordCoverageReader $coverage,
        private readonly SeoPublishedContentReader $content,
        private readonly SeoAuditPageReader $audit,
    ) {
    }

    public function domain(): string
    {
        return 'seo';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::SeoModule;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $keywords = SeoKeyword::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            ->orderBy('id')
            ->limit(self::KEYWORD_CAP)
            ->get(['id', 'phrase', 'phrase_normalized', 'business_location_id']);

        $published = $this->content->forBusiness($business);
        $results = $this->coverage->forKeywords($keywords, $published);

        $covered = 0;
        $notCovered = [];

        foreach ($keywords as $keyword) {
            $status = $results[(int) $keyword->id]->status;

            if ($status === SeoKeywordCoverageStatus::Covered) {
                $covered++;
            } elseif ($status === SeoKeywordCoverageStatus::NotCovered) {
                $key = $keyword->business_location_id === null ? 0 : (int) $keyword->business_location_id;
                $notCovered[$key] ??= ['count' => 0, 'phrases' => []];
                $notCovered[$key]['count']++;

                if (count($notCovered[$key]['phrases']) < self::PHRASE_CAP) {
                    $notCovered[$key]['phrases'][] = (string) $keyword->phrase;
                }
            }
        }

        $page = $this->audit->read($business);
        $critical = 0;
        $warning = 0;
        $rules = [];

        foreach ($page->findings as $finding) {
            if ($finding->severity === SeoAuditSeverity::Critical) {
                $critical++;
            } elseif ($finding->severity === SeoAuditSeverity::Warning) {
                $warning++;
            } else {
                continue;
            }

            $rules[$finding->ruleKey] = true;
        }

        return GrowthFactSet::available($this->domain(), [
            'keyword_count' => $keywords->count(),
            'coverage_known' => $published !== null,
            'covered_count' => $covered,
            'not_covered' => $notCovered,
            'audit_ran' => $page->hasRun(),
            'audit_findings' => ['critical' => $critical, 'warning' => $warning, 'rules' => array_slice(array_keys($rules), 0, 10)],
        ]);
    }
}
