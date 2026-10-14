<?php

namespace App\Library\ExternalSite;

/** What the crawler learned about the site's robots.txt (never a verdict on the site itself). */
final class RobotsReport
{
    /** @param list<string> $sitemaps */
    public function __construct(
        public readonly bool $found,
        public readonly ?int $status,
        public readonly bool $readable,
        public readonly bool $blocksWholeSite,
        public readonly array $sitemaps = [],
    ) {
    }
}
