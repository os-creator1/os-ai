<?php

namespace App\Library\ExternalSite;

/**
 * External Website Audit Mode V1 — a small, polite, bounded crawl of a Business's
 * OWN public website.
 *
 * Discovery is the start URL, internal links and sitemap.xml (from robots.txt or
 * the conventional path), nothing else. The crawl:
 *   - stays on the audited site: the start host and its www / non-www twin only;
 *     a link or redirect to any other domain is never followed;
 *   - obeys robots.txt for our token (a start page that robots.txt disallows is
 *     reported as such, not crawled);
 *   - fetches ONE page at a time with a small pause, GET only, no cookies, no
 *     forms, no authentication;
 *   - is bounded by page count, per-response bytes, per-request timeout, a total
 *     time budget and the redirect limit (see config/external_site_audit.php);
 *   - keeps only normalised values (ExtractedPage), never HTML.
 *
 * Every fetch goes through SafeFetcher -> UrlGuard, so each URL it ever contacts
 * has been validated against private/internal destinations and pinned to a public
 * address. It never throws for a remote problem: a page that fails is recorded
 * with a reason code.
 */
final class ExternalSiteCrawler
{
    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly UrlGuard $guard,
        private readonly HtmlFactExtractor $extractor,
        private readonly ExternalSiteConfig $config,
    ) {
    }

    public function crawl(string $startUrl): CrawlOutcome
    {
        $start = UrlResolver::normalize($startUrl);

        try {
            if ($start === null) {
                throw new ExternalSiteException('url_malformed');
            }

            $startTarget = $this->guard->validate($start);
        } catch (ExternalSiteException $e) {
            return new CrawlOutcome([], 0, CrawlOutcome::UNREACHABLE, false, $e->reason);
        }

        $allowedHosts = $this->siteHosts($startTarget->host);
        $origin = $startTarget->scheme.'://'.$startTarget->host.($this->isDefaultPort($startTarget) ? '' : ':'.$startTarget->port);
        $robots = $this->robots($origin, $allowedHosts);
        $startPath = (string) (parse_url($start, PHP_URL_PATH) ?: '/');

        if (! $robots->allows($startPath)) {
            return new CrawlOutcome([], 1, CrawlOutcome::BLOCKED_BY_ROBOTS, false, null);
        }

        $queue = [$start];
        $seen = [$start => true];

        foreach ($this->sitemapUrls($origin, $robots, $allowedHosts) as $url) {
            if (! isset($seen[$url])) {
                $seen[$url] = true;
                $queue[] = $url;
            }
        }

        $deadline = hrtime(true) + $this->config->maxTotalSeconds() * 1_000_000_000;
        $pages = [];
        $fetched = 0;
        $truncated = false;

        while ($queue !== []) {
            if (count($pages) >= $this->config->maxPages() || hrtime(true) > $deadline) {
                $truncated = true;

                break;
            }

            $url = array_shift($queue);
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

            if (! $robots->allows($path) || ! HtmlFactExtractor::looksLikePage($url)) {
                continue;
            }

            if ($fetched > 0 && $this->config->requestDelayMs() > 0) {
                usleep($this->config->requestDelayMs() * 1000);
            }

            $fetched++;
            $result = $this->fetcher->fetch($url, $allowedHosts);
            $final = UrlResolver::normalize($result->finalUrl) ?? $url;

            if ($final !== $url) {
                if (isset($seen[$final]) && $seen[$final] === 'done') {
                    continue; // a redirect to a page already audited
                }

                $seen[$final] = 'done';
            }

            $seen[$url] = 'done';

            if ($result->error === 'content_type_not_allowed') {
                continue; // a linked PDF or file, not a page to audit
            }

            if ($result->error !== null || $result->status >= 400 || $result->status === 0) {
                $pages[] = new CrawledPage($final, $result->status, $result->error ?? 'http_'.$result->status, null);

                continue;
            }

            if ($result->status < 200 || $result->status >= 300) {
                continue; // an unfollowed 3xx or other non-content answer
            }

            $facts = $this->extractor->extract($result->body, $final);
            $pages[] = new CrawledPage($final, $result->status, null, $facts, $result->xRobotsNoindex());

            foreach ($facts->links as $link) {
                $host = strtolower((string) parse_url($link, PHP_URL_HOST));

                if (! in_array($host, $allowedHosts, true) || isset($seen[$link]) || ! HtmlFactExtractor::looksLikePage($link)) {
                    continue;
                }

                $seen[$link] = true;
                $queue[] = $link;
            }
        }

        if ($queue !== []) {
            $truncated = true;
        }

        $pages = $this->withBrokenLinkCounts($pages, $allowedHosts);

        return new CrawlOutcome($pages, count($seen), $this->indexability($pages, $start), $truncated, $pages === [] ? 'nothing_fetched' : null);
    }

    /**
     * For each page, how many of its internal links point at a page this crawl found unreachable.
     *
     * @param  list<CrawledPage>  $pages
     * @param  list<string>  $allowedHosts
     * @return list<CrawledPage>
     */
    private function withBrokenLinkCounts(array $pages, array $allowedHosts): array
    {
        $broken = [];

        foreach ($pages as $page) {
            if (! $page->isReachable()) {
                $broken[$page->url] = true;
            }
        }

        if ($broken === []) {
            return $pages;
        }

        return array_map(function (CrawledPage $page) use ($broken): CrawledPage {
            if ($page->facts === null) {
                return $page;
            }

            $count = count(array_filter($page->facts->links, fn (string $link): bool => isset($broken[$link])));

            return $count > 0 ? $page->withBrokenLinks($count) : $page;
        }, $pages);
    }

    /** @param list<CrawledPage> $pages */
    private function indexability(array $pages, string $start): string
    {
        $home = $pages[0] ?? null;

        if ($home === null || ! $home->isReachable()) {
            return CrawlOutcome::UNREACHABLE;
        }

        if ($home->xRobotsNoindex || ($home->facts?->noindex ?? false)) {
            return CrawlOutcome::NOINDEX;
        }

        return CrawlOutcome::INDEXABLE;
    }

    /** @param list<string> $allowedHosts */
    private function robots(string $origin, array $allowedHosts): RobotsPolicy
    {
        $result = $this->fetcher->fetch($origin.'/robots.txt', $allowedHosts, 'text/plain,*/*;q=0.1', ['text/plain', 'text/html', 'application/octet-stream'], 500_000);

        if (! $result->ok()) {
            return RobotsPolicy::allowAll();
        }

        return RobotsPolicy::parse($result->body, $this->config->robotsToken());
    }

    /**
     * Same-site page URLs from the sitemap(s): those robots.txt names, else /sitemap.xml. A sitemap index is
     * followed one level, all bounded by the sitemap fetch/byte/URL limits.
     *
     * @param  list<string>  $allowedHosts
     * @return list<string>
     */
    private function sitemapUrls(string $origin, RobotsPolicy $robots, array $allowedHosts): array
    {
        $queue = $robots->sitemaps() !== [] ? $robots->sitemaps() : [$origin.'/sitemap.xml'];
        $fetches = 0;
        $urls = [];

        while ($queue !== [] && $fetches < $this->config->maxSitemapFetches() && count($urls) < $this->config->maxSitemapUrls()) {
            $candidate = UrlResolver::resolve($origin.'/', (string) array_shift($queue));

            if ($candidate === null || ! in_array(strtolower((string) parse_url($candidate, PHP_URL_HOST)), $allowedHosts, true)) {
                continue;
            }

            $fetches++;
            $result = $this->fetcher->fetch($candidate, $allowedHosts, 'application/xml,text/xml,*/*;q=0.1', ['application/xml', 'text/xml', 'application/xhtml+xml', 'text/plain', 'application/octet-stream'], $this->config->maxSitemapBytes());

            if (! $result->ok()) {
                continue;
            }

            $read = SitemapReader::read($result->body, $this->config->maxSitemapUrls());

            foreach ($read['urls'] as $loc) {
                $resolved = UrlResolver::normalize($loc);

                if ($resolved === null || ! in_array(strtolower((string) parse_url($resolved, PHP_URL_HOST)), $allowedHosts, true)) {
                    continue;
                }

                if ($read['kind'] === 'sitemapindex') {
                    $queue[] = $resolved;
                } elseif (count($urls) < $this->config->maxSitemapUrls()) {
                    $urls[] = $resolved;
                }
            }
        }

        return $urls;
    }

    /** @return list<string> the host and its www / non-www twin */
    private function siteHosts(string $host): array
    {
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return array_values(array_unique([$bare, 'www.'.$bare]));
    }

    private function isDefaultPort(ValidatedTarget $target): bool
    {
        return ($target->scheme === 'https' && $target->port === 443) || ($target->scheme === 'http' && $target->port === 80);
    }
}
