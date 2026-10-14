<?php

namespace App\Library\ExternalSite;

/** One sitemap file the crawler tried to read. kind: urlset | sitemapindex | invalid | unreachable */
final class SitemapReport
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $status,
        public readonly string $kind,
        public readonly int $urlCount,
        public readonly bool $declaredInRobots,
    ) {
    }
}
