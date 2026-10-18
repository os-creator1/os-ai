<?php

namespace App\Library\Seo\Audit;

/**
 * External Website Audit Mode V1 — a whole site's audit input: its pages, in a
 * stable order, and the one site-level count the rules need (images with no
 * description). Source-neutral; see SeoAuditPageFacts.
 */
final class SeoAuditSiteFacts
{
    /**
     * @param  list<SeoAuditPageFacts>  $pages
     */
    public function __construct(
        public readonly array $pages,
        public readonly int $imagesMissingAlt = 0,
    ) {
    }
}
