<?php

namespace App\Library\ExternalSite;

/**
 * One URL the crawl DECIDED something about. It is not only an audited page: a
 * URL that redirected, a file, a technical endpoint, a page robots.txt disallows,
 * a page a firewall turned the crawler away from, and a page the crawl budget
 * never reached are all recorded, each with its own `kind`, so the report can
 * explain every number instead of mixing them.
 *
 * kind:
 *   html              fetched, 2xx, HTML: audited (has `facts`)
 *   redirect          answered with a redirect (the page it led to is its own record)
 *   non_html          fetched, 2xx, but not an HTML page (a PDF, an image, ...)
 *   error             the website itself answered with an error status (404, 410, 5xx ...)
 *   blocked           a firewall / bot protection / rate limit turned the crawler away (NOT a site error)
 *   unreachable       no HTTP answer: DNS, connection, TLS, timeout, too large
 *   robots_disallowed robots.txt asks crawlers to stay away: never fetched
 *   excluded          not a content page (technical endpoint, private area, parameter variant): never fetched
 *   skipped           eligible, but the crawl budget or time limit ended first: not checked
 */
final class CrawledPage
{
    public const HTML = 'html';

    public const REDIRECT = 'redirect';

    public const NON_HTML = 'non_html';

    public const ERROR = 'error';

    public const BLOCKED = 'blocked';

    public const UNREACHABLE = 'unreachable';

    public const ROBOTS_DISALLOWED = 'robots_disallowed';

    public const EXCLUDED = 'excluded';

    public const SKIPPED = 'skipped';

    /**
     * @param  list<array{url: string, status: int}>  $chain
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly ?string $error,
        public readonly ?ExtractedPage $facts,
        public readonly bool $xRobotsNoindex = false,
        public readonly int $brokenLinks = 0,
        public readonly string $kind = self::HTML,
        public readonly ?string $finalUrl = null,
        public readonly array $chain = [],
        public readonly ?int $depth = null,
        public readonly string $source = 'link',
        public readonly bool $inSitemap = false,
        public readonly ?string $reason = null,
        public readonly ?string $contentType = null,
        public readonly ?string $xRobots = null,
    ) {
    }

    public function isReachable(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 400;
    }

    public function isAudited(): bool
    {
        return $this->kind === self::HTML && $this->facts !== null;
    }

    public function withBrokenLinks(int $count): self
    {
        return $this->with(['brokenLinks' => $count]);
    }

    public function withInSitemap(bool $inSitemap): self
    {
        return $this->with(['inSitemap' => $inSitemap]);
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        $values = [
            'url' => $this->url, 'status' => $this->status, 'error' => $this->error, 'facts' => $this->facts,
            'xRobotsNoindex' => $this->xRobotsNoindex, 'brokenLinks' => $this->brokenLinks, 'kind' => $this->kind,
            'finalUrl' => $this->finalUrl, 'chain' => $this->chain, 'depth' => $this->depth, 'source' => $this->source,
            'inSitemap' => $this->inSitemap, 'reason' => $this->reason, 'contentType' => $this->contentType, 'xRobots' => $this->xRobots,
        ];

        return new self(...array_merge($values, $changes));
    }
}
