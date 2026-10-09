<?php

namespace App\Library\ExternalSite;

/**
 * Everything one crawl produced. `failureCode` is set only when NOTHING usable
 * was obtained (the start URL was refused or unreachable); a partial crawl that
 * hit a limit is still a usable one, flagged by `truncated`.
 */
final class CrawlOutcome
{
    public const INDEXABLE = 'indexable';

    public const BLOCKED_BY_ROBOTS = 'blocked_by_robots';

    public const NOINDEX = 'noindex';

    public const UNREACHABLE = 'unreachable';

    /** @param list<CrawledPage> $pages */
    public function __construct(
        public readonly array $pages,
        public readonly int $discovered,
        public readonly string $indexability,
        public readonly bool $truncated,
        public readonly ?string $failureCode = null,
    ) {
    }
}
