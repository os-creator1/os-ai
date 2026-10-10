<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Seo\SeoPublishedContentReader;
use App\Library\Website\WebsiteCatalogReferences;
use App\Models\Website;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Website facts from the Website table itself — Growth never crawls or
 * fetches a public URL (Growth Center §18: the Website snapshot is the
 * authority). Two queries.
 *
 * Fact shape (domain `website`):
 *   has_external_site   bool   the Business profile already carries a website_url
 *   exists              bool   a Website row exists
 *   status              string|null  draft | published | archived
 *   published           bool   status = published AND a published revision exists
 *
 * `has_external_site` matters: a Business that runs its marketing from a site
 * it hosts elsewhere must not be told its platform Website is unpublished.
 *
 * Package sync (`package_changed_count`, `package_removed_count`) is the
 * Website module's own verdict, WebsiteCatalogReferences::staleness() — Growth
 * counts what it reports and compares nothing itself.
 *
 * Search visibility (`home_hidden_from_search`) is the owner's own per-page setting from the
 * published snapshot. Starter pages are generated hidden by design, so only a hidden HOME page is a
 * finding; the platform-path noindex is a status, never counted.
 *
 * External Website Audit Mode V1 adds, additively:
 *   mode            string|null  hosted | external | none (the Business's primary website source; null = not chosen)
 *   external        array|null   for an `external` Business with a finished crawl: critical, issues (warning + info),
 *                                broken_links, indexability, pages, checked_at; null otherwise
 *   missing_landing int          active goals whose website intent has no landing destination chosen — an ACQUISITION
 *                                recommendation seam, never a technical finding
 * Growth reads these stored values only; it fetches nothing and the external site is audited by the one SEO engine.
 *
 * DEFERRED: "high-priority service area page not published" (no canonical seam).
 */
final class GrowthWebsiteFactReader implements GrowthFactReader
{
    public function __construct(
        private readonly WebsiteCatalogReferences $catalogReferences,
        private readonly SeoPublishedContentReader $publishedContent,
    ) {
    }

    public function domain(): string
    {
        return 'website';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::WebsiteGeneration;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $website = DB::table('websites')
            ->where('business_id', $business->id)
            ->first(['status', 'published_revision_id']);
        $published = $website !== null && $website->status === 'published' && $website->published_revision_id !== null;
        $staleness = ['changed' => [], 'removed' => []];
        $hiddenPages = 0;
        $pageCount = 0;
        $homeHidden = false;

        if ($published) {
            $content = $this->publishedContent->forBusiness($business);

            foreach ($content?->pages ?? [] as $page) {
                $pageCount++;
                $hiddenPages += $page->noindex ? 1 : 0;
                $homeHidden = $homeHidden || ($page->isHome && $page->noindex);
            }

            $model = Website::query()->where('business_id', $business->id)->first();
            $staleness = $model !== null ? $this->catalogReferences->staleness($model) : $staleness;
        }

        $mode = app(\App\Library\Website\WebsiteModeManager::class)->resolve($business);
        $external = null;

        if ($mode === \App\Enums\Website\WebsiteMode::External) {
            $crawl = app(\App\Library\ExternalSite\ExternalWebsiteReader::class)->latestCompleted($business);

            $external = $crawl === null ? null : [
                'critical' => (int) $crawl->critical_count,
                'issues' => (int) $crawl->warning_count + (int) $crawl->info_count,
                'broken_links' => (int) $crawl->broken_links,
                'indexability' => (string) $crawl->indexability,
                'pages' => (int) $crawl->pages_fetched,
                'checked_at' => $crawl->finished_at?->toIso8601String(),
            ];
        }

        return GrowthFactSet::available($this->domain(), [
            'mode' => $mode?->value,
            'external' => $external,
            'missing_landing' => app(\App\Library\Acquisition\PurposeWebsiteIntents::class)->withoutDestination($business)->count(),
            'has_external_site' => trim((string) $business->website_url) !== '',
            'exists' => $website !== null,
            'status' => $website?->status,
            'published' => $published,
            'package_changed_count' => count($staleness['changed']),
            'package_removed_count' => count($staleness['removed']),
            'page_count' => $pageCount,
            'pages_hidden_from_search' => $hiddenPages,
            'home_hidden_from_search' => $homeHidden,
        ]);
    }
}
