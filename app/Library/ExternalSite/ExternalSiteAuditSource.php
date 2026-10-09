<?php

namespace App\Library\ExternalSite;

use App\Library\Seo\Audit\SeoAuditPageFacts;
use App\Library\Seo\Audit\SeoAuditSiteFacts;
use App\Models\ExternalSitePage;

/**
 * EXTERNAL WEBSITE -> crawler -> stored page values -> normalised page facts.
 *
 * Translates the persisted values of a crawl into the same source-neutral
 * SeoAuditSiteFacts a hosted revision produces (HostedRevisionAuditSource), so
 * the ONE SeoAuditEvaluator judges both. Unlike the hosted source it supplies the
 * external-only facts (status, headings, canonical, Open Graph, structured data,
 * broken links), because an external site's owner controls them.
 *
 * Built from stored rows rather than the in-memory crawl so an audit can be
 * recomputed from a crawl at any time (for example when the rules change) without
 * fetching anything again.
 */
final class ExternalSiteAuditSource
{
    /**
     * @param  iterable<ExternalSitePage>  $pages  in crawl order
     */
    public static function factsFor(iterable $pages): SeoAuditSiteFacts
    {
        $facts = [];
        $missingAlt = 0;

        foreach ($pages as $page) {
            $loaded = $page->error_code === null && (int) $page->http_status >= 200 && (int) $page->http_status < 400;
            $title = $page->title === null ? null : (string) $page->title;

            $facts[] = new SeoAuditPageFacts(
                key: (string) $page->id,
                name: $page->displayPath(),
                explicitTitle: $title,
                effectiveTitle: $title ?? '',
                metaDescription: $page->meta_description === null ? null : (string) $page->meta_description,
                noindex: (bool) $page->noindex,
                httpStatus: $loaded ? (int) $page->http_status : ((int) $page->http_status >= 400 ? (int) $page->http_status : 0),
                h1Count: $loaded ? (int) $page->h1_count : null,
                hasCanonical: $loaded ? $page->canonical_url !== null : null,
                hasOpenGraph: $loaded ? (bool) $page->has_open_graph : null,
                hasStructuredData: $loaded ? (bool) $page->has_json_ld : null,
                brokenInternalLinks: $loaded ? (int) $page->broken_link_count : null,
            );

            if ($loaded) {
                $missingAlt += (int) $page->images_missing_alt;
            }
        }

        return new SeoAuditSiteFacts($facts, $missingAlt);
    }
}
