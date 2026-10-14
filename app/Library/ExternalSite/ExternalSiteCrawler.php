<?php

namespace App\Library\ExternalSite;

/**
 * External Website Audit — a small, polite, bounded crawl of a Business's OWN
 * public website.
 *
 * Discovery is the home page, then internal links (breadth first) and the
 * sitemap(s) robots.txt names or the conventional paths, nothing else. The crawl:
 *   - stays on the audited site: the start host and its www / non-www twin only;
 *     a link or redirect to any other domain is never followed;
 *   - obeys robots.txt for our token (a disallowed URL is recorded as such, never
 *     fetched);
 *   - never fetches what is not a content page (files, technical endpoints,
 *     log-in / account / cart areas, parameter variations: see UrlClassifier);
 *   - fetches ONE URL at a time with a small pause, GET only, no cookies, no
 *     forms, no authentication, and does not try to get past bot protection: a
 *     firewall's refusal is recorded as "blocked", which is NOT a site failure;
 *   - counts equivalent addresses once (UrlResolver::pageKey);
 *   - is bounded by page count, per-response bytes, per-request timeout, a total
 *     time budget and the redirect limit (config/external_site_audit.php);
 *   - keeps only normalised values (ExtractedPage), never HTML.
 *
 * Every fetch goes through SafeFetcher -> UrlGuard, so each URL it contacts has
 * been validated against private/internal destinations and pinned to a public
 * address. It never throws for a remote problem: a URL that fails is recorded with
 * a kind and a reason code.
 *
 * WHAT IS RETURNED is a record of EVERY URL the crawl decided something about
 * (audited, redirected, excluded, blocked, not checked) so the report can explain
 * each number — see CrawledPage.
 */
final class ExternalSiteCrawler
{
    private const SITEMAP_FALLBACKS = ['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'];

    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly UrlGuard $guard,
        private readonly HtmlFactExtractor $extractor,
        private readonly ExternalSiteConfig $config,
    ) {
    }

    /**
     * Checks that the address can be fetched at all, with ONE request (the home page): used when the owner saves
     * or tests an address. It reuses the crawl's own request rules, so the answer is the one the crawl will get.
     */
    public function probe(string $startUrl): ConnectionResult
    {
        $start = UrlResolver::normalize($startUrl);

        try {
            if ($start === null) {
                throw new ExternalSiteException('url_malformed');
            }

            $target = $this->guard->validate($start);
        } catch (ExternalSiteException $e) {
            return new ConnectionResult(ConnectionResult::INVALID_ADDRESS, null, null, $e->reason);
        }

        $hosts = $this->siteHosts($target->host);
        [$policy] = $this->robots($this->origin($target), $hosts);

        if (! $policy->allows((string) (parse_url($start, PHP_URL_PATH) ?: '/'))) {
            return new ConnectionResult(ConnectionResult::ROBOTS_BLOCKED, $start, null, 'blocked_by_robots');
        }

        return $this->connectionFrom($this->fetchWithRetry($start, $hosts));
    }

    /**
     * @param  (callable(array{discovered: int, checked: int, stage: string}): void)|null  $progress  called after every URL, so the owner sees real progress
     * @param  (callable(): bool)|null  $shouldCancel  checked between URLs
     */
    public function crawl(string $startUrl, ?callable $progress = null, ?callable $shouldCancel = null, array $terms = []): CrawlOutcome
    {
        $start = UrlResolver::normalize($startUrl);

        try {
            if ($start === null) {
                throw new ExternalSiteException('url_malformed');
            }

            $startTarget = $this->guard->validate($start);
        } catch (ExternalSiteException $e) {
            return new CrawlOutcome([], 0, CrawlOutcome::UNREACHABLE, false, $e->reason, CrawlOutcome::FINISH_COMPLETED, new ConnectionResult(ConnectionResult::INVALID_ADDRESS, null, null, $e->reason));
        }

        $hosts = $this->siteHosts($startTarget->host);
        $origin = $this->origin($startTarget);
        $state = new CrawlState($hosts);
        $state->terms = $terms;
        $startPath = (string) (parse_url($start, PHP_URL_PATH) ?: '/');

        $progress ??= static function (array $p): void {
        };

        $progress(['discovered' => 1, 'checked' => 0, 'stage' => 'connecting']);

        [$robots, $robotsReport] = $this->robots($origin, $hosts);
        $state->robots = $robots;

        if (! $robots->allows($startPath)) {
            $record = new CrawledPage($start, 0, null, null, kind: CrawledPage::ROBOTS_DISALLOWED, depth: 0, source: 'start', reason: 'blocked_by_robots');

            return new CrawlOutcome([$record], 1, CrawlOutcome::BLOCKED_BY_ROBOTS, false, 'blocked_by_robots', CrawlOutcome::FINISH_COMPLETED, new ConnectionResult(ConnectionResult::ROBOTS_BLOCKED, $start, null, 'blocked_by_robots'), $robotsReport);
        }

        // ---- the home page first: it tells us whether there is anything to audit at all ----
        $raw = $this->fetchWithRetry($start, $hosts);
        $connection = $this->connectionFrom($raw);
        $home = $this->record($state, $start, 0, 'start', $raw);
        $state->visits = 1;
        $progress(['discovered' => 1, 'checked' => 1, 'stage' => 'discovering']);

        if (in_array($connection->state, [ConnectionResult::CRAWLER_BLOCKED, ConnectionResult::NETWORK_ERROR, ConnectionResult::TLS_ERROR, ConnectionResult::REDIRECTS_ELSEWHERE, ConnectionResult::INVALID_ADDRESS], true)) {
            $indexability = $connection->state === ConnectionResult::CRAWLER_BLOCKED ? CrawlOutcome::NOT_VERIFIED : CrawlOutcome::UNREACHABLE;

            return new CrawlOutcome($state->orderedRecords(), count($state->records), $indexability, false, $this->failureFor($connection), CrawlOutcome::FINISH_COMPLETED, $connection, $robotsReport);
        }

        $httpUpgrade = $this->probeHttpUpgrade($start, $hosts, $connection);

        // ---- sitemap seeds ----
        $sitemaps = $this->readSitemaps($origin, $robots, $robotsReport, $hosts, $state);

        $progress(['discovered' => count($state->records) + count($state->linkQueue) + count($state->sitemapQueue), 'checked' => $state->visits, 'stage' => 'crawling']);

        // ---- the crawl proper ----
        $deadline = hrtime(true) + $this->config->maxTotalSeconds() * 1_000_000_000;
        $finish = CrawlOutcome::FINISH_COMPLETED;

        while (($next = $state->nextQueued()) !== null) {
            if ($shouldCancel !== null && $shouldCancel()) {
                $finish = CrawlOutcome::FINISH_CANCELLED;

                break;
            }

            if ($state->visits >= $this->config->maxPages()) {
                $finish = CrawlOutcome::FINISH_PAGE_LIMIT;
                $state->unshiftBack($next);

                break;
            }

            if (hrtime(true) > $deadline) {
                $finish = CrawlOutcome::FINISH_TIME_LIMIT;
                $state->unshiftBack($next);

                break;
            }

            [$url, $depth, $source] = $next;

            if ($this->config->requestDelayMs() > 0) {
                usleep($this->config->requestDelayMs() * 1000);
            }

            $state->visits++;
            $this->record($state, $url, $depth, $source, $this->fetcher->fetch($url, $hosts));

            $progress(['discovered' => count($state->records) + $state->queuedCount(), 'checked' => $state->visits, 'stage' => 'crawling']);
        }

        // ---- everything still queued was NOT checked: say so, with the reason ----
        $reason = $finish === CrawlOutcome::FINISH_TIME_LIMIT ? 'time_limit' : ($finish === CrawlOutcome::FINISH_CANCELLED ? 'cancelled' : 'crawl_limit');

        while (($left = $state->nextQueued()) !== null) {
            [$url, $depth, $source] = $left;
            $this->note($state, new CrawledPage($url, 0, null, null, kind: CrawledPage::SKIPPED, depth: $depth, source: $source, reason: $reason));
        }

        $truncated = in_array($finish, [CrawlOutcome::FINISH_PAGE_LIMIT, CrawlOutcome::FINISH_TIME_LIMIT], true) || ($finish === CrawlOutcome::FINISH_COMPLETED && $state->omitted > 0 && $this->hasSkipped($state));

        $progress(['discovered' => count($state->records), 'checked' => $state->visits, 'stage' => 'analyzing']);

        $records = $state->orderedRecords();
        $images = $finish === CrawlOutcome::FINISH_CANCELLED ? [] : $this->probeImages($records, $hosts, $robots);

        return new CrawlOutcome(
            $records,
            count($records) + $state->omitted,
            $this->indexability($state->home($home), $connection),
            $truncated,
            $records === [] ? 'nothing_fetched' : null,
            $finish,
            $connection,
            $robotsReport,
            $sitemaps,
            $images,
            $state->omitted,
            $httpUpgrade,
        );
    }

    // ---------------------------------------------------------------- recording

    /**
     * Turns one fetch into the record(s) it deserves and queues the links it revealed.
     */
    private function record(CrawlState $state, string $url, int $depth, string $source, FetchResult $raw): CrawledPage
    {
        $finalUrl = UrlResolver::normalize($raw->finalUrl) ?: $url;
        $chain = $raw->chain;

        // --- the fetch itself failed, or the answer was not a page ---
        if ($raw->error !== null) {
            return $this->note($state, match (true) {
                $raw->error === 'foreign_domain' => new CrawledPage($url, $raw->status, null, null, false, 0, CrawledPage::REDIRECT, $finalUrl, $chain, $depth, $source, false, 'redirects_off_site'),
                in_array($raw->error, ['redirect_loop', 'too_many_redirects'], true) => new CrawledPage($url, $raw->status, $raw->error, null, false, 0, CrawledPage::ERROR, $finalUrl, $chain, $depth, $source, false, $raw->error),
                $raw->error === 'content_type_not_allowed' && $raw->status >= 200 && $raw->status < 300 => new CrawledPage($url, $raw->status, null, null, false, 0, CrawledPage::NON_HTML, $finalUrl, $chain, $depth, $source, false, 'not_html', $raw->contentType),
                default => new CrawledPage($url, 0, $raw->error, null, false, 0, CrawledPage::UNREACHABLE, $finalUrl, $chain, $depth, $source, false, $raw->error),
            });
        }

        // --- a firewall / rate limit, NOT the website's own answer ---
        $bot = BotProtectionDetector::assess($raw);

        if ($bot !== null) {
            return $this->note($state, new CrawledPage($url, $raw->status, null, null, false, 0, CrawledPage::BLOCKED, $finalUrl, $chain, $depth, $source, false, $bot));
        }

        if ($raw->status >= 400 || $raw->status === 0 || ($raw->status >= 300 && $raw->status < 400)) {
            return $this->note($state, new CrawledPage($url, $raw->status, 'http_'.$raw->status, null, false, 0, CrawledPage::ERROR, $finalUrl, $chain, $depth, $source, false, 'http_'.$raw->status));
        }

        if (trim($raw->body) === '') {
            return $this->note($state, new CrawledPage($url, $raw->status, null, null, false, 0, CrawledPage::NON_HTML, $finalUrl, $chain, $depth, $source, false, 'empty_response', $raw->contentType));
        }

        // --- an HTML page ---
        $requestKey = UrlResolver::pageKey($url);
        $finalKey = UrlResolver::pageKey($finalUrl);

        // Redirected to a DIFFERENT page: the requested address is a redirect record, the destination is audited
        // (once, however many addresses lead to it).
        if ($chain !== [] && $finalKey !== $requestKey) {
            $this->note($state, new CrawledPage($url, $chain[0]['status'], null, null, false, 0, CrawledPage::REDIRECT, $finalUrl, $chain, $depth, $source, false, 'redirect'));

            if (isset($state->records[$finalKey]) && $state->records[$finalKey]->isAudited()) {
                return $state->records[$requestKey];
            }
        }

        $facts = $this->extractor->extract($raw->body, $finalUrl, $state->terms);
        $page = new CrawledPage($finalUrl, $raw->status, null, $facts, $raw->xRobotsNoindex(), 0, CrawledPage::HTML, $finalUrl, $chain, $depth, $source, false, null, $raw->contentType, $raw->robotsTag);

        // Same key as requested: keep the chain on the one record (http -> https, trailing slash ...).
        $this->note($state, $page, replace: true);

        foreach ($facts->linkDetails as $link) {
            $this->discover($state, $link['url'], $depth + 1, 'link', false);
        }

        // A canonical that names another address of this site is followed too, so "is the preferred address working?"
        // is answered by observation (it is still subject to the page budget and robots.txt).
        foreach (array_slice($facts->canonicalAll, 0, 2) as $canonical) {
            $this->discover($state, $canonical, $depth + 1, 'canonical', false);
        }

        return $page;
    }

    /**
     * Registers a URL found in a link or sitemap: classify it, honour robots.txt, and queue it when it is a page.
     */
    private function discover(CrawlState $state, string $url, ?int $depth, string $source, bool $fromSitemap): void
    {
        $key = UrlResolver::pageKey($url);

        if ($key === null) {
            return;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($host, $state->hosts, true)) {
            return;
        }

        if ($fromSitemap) {
            $state->sitemapKeys[$key] = true;
        }

        if (isset($state->records[$key]) || isset($state->queued[$key])) {
            return;
        }

        $class = UrlClassifier::classify($url, $fromSitemap);

        if ($class['class'] !== UrlClassifier::PAGE) {
            $this->note($state, new CrawledPage($url, 0, null, null, false, 0, CrawledPage::EXCLUDED, null, [], $depth, $source, false, $class['class']));

            return;
        }

        if (! $state->robots->allows((string) (parse_url($url, PHP_URL_PATH) ?: '/'))) {
            $this->note($state, new CrawledPage($url, 0, null, null, false, 0, CrawledPage::ROBOTS_DISALLOWED, null, [], $depth, $source, false, 'blocked_by_robots'));

            return;
        }

        if (count($state->records) + $state->queuedCount() >= $this->config->maxRecordedUrls()) {
            $state->omitted++;

            return;
        }

        $state->queued[$key] = true;

        if ($source === 'sitemap') {
            $state->sitemapQueue[] = [$url, $depth ?? 2, $source];
        } else {
            $state->linkQueue[] = [$url, $depth ?? 1, $source];
        }
    }

    /** Stores a record under its page key, bounded; `replace` lets an audited page supersede an earlier note for the same key. */
    private function note(CrawlState $state, CrawledPage $page, bool $replace = false): CrawledPage
    {
        $key = UrlResolver::pageKey($page->url);

        if ($key === null) {
            return $page;
        }

        unset($state->queued[$key]);

        if (isset($state->records[$key]) && ! $replace) {
            return $state->records[$key];
        }

        if (! isset($state->records[$key]) && count($state->records) >= $this->config->maxRecordedUrls() && $page->kind !== CrawledPage::HTML) {
            $state->omitted++;

            return $page;
        }

        $state->records[$key] = $page;

        return $page;
    }

    private function hasSkipped(CrawlState $state): bool
    {
        foreach ($state->records as $record) {
            if ($record->kind === CrawledPage::SKIPPED) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- connection

    private function fetchWithRetry(string $url, array $hosts): FetchResult
    {
        $raw = $this->fetcher->fetch($url, $hosts);

        $transient = in_array($raw->error, ['timeout', 'connect_failed', 'transport_error'], true)
            || ($raw->error === null && in_array($raw->status, [500, 502, 503, 504], true) && BotProtectionDetector::assess($raw) === null);

        if ($transient) {
            usleep(max(0, $this->config->requestDelayMs()) * 4000);
            $raw = $this->fetcher->fetch($url, $hosts);
        }

        return $raw;
    }

    private function connectionFrom(FetchResult $raw): ConnectionResult
    {
        $chain = $raw->chain;

        if ($raw->error !== null) {
            return match (true) {
                $raw->error === 'foreign_domain' => new ConnectionResult(ConnectionResult::REDIRECTS_ELSEWHERE, $raw->finalUrl, $raw->status ?: null, 'redirects_off_site', $chain),
                $raw->error === 'tls_failed' => new ConnectionResult(ConnectionResult::TLS_ERROR, $raw->finalUrl, null, 'tls_failed', $chain),
                in_array($raw->error, ['timeout', 'connect_failed', 'dns_failed', 'transport_error', 'too_large', 'ip_mismatch'], true) => new ConnectionResult(ConnectionResult::NETWORK_ERROR, $raw->finalUrl, null, $raw->error, $chain),
                in_array($raw->error, ['redirect_loop', 'too_many_redirects'], true) => new ConnectionResult(ConnectionResult::HTTP_ERROR, $raw->finalUrl, $raw->status ?: null, $raw->error, $chain),
                $raw->error === 'content_type_not_allowed' => new ConnectionResult(ConnectionResult::REACHABLE, $raw->finalUrl, $raw->status, 'not_html', $chain),
                default => new ConnectionResult(ConnectionResult::INVALID_ADDRESS, $raw->finalUrl, null, $raw->error, $chain),
            };
        }

        $bot = BotProtectionDetector::assess($raw);

        if ($bot !== null) {
            return new ConnectionResult(ConnectionResult::CRAWLER_BLOCKED, $raw->finalUrl, $raw->status, $bot, $chain);
        }

        if ($raw->status >= 400 || $raw->status === 0) {
            return new ConnectionResult(ConnectionResult::HTTP_ERROR, $raw->finalUrl, $raw->status, 'http_'.$raw->status, $chain);
        }

        return new ConnectionResult(ConnectionResult::REACHABLE, UrlResolver::normalize($raw->finalUrl) ?: $raw->finalUrl, $raw->status, null, $chain);
    }

    private function failureFor(ConnectionResult $connection): string
    {
        return match ($connection->state) {
            ConnectionResult::CRAWLER_BLOCKED => 'crawler_blocked',
            ConnectionResult::REDIRECTS_ELSEWHERE => 'redirects_elsewhere',
            ConnectionResult::TLS_ERROR => 'tls_failed',
            ConnectionResult::INVALID_ADDRESS => (string) ($connection->reason ?? 'url_malformed'),
            default => (string) ($connection->reason ?? 'unreachable'),
        };
    }

    /** What the crawler OBSERVED about indexing on the home page. Never a claim about Google's index. */
    private function indexability(?CrawledPage $home, ConnectionResult $connection): string
    {
        if ($connection->state === ConnectionResult::CRAWLER_BLOCKED) {
            return CrawlOutcome::NOT_VERIFIED;
        }

        if ($home === null || $home->kind === CrawledPage::BLOCKED) {
            return $home?->kind === CrawledPage::BLOCKED ? CrawlOutcome::NOT_VERIFIED : CrawlOutcome::UNREACHABLE;
        }

        if (! $home->isAudited()) {
            return in_array($home->kind, [CrawledPage::ERROR, CrawledPage::UNREACHABLE], true) ? CrawlOutcome::UNREACHABLE : CrawlOutcome::NOT_VERIFIED;
        }

        if ($home->xRobotsNoindex || ($home->facts?->noindex ?? false)) {
            return CrawlOutcome::NOINDEX;
        }

        return CrawlOutcome::INDEXABLE;
    }

    // ---------------------------------------------------------------- robots + sitemaps

    /** @return array{0: RobotsPolicy, 1: RobotsReport} */
    private function robots(string $origin, array $hosts): array
    {
        $result = $this->fetcher->fetch($origin.'/robots.txt', $hosts, 'text/plain,*/*;q=0.1', ['text/plain', 'text/html', 'application/octet-stream'], 500_000);

        if ($result->ok()) {
            $policy = RobotsPolicy::parse($result->body, $this->config->robotsToken());

            return [$policy, new RobotsReport(true, $result->status, true, $policy->blocksWholeSite(), $policy->sitemaps())];
        }

        // Missing (404/410) = no restrictions. Any other failure is "could not read it": proceed as if unrestricted
        // (a polite one-request-at-a-time crawl of public pages), but say so in the report.
        $absent = in_array($result->status, [404, 410], true);

        return [RobotsPolicy::allowAll(), new RobotsReport(false, $result->status ?: null, $absent, false, [])];
    }

    /**
     * Reads the sitemap(s) and offers their URLs to the crawl. A sitemap index is followed one level; everything is
     * bounded by the sitemap fetch / byte / URL limits.
     *
     * @return list<SitemapReport>
     */
    private function readSitemaps(string $origin, RobotsPolicy $robots, RobotsReport $robotsReport, array $hosts, CrawlState $state): array
    {
        $declared = $robots->sitemaps();
        $queue = $declared !== [] ? $declared : array_map(fn (string $p): string => $origin.$p, self::SITEMAP_FALLBACKS);
        $isDeclared = $declared !== [];
        $reports = [];
        $fetches = 0;
        $seenSitemaps = [];
        $urlsRead = 0;

        while ($queue !== [] && $fetches < $this->config->maxSitemapFetches() && $urlsRead < $this->config->maxSitemapUrls()) {
            $candidate = UrlResolver::resolve($origin.'/', (string) array_shift($queue));

            if ($candidate === null || isset($seenSitemaps[$candidate]) || ! in_array(strtolower((string) parse_url($candidate, PHP_URL_HOST)), $hosts, true)) {
                continue;
            }

            $seenSitemaps[$candidate] = true;
            $fetches++;
            $result = $this->fetcher->fetch($candidate, $hosts, 'application/xml,text/xml,*/*;q=0.1', ['application/xml', 'text/xml', 'application/xhtml+xml', 'text/plain', 'application/octet-stream'], $this->config->maxSitemapBytes());

            if (! $result->ok()) {
                $reports[] = new SitemapReport($candidate, $result->status ?: null, 'unreachable', 0, $isDeclared);

                continue;
            }

            $read = SitemapReader::read($result->body, $this->config->maxSitemapUrls());
            $reports[] = new SitemapReport($candidate, $result->status, $read['kind'], count($read['urls']), $isDeclared);

            foreach ($read['urls'] as $loc) {
                $resolved = UrlResolver::normalize($loc);

                if ($resolved === null) {
                    continue;
                }

                if ($read['kind'] === 'sitemapindex') {
                    $queue[] = $resolved;
                } elseif ($urlsRead < $this->config->maxSitemapUrls()) {
                    $urlsRead++;
                    $this->discover($state, $resolved, null, 'sitemap', true);
                }
            }

            // The fallbacks are guesses: once one of them is a real sitemap, stop guessing.
            if (! $isDeclared && $read['kind'] !== 'invalid') {
                $queue = array_values(array_filter($queue, fn (string $q): bool => ! in_array(parse_url($q, PHP_URL_PATH), self::SITEMAP_FALLBACKS, true)));
            }
        }

        return $reports;
    }

    /**
     * One extra request: does the plain http:// address move visitors to https://? Only asked when the site itself
     * is served over https. Null when it cannot be answered (the port is closed, a timeout): no claim is made.
     */
    private function probeHttpUpgrade(string $start, array $hosts, ConnectionResult $connection): ?bool
    {
        if (! str_starts_with($start, 'https://') || ! str_starts_with((string) $connection->finalUrl, 'https://')) {
            return null;
        }

        $plain = 'http://'.substr($start, 8);

        if ($this->config->requestDelayMs() > 0) {
            usleep($this->config->requestDelayMs() * 1000);
        }

        $raw = $this->fetcher->fetch($plain, $hosts);

        if ($raw->error !== null && $raw->status === 0) {
            return null;
        }

        if ($raw->status >= 200 && $raw->status < 300) {
            return str_starts_with((string) UrlResolver::normalize($raw->finalUrl), 'https://');
        }

        return null;
    }

    // ---------------------------------------------------------------- images

    /**
     * Probes a bounded number of distinct same-site images: status and Content-Length only (the transport stops
     * reading after a few bytes), to find broken images and, when the server states a size, large ones.
     *
     * @param  list<CrawledPage>  $records
     * @return array<string, array{status: int, bytes: ?int, error: ?string}>
     */
    private function probeImages(array $records, array $hosts, RobotsPolicy $robots): array
    {
        $limit = $this->config->maxImageChecks();
        $found = [];

        foreach ($records as $record) {
            foreach ($record->facts?->images ?? [] as $image) {
                $src = (string) $image['src'];

                if ($src !== '' && ! isset($found[$src]) && in_array(strtolower((string) parse_url($src, PHP_URL_HOST)), $hosts, true)) {
                    $found[$src] = true;
                }
            }
        }

        $out = [];

        foreach (array_slice(array_keys($found), 0, $limit) as $src) {
            if ($this->config->requestDelayMs() > 0) {
                usleep($this->config->requestDelayMs() * 1000);
            }

            if (! $robots->allows((string) (parse_url($src, PHP_URL_PATH) ?: '/'))) {
                continue; // robots.txt covers images too
            }

            $result = $this->fetcher->fetch($src, $hosts, 'image/*,*/*;q=0.1', ['image/'], 512);
            $length = $result->header('content-length');
            $bytes = $length !== null && ctype_digit(trim($length)) ? (int) trim($length) : null;

            $out[$src] = [
                'status' => $result->status,
                'bytes' => $bytes,
                'error' => in_array($result->error, ['too_large', null], true) ? null : $result->error,
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- hosts

    /** @return list<string> the host and its www / non-www twin */
    private function siteHosts(string $host): array
    {
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return array_values(array_unique([$bare, 'www.'.$bare]));
    }

    private function origin(ValidatedTarget $target): string
    {
        $default = ($target->scheme === 'https' && $target->port === 443) || ($target->scheme === 'http' && $target->port === 80);

        return $target->scheme.'://'.$target->host.($default ? '' : ':'.$target->port);
    }
}
