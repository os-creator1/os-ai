<?php

namespace App\Library\ExternalSite\Audit;

use App\Library\ExternalSite\CrawledPage;
use App\Library\ExternalSite\CrawlOutcome;
use App\Library\ExternalSite\StructuredDataInspector;
use App\Library\ExternalSite\UrlClassifier;
use App\Library\ExternalSite\UrlResolver;

/**
 * The deterministic evaluation half of the external-website audit: one crawl in,
 * findings out. Pure — no database, no clock, no randomness, no network, no AI — so
 * the same crawl always yields the same findings in the same order.
 *
 * THE RULES THAT KEEP IT HONEST (each is pinned by a test):
 *   - Every page is judged on ITS OWN facts. A count such as "2 main headings" is the
 *     number of <h1> elements on that one page; nothing is ever summed over the crawl
 *     and attributed to a page.
 *   - HTML-content rules (title, description, headings, images, structured data ...)
 *     run ONLY for pages that were fetched and parsed as HTML. A page that returned
 *     404, was blocked by a firewall, timed out, redirected or was not HTML produces
 *     none of them — it cannot have a "missing title".
 *   - A firewall's refusal (403, 429, a challenge page) is reported as "not checked",
 *     never as a broken page or an outage.
 *   - Technical problems, SEO improvements and growth opportunities are different
 *     kinds (see ExternalAuditCatalog) and are never mixed in one count.
 *   - Duplicates (titles, descriptions, headings) form ONE group listing the real
 *     URLs; a broken destination linked from many pages is one group with every
 *     source named.
 *   - Priority comes from the catalogue's table by page importance; the only
 *     adjustments are explicit and commented below.
 *
 * ORDER IS STABLE: pages in crawl order, within a page the order rules are
 * evaluated in below; findings are finally sorted by (priority desc, rule, url).
 */
final class ExternalAuditEvaluator
{
    /** @var array<string, CrawledPage> */
    private array $byKey = [];

    /** @var list<string> */
    private array $siteHosts = [];

    /** @var list<ExternalFindingDraft> */
    private array $findings = [];

    public function evaluate(CrawlOutcome $outcome, ExternalAuditContext $ctx): ExternalAuditResult
    {
        $this->byKey = [];
        $this->findings = [];
        $records = $outcome->pages;

        foreach ($records as $record) {
            $key = UrlResolver::pageKey($record->url);

            if ($key !== null && ! isset($this->byKey[$key])) {
                $this->byKey[$key] = $record;
            }
        }

        $audited = $outcome->audited();
        $this->siteHosts = $this->hostsFrom($records);
        $start = $records[0] ?? null;
        $home = $this->resolveHome($start);

        // ------------------------------------------------------------------ link graph
        [$edges, $inlinks] = $this->linkGraph($audited);

        // ------------------------------------------------------------------ per-page analysis
        $analysis = [];

        foreach ($audited as $page) {
            $key = (string) UrlResolver::pageKey($page->url);
            $importance = $this->importanceOf($page);
            $canonicalStatus = $this->canonicalStatus($page);

            $analysis[$key] = [
                'importance' => $importance,
                'inlinks' => count($inlinks[$key] ?? []),
                'indexable' => ! ($page->facts->noindex || $page->xRobotsNoindex),
                'canonical_status' => $canonicalStatus,
            ];
        }

        $this->siteRules($outcome, $ctx, $audited, $home, $analysis);

        foreach ($audited as $page) {
            $this->pageRules($page, $ctx, $analysis[(string) UrlResolver::pageKey($page->url)], $outcome);
        }

        $this->duplicateRules($audited, $analysis);
        $this->linkRules($audited, $analysis, $inlinks, $edges);
        $this->recordRules($records, $analysis, $outcome);
        $this->localRules($audited, $home, $ctx, $analysis);

        $findings = $this->sorted($this->findings);

        return new ExternalAuditResult($findings, $analysis, $this->coverage($outcome), $this->summary($findings, $audited), $edges);
    }

    // ------------------------------------------------------------------ site-level rules

    /**
     * @param  list<CrawledPage>  $audited
     * @param  array<string, array<string, mixed>>  $analysis
     */
    private function siteRules(CrawlOutcome $outcome, ExternalAuditContext $ctx, array $audited, ?CrawledPage $home, array $analysis): void
    {
        // The home page itself: a confirmed error from the website, or no connection at all.
        if ($home !== null && $audited !== []) {
            if ($home->kind === CrawledPage::ERROR && ! in_array($home->reason, ['redirect_loop', 'too_many_redirects'], true)) {
                $this->add('home_page_error', $home->url, 'critical', '', '', [
                    'observed' => 'Your home page answered with HTTP '.$home->status.'.',
                    'values' => ['http_status' => $home->status],
                ], ExternalPageImportance::HOME);
            } elseif ($home->kind === CrawledPage::UNREACHABLE) {
                $this->add('home_page_unreachable', $home->url, null, '', '', [
                    'observed' => 'The connection to your home page failed ('.$this->reasonLabel((string) $home->error).').',
                    'values' => ['reason' => (string) $home->error],
                ], ExternalPageImportance::HOME);
            }
        }

        // HTTPS.
        if ($home !== null && $home->isAudited() && str_starts_with((string) $home->finalUrl, 'http://')) {
            $this->add('site_not_https', $home->url, null, '', '', [
                'observed' => 'The home page was delivered over http:// at '.$home->finalUrl.' and was not moved to https://.',
                'values' => ['final_url' => (string) $home->finalUrl],
            ], ExternalPageImportance::HOME);
        } elseif ($outcome->httpUpgrade === false && $home !== null) {
            $this->add('http_not_redirected', $home->url, null, '', '', [
                'observed' => 'Requesting http:// returned a normal page (HTTP 200) with no redirect to https://.',
                'values' => ['http_probe' => 'served_without_redirect'],
            ], ExternalPageImportance::HOME);
        }

        // Sitemaps.
        $reports = $outcome->sitemaps;
        $usable = array_values(array_filter($reports, fn ($r): bool => in_array($r->kind, ['urlset', 'sitemapindex'], true)));

        if ($reports !== [] && $usable === []) {
            $declared = array_values(array_filter($reports, fn ($r): bool => $r->declaredInRobots));

            if ($declared !== []) {
                $this->add('sitemap_invalid', null, null, $declared[0]->url, '', [
                    'observed' => 'robots.txt names the sitemap '.$declared[0]->url.' but it could not be loaded'.($declared[0]->status ? ' (HTTP '.$declared[0]->status.')' : '').'.',
                    'related' => [['url' => $declared[0]->url, 'status' => $declared[0]->status]],
                ]);
            } else {
                $this->add('sitemap_missing', null, null, '', '', [
                    'observed' => 'No sitemap was found at: '.implode(', ', array_map(fn ($r): string => (string) parse_url($r->url, PHP_URL_PATH), $reports)).'.',
                    'values' => ['locations_tried' => count($reports)],
                ]);
            }
        }

        foreach ($reports as $report) {
            if ($report->kind === 'invalid') {
                $this->add('sitemap_invalid', null, null, $report->url, '', [
                    'observed' => 'The file at '.$report->url.' is not valid sitemap XML.',
                    'related' => [['url' => $report->url, 'status' => $report->status]],
                ]);
            }
        }
    }

    // ------------------------------------------------------------------ per-page rules

    /**
     * @param  array<string, mixed>  $info
     */
    private function pageRules(CrawledPage $page, ExternalAuditContext $ctx, array $info, CrawlOutcome $outcome): void
    {
        $f = $page->facts;
        $url = $page->url;
        $imp = (string) $info['importance'];

        // ---- indexing directives ----
        if ($f->noindex || $page->xRobotsNoindex) {
            $sources = array_values(array_filter([
                $f->noindex ? 'meta robots tag ("'.$f->metaRobots.'")' : null,
                $page->xRobotsNoindex ? 'X-Robots-Tag header ("'.$page->xRobots.'")' : null,
            ]));

            $this->add('noindex_page', $url, null, '', '', [
                'observed' => 'This page tells search engines not to list it, through its '.implode(' and ', $sources).'.',
                'values' => ['meta_robots' => $f->metaRobots, 'x_robots_tag' => $page->xRobots],
            ], $imp);
        }

        // ---- canonical ----
        $distinct = array_values(array_unique($f->canonicalAll));

        if ($distinct === []) {
            $this->add('canonical_missing', $url, null, '', '', ['observed' => 'No canonical link was found in the page.'], $imp);
        } elseif (count($distinct) > 1) {
            $this->add('canonical_conflict', $url, null, '', '', [
                'observed' => count($distinct).' different canonical addresses are declared on this one page.',
                'related' => array_map(fn (string $c): array => ['url' => $c, 'note' => 'declared canonical'], $distinct),
            ], $imp);
        } else {
            $status = $info['canonical_status'];

            if ($status === 'off_site') {
                $this->add('canonical_off_site', $url, null, '', '', [
                    'observed' => 'The canonical link points to another website: '.$distinct[0].'.',
                    'related' => [['url' => $distinct[0], 'note' => 'canonical on another website']],
                ], $imp);
            } elseif ($status === 'other_problem') {
                $target = $this->byKey[(string) UrlResolver::pageKey($distinct[0])] ?? null;
                $why = $target === null ? 'does not work' : match ($target->kind) {
                    CrawledPage::ERROR => 'answers with HTTP '.$target->status,
                    CrawledPage::REDIRECT => 'redirects to '.$target->finalUrl,
                    CrawledPage::HTML => 'is itself marked noindex',
                    default => 'is not an ordinary page',
                };

                $this->add('canonical_target_problem', $url, null, '', '', [
                    'observed' => 'The canonical address '.$distinct[0].' '.$why.'.',
                    'related' => [['url' => $distinct[0], 'status' => $target?->status ?: null, 'note' => 'canonical target']],
                ], $imp);
            }
        }

        // ---- title ----
        $title = $f->title;

        if ($title === null) {
            $this->add('title_missing', $url, null, '', '', [
                'observed' => $f->titleCount > 0 ? 'The page has a title tag, but it is empty.' : 'The page has no title tag.',
                'values' => ['title_tags' => $f->titleCount],
                'proposal' => 'Suggested wording to adapt: "'.$this->suggestTitle($url, $ctx->businessName).'"',
            ], $imp);
        } else {
            $length = mb_strlen($title);

            if ($f->titleCount > 1) {
                $this->add('title_multiple', $url, null, '', '', ['observed' => 'The page contains '.$f->titleCount.' title tags.', 'values' => ['title_tags' => $f->titleCount, 'first_title' => $title]], $imp);
            }

            if ($length > $ctx->titleMax) {
                $this->add('title_too_long', $url, null, '', '', ['observed' => 'The title is '.$length.' characters (guidance: about '.$ctx->titleMax.' or fewer).', 'values' => ['length' => $length, 'title' => $title]], $imp);
            } elseif ($length < $ctx->titleMin) {
                $this->add('title_too_short', $url, null, '', '', [
                    'observed' => 'The title is only '.$length.' characters: "'.$title.'".',
                    'values' => ['length' => $length, 'title' => $title],
                    'proposal' => 'Suggested wording to adapt: "'.$this->suggestTitle($url, $ctx->businessName).'"',
                ], $imp);
            }
        }

        // ---- description ----
        $description = $f->metaDescription;

        if ($description === null) {
            $this->add('description_missing', $url, null, '', '', ['observed' => 'No non-empty meta description was found in the page.'], $imp);
        } else {
            $length = mb_strlen($description);

            if ($length < $ctx->descriptionMin) {
                $this->add('description_too_short', $url, null, '', '', ['observed' => 'The description is '.$length.' characters (guidance: at least about '.$ctx->descriptionMin.').', 'values' => ['length' => $length, 'description' => $description]], $imp);
            } elseif ($length > $ctx->descriptionMax) {
                $this->add('description_too_long', $url, null, '', '', ['observed' => 'The description is '.$length.' characters (guidance: about '.$ctx->descriptionMax.' or fewer).', 'values' => ['length' => $length]], $imp);
            }
        }

        // ---- mobile viewport (a tag check only) ----
        if (! $f->hasViewport) {
            $this->add('viewport_missing', $url, null, '', '', ['observed' => 'No <meta name="viewport"> tag was found in the page.'], $imp);
        }

        // ---- mixed content ----
        if ($f->insecureResources !== [] && str_starts_with($url, 'https://')) {
            $this->add('mixed_content', $url, null, '', '', [
                'observed' => count($f->insecureResources).' file(s) are requested over insecure http:// by this https:// page.',
                'values' => ['count' => count($f->insecureResources)],
                'related' => array_map(fn (string $r): array => ['url' => $r, 'note' => 'insecure resource'], $f->insecureResources),
            ], $imp);
        }

        // ---- social preview ----
        if (! $f->hasOpenGraph) {
            $this->add('og_missing', $url, null, '', '', ['observed' => 'No Open Graph (og:) tags were found in the page.'], $imp);
        }

        // ---- headings: THIS page's own counts ----
        if ($f->h1Count === 0) {
            $this->add('h1_missing', $url, null, '', '', [
                'observed' => $f->emptyHeadings > 0 ? 'The page has no H1 with text ('.$f->emptyHeadings.' empty heading(s) exist).' : 'The page has no H1 heading.',
                'values' => ['h1_count' => 0, 'empty_headings' => $f->emptyHeadings],
            ], $imp);
        } elseif ($f->h1Count > 1) {
            $this->add('h1_multiple', $url, null, '', '', [
                'observed' => 'This page contains '.$f->h1Count.' H1 headings.',
                'values' => ['h1_count' => $f->h1Count, 'h1_texts' => $f->h1Texts],
            ], $imp);
        }

        if ($f->emptyHeadings > 0) {
            $this->add('heading_empty', $url, null, '', '', ['observed' => $f->emptyHeadings.' heading element(s) on this page contain no text.', 'values' => ['empty_headings' => $f->emptyHeadings]], $imp);
        }

        $skip = $this->headingSkip($f->headings);

        if ($skip !== null) {
            $this->add('heading_skip', $url, null, '', '', ['observed' => $skip, 'values' => ['outline' => array_map(fn (array $h): string => 'H'.$h['level'].' '.$h['text'], array_slice($f->headings, 0, 12))]], $imp);
        }

        // ---- images ----
        $missingAlt = [];

        foreach ($f->images as $image) {
            if ($image['alt'] === null && ! $image['decorative']) {
                $missingAlt[] = $image['src'] !== '' ? $image['src'] : '(inline image)';
            }
        }

        if ($missingAlt !== []) {
            $this->add('image_alt_missing', $url, null, '', '', [
                'observed' => count($missingAlt).' of '.count($f->images).' image(s) on this page have no alt attribute.',
                'values' => ['missing_alt' => count($missingAlt), 'images_on_page' => count($f->images)],
                'related' => array_map(fn (string $s): array => ['url' => $s, 'note' => 'no alt attribute'], array_slice($missingAlt, 0, 8)),
            ], $imp);
        }

        $broken = [];
        $large = [];

        foreach ($f->images as $image) {
            $probe = $outcome->images[$image['src']] ?? null;

            if ($probe === null) {
                continue;
            }

            if ($probe['status'] >= 400) {
                $broken[$image['src']] = ['url' => $image['src'], 'status' => $probe['status'], 'note' => 'image did not load'];
            } elseif ($probe['bytes'] !== null && $probe['bytes'] > $ctx->largeImageBytes) {
                $large[$image['src']] = ['url' => $image['src'], 'note' => number_format($probe['bytes'] / 1_000_000, 1).' MB reported by the server'];
            }
        }

        if ($broken !== []) {
            $this->add('image_broken', $url, null, '', '', ['observed' => count($broken).' image(s) on this page answered with an error.', 'values' => ['broken_images' => count($broken)], 'related' => array_values($broken)], $imp);
        }

        if ($large !== []) {
            $this->add('image_large', $url, null, '', '', ['observed' => count($large).' image(s) on this page are reported as larger than '.number_format($ctx->largeImageBytes / 1_000_000, 1).' MB.', 'values' => ['large_images' => count($large)], 'related' => array_values($large)], $imp);
        }

        // ---- structured data ----
        $invalid = [];
        $incomplete = [];

        foreach ($f->structuredData as $block) {
            if (! $block['valid']) {
                $invalid[] = $block['error'];
            } elseif ($block['missing'] !== []) {
                $incomplete = array_merge($incomplete, $block['missing']);
            }
        }

        if ($invalid !== []) {
            $this->add('structured_data_invalid', $url, null, '', '', ['observed' => count($invalid).' JSON-LD block(s) on this page could not be parsed ('.implode(', ', array_unique($invalid)).').', 'values' => ['invalid_blocks' => count($invalid)]], $imp);
        }

        if ($incomplete !== []) {
            $this->add('structured_data_incomplete', $url, null, '', '', ['observed' => 'Declared structured data lacks: '.implode(', ', array_unique($incomplete)).'.', 'values' => ['missing' => array_values(array_unique($incomplete))]], $imp);
        }

        // ---- empty anchors ----
        $empty = array_values(array_filter($f->linkDetails, fn (array $l): bool => $l['anchor'] === ''));

        if ($empty !== []) {
            $this->add('anchor_empty', $url, null, '', '', [
                'observed' => count($empty).' link(s) on this page have no text, image description or label.',
                'values' => ['links_without_text' => count($empty)],
                'related' => array_map(fn (array $l): array => ['url' => $l['url'], 'note' => 'link has no readable text'], array_slice($empty, 0, 8)),
            ], $imp);
        }

        // ---- depth ----
        if ($page->depth !== null && $page->depth >= $ctx->deepPageDepth && ! in_array($page->source, ['sitemap', 'canonical'], true)) {
            $this->add('deep_page', $url, null, '', '', ['observed' => 'This page is '.$page->depth.' clicks from the home page.', 'values' => ['clicks_from_home' => $page->depth]], $imp);
        }

        // ---- redirects that led here ----
        // (When the requested address differs from this page, the redirect record carries the finding instead.)
        if (count($page->chain) >= 2 && UrlResolver::pageKey($page->chain[0]['url']) === UrlResolver::pageKey($url)) {
            $this->add('redirect_chain', $url, null, '', '', $this->chainEvidence($page), $imp);
        }

        // ---- location pages with very little content ----
        if (ExternalPageImportance::looksLikeLocationPage($url) && $f->wordCount < $ctx->thinLocationWords) {
            $this->add('location_page_thin', $url, null, '', '', ['observed' => 'This looks like a location or service-area page and contains about '.$f->wordCount.' words.', 'values' => ['words' => $f->wordCount]], $imp);
        }
    }

    // ------------------------------------------------------------------ duplicates (one group, real URLs)

    /**
     * @param  list<CrawledPage>  $audited
     * @param  array<string, array<string, mixed>>  $analysis
     */
    private function duplicateRules(array $audited, array $analysis): void
    {
        $eligible = array_values(array_filter($audited, function (CrawledPage $p) use ($analysis): bool {
            $info = $analysis[(string) UrlResolver::pageKey($p->url)];

            return $info['indexable'] === true && in_array($info['canonical_status'], ['self', 'missing'], true);
        }));

        $this->duplicateGroup($eligible, $analysis, 'title_duplicate', fn (CrawledPage $p): ?string => $p->facts->title, 'title');
        $this->duplicateGroup($eligible, $analysis, 'description_duplicate', fn (CrawledPage $p): ?string => $p->facts->metaDescription, 'description');

        // Identical H1 text, only for pages with exactly one H1 (a page with several is its own finding).
        $this->duplicateGroup($eligible, $analysis, 'h1_duplicate', fn (CrawledPage $p): ?string => $p->facts->h1Count === 1 ? ($p->facts->h1Texts[0] ?? null) : null, 'heading');
    }

    /**
     * @param  list<CrawledPage>  $pages
     * @param  array<string, array<string, mixed>>  $analysis
     * @param  callable(CrawledPage): ?string  $value
     */
    private function duplicateGroup(array $pages, array $analysis, string $rule, callable $value, string $label): void
    {
        $groups = [];

        foreach ($pages as $page) {
            $raw = $value($page);

            if ($raw === null || trim($raw) === '') {
                continue;
            }

            $groups[mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $raw)))][] = [$page, $raw];
        }

        foreach ($groups as $normalised => $members) {
            if (count($members) < 2) {
                continue;
            }

            $group = sha1((string) $normalised);
            $urls = array_map(fn (array $m): string => $m[0]->url, $members);

            foreach ($members as [$page, $raw]) {
                $others = array_values(array_filter($urls, fn (string $u): bool => $u !== $page->url));
                $imp = $analysis[(string) UrlResolver::pageKey($page->url)]['importance'];

                // The group's priority is that of its most important member, so a title shared with the home page is
                // never rated as if it were only on blog posts.
                $best = array_reduce($members, fn (string $carry, array $m): string => ExternalPageImportance::weight($analysis[(string) UrlResolver::pageKey($m[0]->url)]['importance']) > ExternalPageImportance::weight($carry) ? $analysis[(string) UrlResolver::pageKey($m[0]->url)]['importance'] : $carry, ExternalPageImportance::UTILITY);

                $this->add($rule, $page->url, ExternalAuditCatalog::priorityFor($rule, $best), '', $group, [
                    'observed' => 'The same '.$label.' ("'.mb_substr($raw, 0, 120).'") is used on '.count($members).' pages.',
                    'values' => ['shared_by' => count($members), $label => $raw],
                    'related' => array_map(fn (string $u): array => ['url' => $u, 'note' => 'same '.$label], $others),
                ], $imp);
            }
        }
    }

    // ------------------------------------------------------------------ internal links

    /**
     * @param  list<CrawledPage>  $audited
     * @param  array<string, array<string, mixed>>  $analysis
     * @param  array<string, array<string, true>>  $inlinks
     * @param  list<array{source: string, target: string, anchor: string, nofollow: bool}>  $edges
     */
    private function linkRules(array $audited, array $analysis, array $inlinks, array $edges): void
    {
        foreach ($edges as $edge) {
            $targetKey = (string) UrlResolver::pageKey($edge['target']);
            $sourceKey = (string) UrlResolver::pageKey($edge['source']);
            $target = $this->byKey[$targetKey] ?? null;

            if ($target === null || $targetKey === $sourceKey) {
                continue;
            }

            $sourceImp = $analysis[$sourceKey]['importance'] ?? ExternalPageImportance::STANDARD;
            $destImp = ExternalPageImportance::classify($target->finalUrl ?? $target->url);
            $anchor = $edge['anchor'] !== '' ? $edge['anchor'] : '(no link text)';

            if ($target->kind === CrawledPage::ERROR && $target->status >= 400) {
                $priority = ExternalAuditCatalog::priorityFor('broken_internal_link', $destImp);

                // Explicit adjustments: a broken link ON the home page is at least High; a broken link to the home or a
                // key page that is in the home page or sitewide navigation (3+ pages link to it) is Critical.
                if ($sourceImp === ExternalPageImportance::HOME && ExternalAuditCatalog::priorityWeight($priority) < ExternalAuditCatalog::priorityWeight('high')) {
                    $priority = 'high';
                }

                if (in_array($destImp, [ExternalPageImportance::HOME, ExternalPageImportance::KEY], true) && ($sourceImp === ExternalPageImportance::HOME || count($inlinks[$targetKey] ?? []) >= 3)) {
                    $priority = 'critical';
                }

                $this->add('broken_internal_link', $edge['source'], $priority, $targetKey, sha1($targetKey), [
                    'observed' => 'The link "'.$anchor.'" on this page leads to '.$target->url.', which answered with HTTP '.$target->status.'.',
                    'values' => ['anchor' => $anchor, 'destination' => $target->url, 'http_status' => $target->status, 'linked_from_pages' => count($inlinks[$targetKey] ?? [])],
                    'related' => [['url' => $target->url, 'status' => $target->status, 'note' => 'destination of the link']],
                    'proposal' => 'Point the link at the correct page or remove it.',
                ], $sourceImp, $target->status >= 500 ? 'heuristic' : 'confirmed');

                continue;
            }

            if ($target->kind === CrawledPage::REDIRECT && $target->reason === 'redirect' && $target->finalUrl !== null && UrlResolver::normalize($edge['target']) === UrlResolver::normalize($target->url)) {
                $this->add('internal_link_to_redirect', $edge['source'], null, $targetKey, sha1($targetKey), [
                    'observed' => 'The link "'.$anchor.'" leads to '.$target->url.', which redirects to '.$target->finalUrl.'.',
                    'values' => ['anchor' => $anchor, 'destination' => $target->url, 'final_url' => $target->finalUrl, 'redirects' => count($target->chain)],
                    'related' => [['url' => $target->finalUrl, 'status' => 200, 'note' => 'final address']],
                    'proposal' => 'Change the link to '.$target->finalUrl,
                ], $sourceImp);

                continue;
            }

            if ($target->isAudited() && ($analysis[$targetKey]['canonical_status'] ?? '') === 'other_page' && $target->facts->canonicalUrl !== null) {
                $this->add('link_to_canonicalized_page', $edge['source'], null, $targetKey, sha1($targetKey), [
                    'observed' => 'The link "'.$anchor.'" leads to '.$target->url.', whose canonical address is '.$target->facts->canonicalUrl.'.',
                    'values' => ['anchor' => $anchor, 'destination' => $target->url, 'canonical' => $target->facts->canonicalUrl],
                    'related' => [['url' => $target->facts->canonicalUrl, 'note' => 'canonical (preferred) address']],
                    'proposal' => 'Change the link to '.$target->facts->canonicalUrl,
                ], $sourceImp);
            }
        }
    }

    // ------------------------------------------------------------------ records that are not audited pages

    /**
     * @param  list<CrawledPage>  $records
     * @param  array<string, array<string, mixed>>  $analysis
     */
    private function recordRules(array $records, array $analysis, CrawlOutcome $outcome): void
    {
        foreach ($records as $record) {
            $imp = $record->source === 'start' ? ExternalPageImportance::HOME : ExternalPageImportance::classify($record->finalUrl ?? $record->url);
            $key = (string) UrlResolver::pageKey($record->url);

            switch ($record->kind) {
                case CrawledPage::BLOCKED:
                    $this->add('crawler_blocked', $record->url, 'info', '', '', [
                        'observed' => 'Our crawler was turned away (HTTP '.$record->status.', '.str_replace('_', ' ', (string) $record->reason).'). The page was not audited.',
                        'values' => ['http_status' => $record->status, 'reason' => (string) $record->reason],
                    ], $imp);
                    break;

                case CrawledPage::ROBOTS_DISALLOWED:
                    if ($record->source === 'start') {
                        break;
                    }

                    if ($record->inSitemap) {
                        $this->add('sitemap_url_blocked_by_robots', $record->url, null, '', '', ['observed' => 'This address is listed in your sitemap but robots.txt asks crawlers not to fetch it.'], $imp);
                    } else {
                        $this->add('robots_blocks_pages', $record->url, null, '', '', ['observed' => 'robots.txt asks crawlers like ours not to fetch this address. It was not fetched.', 'values' => ['found_via' => $record->source]], $imp);
                    }
                    break;

                case CrawledPage::ERROR:
                    if (in_array($record->reason, ['redirect_loop', 'too_many_redirects'], true)) {
                        $this->add('redirect_loop', $record->url, null, '', '', array_merge($this->chainEvidence($record), ['observed' => 'Following this address ended in '.str_replace('_', ' ', (string) $record->reason).'.']), $imp);
                    } elseif ($record->inSitemap && $record->status >= 400) {
                        $this->add('sitemap_url_error', $record->url, null, '', '', [
                            'observed' => 'This address is in your sitemap but answered with HTTP '.$record->status.'.',
                            'values' => ['http_status' => $record->status],
                        ], $imp);
                    }
                    break;

                case CrawledPage::REDIRECT:
                    if ($record->inSitemap && $record->reason === 'redirect' && $record->finalUrl !== null) {
                        $this->add('sitemap_url_redirects', $record->url, null, '', '', [
                            'observed' => 'This sitemap address redirects to '.$record->finalUrl.'.',
                            'related' => [['url' => $record->finalUrl, 'note' => 'final address']],
                            'proposal' => 'List '.$record->finalUrl.' in the sitemap instead.',
                        ], $imp);
                    }

                    if (count($record->chain) >= 2) {
                        $this->add('redirect_chain', $record->url, null, '', '', $this->chainEvidence($record), $imp);
                    }
                    break;

                case CrawledPage::HTML:
                    if ($record->inSitemap && ($record->facts?->noindex || $record->xRobotsNoindex)) {
                        $this->add('sitemap_url_noindex', $record->url, null, '', '', ['observed' => 'This page is listed in your sitemap but is marked noindex.'], $imp);
                    }

                    if ($record->inSitemap && $record->source !== 'start' && ! $outcome->truncated && ($analysis[$key]['inlinks'] ?? 0) === 0 && $imp !== ExternalPageImportance::HOME) {
                        $this->add('orphan_candidate', $record->url, null, '', '', ['observed' => 'Listed in your sitemap, but none of the '.count($outcome->audited()).' pages we checked links to it.', 'values' => ['pages_checked' => count($outcome->audited())]], $imp);
                    }
                    break;
            }
        }
    }

    // ------------------------------------------------------------------ local SEO (opportunities, heuristic)

    /**
     * @param  list<CrawledPage>  $audited
     * @param  array<string, array<string, mixed>>  $analysis
     */
    private function localRules(array $audited, ?CrawledPage $home, ExternalAuditContext $ctx, array $analysis): void
    {
        if ($audited === []) {
            return;
        }

        // ---- a way to contact or book, from the home page ----
        if ($home !== null && $home->isAudited()) {
            $f = $home->facts;
            $paths = [];
            $hasContactLink = false;

            foreach ($f->linkDetails as $link) {
                if ($this->isInternal($link['url']) && ExternalPageImportance::isConversionPage($link['url'])) {
                    $hasContactLink = true;
                    $paths[] = $link['url'];
                }
            }

            if (! $hasContactLink && ! $f->hasTelLink && ! $f->hasMailtoLink && $f->formCount === 0) {
                $this->add('no_contact_path', $home->url, null, '', '', [
                    'observed' => 'The home page has no link to a contact or booking page, no phone (tel:) link, no email (mailto:) link and no form.',
                    'values' => ['tel_links' => false, 'mailto_links' => false, 'forms' => 0],
                ], ExternalPageImportance::HOME);
            }
        }

        // ---- phone number consistency against the business profile ----
        $found = [];

        foreach ($audited as $page) {
            foreach ($page->facts->phones as $digits) {
                $found[substr($digits, -10)][] = $page->url;
            }
        }

        if ($ctx->phoneDigits !== null) {
            $profile = substr($ctx->phoneDigits, -10);

            if ($found !== [] && ! isset($found[$profile])) {
                $this->add('nap_phone_mismatch', $audited[0]->url, null, '', '', [
                    'observed' => 'The checked pages show '.count($found).' phone number(s), none of which matches the number in your business profile.',
                    'values' => ['numbers_on_site' => array_map(fn (string $n): string => $this->formatPhone($n), array_keys($found)), 'profile_number' => $this->formatPhone($profile)],
                    'related' => array_map(fn (string $n): array => ['url' => $found[$n][0], 'note' => $this->formatPhone($n)], array_slice(array_keys($found), 0, 6)),
                ]);
            } elseif ($found === []) {
                $this->add('nap_phone_not_found', $audited[0]->url, null, '', '', [
                    'observed' => 'No phone number was found as text or a tel: link on the '.count($audited).' page(s) we checked.',
                    'values' => ['profile_number' => $this->formatPhone($profile), 'pages_checked' => count($audited)],
                ]);
            }
        }

        // ---- structured data: local business, else none ----
        $anyJsonLd = false;
        $anyLocalType = false;

        foreach ($audited as $page) {
            $anyJsonLd = $anyJsonLd || $page->facts->hasJsonLd;

            foreach ($page->facts->structuredData as $block) {
                foreach ($block['types'] as $type) {
                    $anyLocalType = $anyLocalType || StructuredDataInspector::isLocalBusinessType($type);
                }
            }
        }

        if ($ctx->hasLocation && ! $anyLocalType) {
            $this->add('local_schema_missing', $home?->url ?? $audited[0]->url, null, '', '', [
                'observed' => $anyJsonLd ? 'JSON-LD exists on the checked pages but declares no LocalBusiness type.' : 'No JSON-LD structured data was found on the checked pages.',
                'values' => ['pages_checked' => count($audited), 'json_ld_present' => $anyJsonLd],
            ]);
        } elseif (! $anyJsonLd) {
            $this->add('structured_data_none', $home?->url ?? $audited[0]->url, null, '', '', ['observed' => 'No JSON-LD block was found on any of the '.count($audited).' pages we checked.', 'values' => ['pages_checked' => count($audited)]]);
        }

        // ---- is the business's location said anywhere? (site-level only; never a per-page keyword demand) ----
        $terms = $ctx->terms();

        if ($terms !== []) {
            $mentioned = false;

            foreach ($audited as $page) {
                foreach ($page->facts->termHits as $hit) {
                    $mentioned = $mentioned || $hit['title'] || $hit['h1'] || $hit['body'];
                }
            }

            if (! $mentioned) {
                $this->add('service_area_not_visible', $home?->url ?? $audited[0]->url, null, '', '', [
                    'observed' => 'None of the '.count($audited).' checked pages mention '.implode(', ', array_slice($terms, 0, 4)).'.',
                    'values' => ['cities_searched' => array_slice($terms, 0, 6), 'pages_checked' => count($audited)],
                ]);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function add(string $rule, ?string $pageUrl, ?string $priority, string $scope, string $group, array $evidence, string $importance = ExternalPageImportance::STANDARD, ?string $confidence = null): void
    {
        $this->findings[] = new ExternalFindingDraft(
            $rule,
            $pageUrl,
            $priority ?? ExternalAuditCatalog::priorityFor($rule, $importance),
            $scope,
            $group,
            $evidence,
            $confidence ?? (string) (ExternalAuditCatalog::rule($rule)['confidence'] ?? 'confirmed'),
            $importance,
        );
    }

    /** @return array<string, mixed> */
    private function chainEvidence(CrawledPage $page): array
    {
        $related = array_map(fn (array $hop): array => ['url' => $hop['url'], 'status' => $hop['status'], 'note' => 'redirect hop'], $page->chain);

        if ($page->finalUrl !== null && $page->finalUrl !== $page->url) {
            $related[] = ['url' => $page->finalUrl, 'note' => 'final address'];
        }

        return ['observed' => 'This address passes through '.count($page->chain).' redirect(s) before the final page.', 'values' => ['redirects' => count($page->chain)], 'related' => $related];
    }

    /**
     * @param  list<CrawledPage>  $audited
     * @return array{0: list<array{source: string, target: string, anchor: string, nofollow: bool}>, 1: array<string, array<string, true>>}
     */
    private function linkGraph(array $audited): array
    {
        $edges = [];
        $inlinks = [];

        foreach ($audited as $page) {
            $sourceKey = (string) UrlResolver::pageKey($page->url);
            $seen = [];

            foreach ($page->facts->linkDetails as $link) {
                $targetKey = UrlResolver::pageKey($link['url']);

                if ($targetKey === null || $targetKey === $sourceKey || isset($seen[$targetKey]) || ! $this->isInternal($link['url'])) {
                    continue;
                }

                $seen[$targetKey] = true;
                $inlinks[$targetKey][$sourceKey] = true;
                $edges[] = ['source' => $page->url, 'target' => $link['url'], 'anchor' => $link['anchor'], 'nofollow' => $link['nofollow']];
            }
        }

        return [$edges, $inlinks];
    }

    private function canonicalStatus(CrawledPage $page): string
    {
        $all = array_values(array_unique($page->facts->canonicalAll));

        if ($all === []) {
            return 'missing';
        }

        if (count($all) > 1) {
            return 'conflict';
        }

        $target = $all[0];

        if (! $this->isInternal($target)) {
            return 'off_site';
        }

        $targetKey = (string) UrlResolver::pageKey($target);

        if ($targetKey === UrlResolver::pageKey($page->url)) {
            return 'self';
        }

        $record = $this->byKey[$targetKey] ?? null;

        if ($record === null) {
            return 'unknown_target';
        }

        return match (true) {
            $record->kind === CrawledPage::ERROR, $record->kind === CrawledPage::REDIRECT, $record->isAudited() && ($record->facts->noindex || $record->xRobotsNoindex) => 'other_problem',
            $record->isAudited() => 'other_page',
            default => 'unknown_target',
        };
    }

    private function importanceOf(CrawledPage $page): string
    {
        return $page->source === 'start' ? ExternalPageImportance::HOME : ExternalPageImportance::classify($page->url);
    }

    private function resolveHome(?CrawledPage $start): ?CrawledPage
    {
        if ($start === null || $start->kind !== CrawledPage::REDIRECT || $start->finalUrl === null) {
            return $start;
        }

        $key = UrlResolver::pageKey($start->finalUrl);

        return $key !== null ? ($this->byKey[$key] ?? $start) : $start;
    }

    /** @param list<array{level: int, text: string}> $headings */
    private function headingSkip(array $headings): ?string
    {
        $previous = null;

        foreach ($headings as $heading) {
            if ($previous !== null && $heading['level'] > $previous['level'] + 1) {
                return 'The outline jumps from H'.$previous['level'].' ("'.mb_substr($previous['text'], 0, 40).'") straight to H'.$heading['level'].' ("'.mb_substr($heading['text'], 0, 40).'").';
            }

            $previous = $heading;
        }

        return null;
    }

    /** @param list<CrawledPage> $records @return list<string> */
    private function hostsFrom(array $records): array
    {
        $host = strtolower((string) parse_url($records[0]->url ?? '', PHP_URL_HOST));
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return $bare === '' ? [] : [$bare, 'www.'.$bare];
    }

    private function isInternal(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $this->siteHosts, true);
    }

    private function suggestTitle(string $url, string $businessName): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $last = $path === '' ? 'Home' : basename($path);
        $topic = ucwords(trim((string) preg_replace('/[-_]+/', ' ', (string) preg_replace('/\.[a-z0-9]+$/i', '', $last))));
        $name = trim($businessName);
        $title = $name === '' ? $topic : $topic.' | '.$name;

        return mb_strlen($title) <= 60 ? $title : rtrim(mb_substr($title, 0, 57)).'...';
    }

    private function formatPhone(string $digits): string
    {
        return strlen($digits) === 10 ? '('.substr($digits, 0, 3).') '.substr($digits, 3, 3).'-'.substr($digits, 6) : $digits;
    }

    private function reasonLabel(string $code): string
    {
        return match ($code) {
            'timeout' => 'the request timed out',
            'connect_failed' => 'the connection could not be made',
            'tls_failed' => 'the secure-connection handshake failed',
            'dns_failed' => 'the address did not resolve',
            default => str_replace('_', ' ', $code),
        };
    }

    /**
     * Stable order: priority (most severe first), then rule key, then URL.
     *
     * @param  list<ExternalFindingDraft>  $findings
     * @return list<ExternalFindingDraft>
     */
    private function sorted(array $findings): array
    {
        $indexed = [];

        foreach ($findings as $i => $f) {
            $indexed[] = [$f, $i];
        }

        usort($indexed, fn (array $a, array $b): int => [ExternalAuditCatalog::priorityWeight($b[0]->priority), $a[0]->ruleKey, (string) $a[0]->pageUrl, $a[1]] <=> [ExternalAuditCatalog::priorityWeight($a[0]->priority), $b[0]->ruleKey, (string) $b[0]->pageUrl, $b[1]]);

        return array_map(fn (array $pair): ExternalFindingDraft => $pair[0], $indexed);
    }

    /**
     * The distinct coverage counts. Each URL falls into exactly ONE bucket, so the buckets add up to `discovered`.
     *
     * @return array<string, mixed>
     */
    private function coverage(CrawlOutcome $outcome): array
    {
        $c = ['audited' => 0, 'redirected' => 0, 'errors' => 0, 'blocked' => 0, 'unreachable' => 0, 'robots_disallowed' => 0, 'excluded' => 0, 'skipped' => 0];
        $excludedBy = [];
        $skippedReason = null;
        $auditedViaRedirect = 0;

        foreach ($outcome->pages as $page) {
            switch ($page->kind) {
                case CrawledPage::HTML:
                    $c['audited']++;
                    $auditedViaRedirect += $page->chain !== [] ? 1 : 0;
                    break;
                case CrawledPage::REDIRECT:
                    $c['redirected']++;
                    break;
                case CrawledPage::ERROR:
                    $c['errors']++;
                    break;
                case CrawledPage::BLOCKED:
                    $c['blocked']++;
                    break;
                case CrawledPage::UNREACHABLE:
                    $c['unreachable']++;
                    break;
                case CrawledPage::ROBOTS_DISALLOWED:
                    $c['robots_disallowed']++;
                    break;
                case CrawledPage::EXCLUDED:
                case CrawledPage::NON_HTML:
                    $c['excluded']++;
                    $reason = $page->kind === CrawledPage::NON_HTML ? 'not_html' : (string) $page->reason;
                    $excludedBy[$reason] = ($excludedBy[$reason] ?? 0) + 1;
                    break;
                case CrawledPage::SKIPPED:
                    $c['skipped']++;
                    $skippedReason ??= (string) $page->reason;
                    break;
            }
        }

        $c['omitted'] = $outcome->omitted;
        $c['discovered'] = count($outcome->pages) + $outcome->omitted;
        $c['eligible_html'] = $c['audited'] + $c['errors'] + $c['blocked'] + $c['unreachable'] + $c['skipped'];
        $c['audited_after_redirect'] = $auditedViaRedirect;
        $c['excluded_by'] = $excludedBy;
        $c['skipped_reason'] = $skippedReason;
        $c['finish_reason'] = $outcome->finishReason;

        return $c;
    }

    /**
     * Counts are of ISSUE GROUPS (one per rule), not of rows, so "5 pages without a description" is ONE issue.
     *
     * @param  list<ExternalFindingDraft>  $findings
     * @param  list<CrawledPage>  $audited
     * @return array<string, mixed>
     */
    private function summary(array $findings, array $audited): array
    {
        $groupPriority = [];
        $pagesAffected = [];
        $brokenDestinations = [];
        $brokenInstances = 0;

        foreach ($findings as $f) {
            $current = $groupPriority[$f->ruleKey] ?? null;

            if ($current === null || ExternalAuditCatalog::priorityWeight($f->priority) > ExternalAuditCatalog::priorityWeight($current)) {
                $groupPriority[$f->ruleKey] = $f->priority;
            }

            if ($f->pageUrl !== null && $f->priority !== 'info') {
                $pagesAffected[(string) UrlResolver::pageKey($f->pageUrl)] = true;
            }

            if ($f->ruleKey === 'broken_internal_link') {
                $brokenDestinations[$f->scope] = true;
                $brokenInstances++;
            }
        }

        $priority = array_fill_keys(ExternalAuditCatalog::PRIORITIES, 0);
        $kinds = ['problem' => 0, 'improvement' => 0, 'opportunity' => 0, 'info' => 0];

        foreach ($groupPriority as $rule => $p) {
            $priority[$p]++;
            $kinds[ExternalAuditCatalog::kind($rule)]++;
        }

        return [
            'priority' => $priority,
            'kinds' => $kinds,
            'issue_groups' => count($groupPriority),
            'rows' => count($findings),
            'pages_affected' => count($pagesAffected),
            'broken_destinations' => count($brokenDestinations),
            'broken_link_instances' => $brokenInstances,
            'pages_audited' => count($audited),
        ];
    }
}
