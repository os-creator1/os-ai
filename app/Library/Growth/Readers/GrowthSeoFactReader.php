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
use App\Library\Seo\SeoPhraseNormalizer;
use App\Library\Seo\SeoPublishedContentReader;
use App\Models\Business;
use App\Models\BusinessLocation;
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
 * the audit page (6 at most) — independent of keyword or page count.
 *
 * Fact shape (domain `seo`):
 *   keyword_count        int   active tracked keywords on an operational Location or Business-wide (capped)
 *   coverage_known       bool  a published Website exists to judge coverage against
 *   covered_count        int   keywords found on a page search engines can list
 *   not_covered          array<int, array{count, phrases}>  per-Location breakdown (0 = Business-wide)
 *   not_covered_total    int   DISTINCT phrases (SeoPhraseNormalizer) found on no page — one site-wide gap, however many Locations repeat it
 *   not_covered_examples array<int, string>  up to 5 of those phrases from Business-wide keywords only (a Location's own keyword text never rides a Business-wide finding)
 *   not_covered_ids      array<int, int>  keyword ids behind not_covered_total (other rules use it to avoid reporting the same root problem twice)
 *   audit_ran            bool  a COMPLETED audit exists for the CURRENTLY published revision (a failed run, another revision's run, or no published site is false)
 *   audit_findings       array{critical: int, warning: int, rules: array<int,string>}
 *
 * A phrase found only on pages hidden from search is neither covered nor
 * "not mentioned": it is left out of both counts (the site's hidden pages are
 * the cause, not the keyword).
 *
 * Rank and Search Console are separate domains: `rank` (GrowthRankFactReader,
 * stored observations only) and `search_console` (still unavailable).
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
        $activeLocationIds = BusinessLocation::query()
            ->select('id')
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active');

        $keywords = SeoKeyword::query()
            ->where('business_id', $business->id)
            ->where('lifecycle_state', 'active')
            // A keyword on an archived Location is no longer worked on anywhere: it
            // is not a gap to report. Business-wide keywords (no Location) always count.
            ->where(fn ($q) => $q->whereNull('business_location_id')->orWhereIn('business_location_id', $activeLocationIds))
            ->orderBy('id')
            ->limit(self::KEYWORD_CAP)
            ->get(['id', 'phrase', 'phrase_normalized', 'business_location_id']);

        $published = $this->content->forBusiness($business);
        $results = $this->coverage->forKeywords($keywords, $published);

        $covered = 0;
        $notCovered = [];
        $distinct = [];
        $examples = [];
        $ids = [];

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

                $ids[] = (int) $keyword->id;
                $normalized = SeoPhraseNormalizer::normalize((string) $keyword->phrase);

                if (! isset($distinct[$normalized])) {
                    $distinct[$normalized] = true;

                    if ($key === 0 && count($examples) < self::PHRASE_CAP) {
                        $examples[] = (string) $keyword->phrase;
                    }
                }
            }
        }

        $page = $this->audit->read($business);
        $critical = 0;
        $warning = 0;
        $rules = [];

        // Findings are only ever read from a COMPLETED run of the published revision.
        if ($page->completedForPublishedVersion()) {
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
        }

        return GrowthFactSet::available($this->domain(), [
            'keyword_count' => $keywords->count(),
            'coverage_known' => $published !== null,
            'covered_count' => $covered,
            'not_covered' => $notCovered,
            'not_covered_total' => count($distinct),
            'not_covered_examples' => $examples,
            'not_covered_ids' => $ids,
            'audit_ran' => $page->completedForPublishedVersion(),
            'audit_findings' => ['critical' => $critical, 'warning' => $warning, 'rules' => array_slice(array_keys($rules), 0, 10)],
        ]);
    }
}
