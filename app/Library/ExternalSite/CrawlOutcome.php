<?php

namespace App\Library\ExternalSite;

/**
 * Everything one crawl produced. `failureCode` is set only when NOTHING usable
 * was obtained (the start URL was refused, unreachable or blocked); a partial
 * crawl that hit a limit is still a usable one, flagged by `truncated`.
 *
 * `indexability` is a statement about what the CRAWLER OBSERVED on the home page
 * (no block seen, a robots.txt block, a noindex, could not be loaded, or not
 * verified because a firewall turned the crawler away). It is never a claim that
 * Google has indexed anything.
 */
final class CrawlOutcome
{
    /** No indexing block was observed on the home page (this is NOT "indexed"). */
    public const INDEXABLE = 'indexable';

    public const BLOCKED_BY_ROBOTS = 'blocked_by_robots';

    public const NOINDEX = 'noindex';

    /** The crawler got a confirmed error or no answer from the home page. */
    public const UNREACHABLE = 'unreachable';

    /** A firewall / rate limit turned the crawler away: nothing can be concluded about the site. */
    public const NOT_VERIFIED = 'not_verified';

    public const FINISH_COMPLETED = 'completed';

    public const FINISH_PAGE_LIMIT = 'page_limit';

    public const FINISH_TIME_LIMIT = 'time_limit';

    public const FINISH_CANCELLED = 'cancelled';

    /**
     * @param  list<CrawledPage>  $pages  every URL decided on, in discovery order (the start page first)
     * @param  list<SitemapReport>  $sitemaps
     * @param  array<string, array{status: int, bytes: ?int, error: ?string}>  $images  probed same-site images by URL
     */
    public function __construct(
        public readonly array $pages,
        public readonly int $discovered,
        public readonly string $indexability,
        public readonly bool $truncated,
        public readonly ?string $failureCode = null,
        public readonly string $finishReason = self::FINISH_COMPLETED,
        public readonly ?ConnectionResult $connection = null,
        public readonly ?RobotsReport $robots = null,
        public readonly array $sitemaps = [],
        public readonly array $images = [],
        /** URLs the crawl saw but could not record individually (storage bound); counted, never silently dropped. */
        public readonly int $omitted = 0,
        /** true: http:// redirects to https://; false: http:// is served without redirecting; null: not checked or not answered */
        public readonly ?bool $httpUpgrade = null,
    ) {
    }

    /** @return list<CrawledPage> */
    public function audited(): array
    {
        return array_values(array_filter($this->pages, fn (CrawledPage $p): bool => $p->isAudited()));
    }
}
