<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Seo\Content\ArticleFreshness;
use App\Library\Seo\Content\ArticleOpportunityEngine;
use App\Library\Seo\Content\ArticleRankSignals;
use App\Models\Business;
use App\Models\WebsiteArticle;
use Carbon\CarbonImmutable;

/**
 * SEO Content (blog) facts for the Growth Center. The content engine owns the FACTS; Growth Center owns the
 * recommendation lifecycle (ContentRules → the one Opportunity Engine) — there is no content recommendation
 * table. Built only from the content engine's own deterministic readers:
 * ArticleOpportunityEngine, ArticleFreshness and ArticleRankSignals. No AI, no provider call: rank facts are the
 * observations rank tracking already stored.
 *
 * Fact shape (domain `content`):
 *   website_published   bool   a published Website exists to write for
 *   article_count       int    published articles
 *   topics_not_covered  array{count, titles}   informational topics worth an article that nothing covers yet
 *   near_page_one       array{count, titles}   published articles whose tracked search sits around positions 8-20
 *   rank_declined       array{count, titles}   articles whose tracked position got materially worse
 *   stale               array{count, titles}   articles due for review (old, changed package, changed page)
 *   performing_well     array{count, titles}   articles whose tracked search is in the top 5
 */
final class GrowthContentFactReader implements GrowthFactReader
{
    private const TITLE_CAP = 3;

    public function __construct(
        private readonly ArticleOpportunityEngine $opportunities,
        private readonly ArticleFreshness $freshness,
        private readonly ArticleRankSignals $rankSignals,
    ) {
    }

    public function domain(): string
    {
        return 'content';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::SeoModule;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $opportunities = $this->opportunities->forBusiness($business);
        $notCovered = array_values(array_filter($opportunities, fn (array $o) => $o['status'] === 'not_covered'));

        $signals = $this->rankSignals->forBusiness($business);
        $near = array_values(array_filter($signals, fn (array $s) => $s['kind'] === ArticleRankSignals::KIND_NEAR_PAGE_ONE));
        $declined = array_values(array_filter($signals, fn (array $s) => in_array($s['kind'], [ArticleRankSignals::KIND_DECLINED, ArticleRankSignals::KIND_STALE_AND_DECLINED], true)));
        $stale = $this->freshness->forBusiness($business, $now);
        $well = $this->rankSignals->performingWell($business);

        return GrowthFactSet::available($this->domain(), [
            'website_published' => $opportunities !== [],
            'article_count' => WebsiteArticle::query()->where('business_id', $business->id)->published()->count(),
            'topics_not_covered' => $this->bucket(array_column($notCovered, 'title')),
            'near_page_one' => $this->bucket(array_column($near, 'title')),
            'rank_declined' => $this->bucket(array_column($declined, 'title')),
            'stale' => $this->bucket(array_column($stale, 'title')),
            'performing_well' => $this->bucket(array_column($well, 'title')),
        ]);
    }

    /**
     * @param  array<int, string>  $titles
     * @return array{count: int, titles: array<int, string>}
     */
    private function bucket(array $titles): array
    {
        return ['count' => count($titles), 'titles' => array_slice(array_values($titles), 0, self::TITLE_CAP)];
    }
}
