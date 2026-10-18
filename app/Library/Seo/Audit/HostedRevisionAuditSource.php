<?php

namespace App\Library\Seo\Audit;

use App\Library\Seo\SeoPublishedContent;
use App\Library\Seo\SeoPublishedPage;
use App\Library\Website\Seo\WebsiteHeadMeta;

/**
 * HOSTED WEBSITE -> revision snapshot -> normalised page facts.
 *
 * Pure translation of the immutable published snapshot (already parsed by
 * SeoPublishedContentReader) into the source-neutral SeoAuditSiteFacts the one
 * SeoAuditEvaluator consumes. It supplies ONLY the facts the hosted rules use
 * and none of the external-only ones (status, headings, canonical, Open Graph,
 * structured data, broken links), because on a hosted site those are the
 * platform's responsibility and are never findings against the customer.
 *
 * Pages the snapshot never identified are dropped here, exactly as the
 * evaluator always skipped them (they cannot be deep-linked to an editor).
 */
final class HostedRevisionAuditSource
{
    public static function factsFor(SeoPublishedContent $content): SeoAuditSiteFacts
    {
        $pages = [];

        foreach ($content->pages as $page) {
            $uid = trim($page->uid);

            if ($uid === '') {
                continue;
            }

            $pages[] = self::page($page, $uid, $content->siteName);
        }

        return new SeoAuditSiteFacts($pages, count($content->assetsMissingAltText()));
    }

    private static function page(SeoPublishedPage $page, string $uid, string $siteName): SeoAuditPageFacts
    {
        return new SeoAuditPageFacts(
            key: $uid,
            name: $page->title,
            explicitTitle: $page->hasSeoTitle() ? (string) $page->seoTitle : null,
            // The real <title>: the SEO title (or the page name) plus the business
            // name, composed by the one rule the public page itself uses.
            effectiveTitle: WebsiteHeadMeta::title((string) $page->seoTitle, $page->title, $siteName),
            metaDescription: $page->hasMetaDescription() ? (string) $page->metaDescription : null,
            noindex: $page->noindex,
        );
    }
}
