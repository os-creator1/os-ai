<?php

namespace App\Library\ExternalSite;

/**
 * The mutable bookkeeping of ONE crawl run, kept out of ExternalSiteCrawler so the
 * crawler's methods stay readable. Plain data plus the queue rules:
 *
 *  - `records` holds one CrawledPage per page key (equivalent addresses share a key);
 *  - `queued` is the set of keys waiting to be fetched, and a queue entry whose key is
 *    no longer in it (the page was audited meanwhile, via a redirect) is skipped, so
 *    nothing is fetched or counted twice;
 *  - links found on pages are fetched before sitemap-only addresses, so the pages a
 *    visitor can reach are always audited ahead of the ones only a sitemap lists.
 *
 * Internal to ExternalSite; never persisted or shown.
 */
final class CrawlState
{
    /** @var array<string, CrawledPage> */
    public array $records = [];

    /** @var array<string, true> */
    public array $queued = [];

    /** @var list<array{0: string, 1: int, 2: string}> */
    public array $linkQueue = [];

    /** @var list<array{0: string, 1: int, 2: string}> */
    public array $sitemapQueue = [];

    /** @var array<string, true> */
    public array $sitemapKeys = [];

    public RobotsPolicy $robots;

    public int $visits = 0;

    public int $omitted = 0;

    /** @var list<string> */
    public array $terms = [];

    /** @param list<string> $hosts */
    public function __construct(public readonly array $hosts)
    {
        $this->robots = RobotsPolicy::allowAll();
    }

    /** @return array{0: string, 1: int, 2: string}|null */
    public function nextQueued(): ?array
    {
        while ($this->linkQueue !== [] || $this->sitemapQueue !== []) {
            $item = $this->linkQueue !== [] ? array_shift($this->linkQueue) : array_shift($this->sitemapQueue);
            $key = UrlResolver::pageKey($item[0]);

            if ($key !== null && isset($this->queued[$key])) {
                return $item;
            }
        }

        return null;
    }

    /** @param array{0: string, 1: int, 2: string} $item */
    public function unshiftBack(array $item): void
    {
        if ($item[2] === 'sitemap') {
            array_unshift($this->sitemapQueue, $item);
        } else {
            array_unshift($this->linkQueue, $item);
        }
    }

    public function queuedCount(): int
    {
        return count($this->queued);
    }

    /**
     * The audited home page: the start record, or the page it redirected to.
     */
    public function home(?CrawledPage $start): ?CrawledPage
    {
        if ($start === null || $start->kind !== CrawledPage::REDIRECT || $start->finalUrl === null) {
            return $start;
        }

        $key = UrlResolver::pageKey($start->finalUrl);

        return $key !== null ? ($this->records[$key] ?? $start) : $start;
    }

    /**
     * Records in discovery order, each flagged when the sitemap lists its address (or the address it redirects to).
     *
     * @return list<CrawledPage>
     */
    public function orderedRecords(): array
    {
        $out = [];

        foreach ($this->records as $key => $record) {
            $finalKey = $record->finalUrl !== null ? UrlResolver::pageKey($record->finalUrl) : null;
            $inSitemap = isset($this->sitemapKeys[$key]) || ($finalKey !== null && isset($this->sitemapKeys[$finalKey]));
            $out[] = $inSitemap && ! $record->inSitemap ? $record->withInSitemap(true) : $record;
        }

        return $out;
    }
}
