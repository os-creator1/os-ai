<?php

namespace Tests\Support\WebsiteAcceptance;

/**
 * Website V1 full-site acceptance — the strict, deterministic SEO / technical
 * audit. PASS or FAIL per check per page, with the evidence; never a score.
 * It judges only what is in the response a crawler receives (plus the files
 * the page's own image URLs point at) against the intended truth carried by
 * AuditContext. Where Website V1 simply does not implement something it says
 * GAP — it never invents a pass and never adds the feature.
 */
final class SeoAudit
{
    /** Copy that must never reach a customer's page. */
    private const PLACEHOLDER_PATTERNS = [
        'lorem ipsum' => '/lorem\s+ipsum|dolor sit amet/i',
        'placeholder wording' => '/\b(placeholder|your text here|sample text|insert (?:text|copy)|tbd|todo|coming soon)\b/i',
        'undefined / null leak' => '/\b(undefined|null|nan)\b/i',
        'object leak' => '/\[object Object\]|Array\s*\(|stdClass/i',
        'unresolved merge token' => '/\{\{|\}\}|\{%|%\}|\[\[|\]\]|\{[a-z_]+\}|%[a-z_]+%|\$[a-z_]+\b/i',
        'raw blade / component syntax' => '/@(?:include|foreach|php|if|section)\b|<x-[a-z]|\{!!/',
        'double punctuation' => '/\w\.\.(?!\.)|\s,|,,|\s\.(?!\d)/',
        'repeated word' => '/\b([a-z]{3,})\s+\1\b/i',
        'exception / stack trace' => '/Whoops|ErrorException|Stack trace|Undefined (?:variable|array key|index)|Call to undefined|Illuminate\\\\/',
    ];

    private const GENERIC_TITLES = ['home', 'untitled', 'page', 'new page', 'index', 'default', 'welcome'];

    private const STOP_WORDS = ['the', 'and', 'for', 'with', 'your', 'our', 'you', 'are', 'from', 'that', 'this', 'about', 'photo', 'booth', 'booths'];

    public function __construct(
        private readonly AcceptanceReport $report,
        private readonly SiteCrawler $crawler,
    ) {}

    /**
     * @param  array<string, PageDoc>  $docs  every crawled page, keyed by URL
     */
    public function auditSite(array $docs, AuditContext $ctx): void
    {
        $this->auditManifestUrls($ctx);

        $titles = [];
        $descriptions = [];
        $canonicals = [];

        foreach ($ctx->manifest as $entry) {
            $label = $this->label($entry);
            $doc = $docs[$entry['url']] ?? null;

            if ($doc === null) {
                $this->report->fail($label, 'page_crawled', 'The page was never reached by the crawler: ' . $entry['url']);

                continue;
            }

            $this->report->expect($doc->status === 200, $label, 'page_crawled', 'HTTP ' . $doc->status . ' ' . $entry['url']);

            if ($doc->status !== 200) {
                continue;
            }

            $this->auditRender($doc, $label);
            $this->auditTitle($doc, $label, $ctx, $titles);
            $this->auditDescription($doc, $label, $ctx, $descriptions);
            $this->auditCanonicalAndRobots($doc, $label, $ctx, $entry, $canonicals);
            $this->auditHeadings($doc, $label);
            $this->auditContent($doc, $label, $ctx, $entry);
            $this->auditImages($doc, $label, $ctx);
            $this->auditLinks($doc, $label, $ctx);
            $this->auditStructuredData($doc, $label, $ctx, $entry);
            $this->auditSocial($doc, $label, $ctx);
            $this->auditHtmlWeight($doc, $label);
        }

        $this->auditUniqueness($titles, $descriptions, $canonicals);
        $this->auditCrossPageDuplication($docs, $ctx);
        $this->auditNavigation($docs, $ctx);
    }

    // ------------------------------------------------------------ manifest

    private function auditManifestUrls(AuditContext $ctx): void
    {
        $slugs = [];

        foreach ($ctx->manifest as $entry) {
            $label = $this->label($entry);
            $slug = $entry['slug'];

            if ($slug === null) {
                continue;
            }

            $slugs[] = $slug;
            $this->report->expect(
                preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 && strlen($slug) <= 80,
                $label,
                'slug_quality',
                'slug "' . $slug . '" must be lower-case words joined by hyphens, at most 80 characters',
            );
            $this->report->expect(
                preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $slug) === 0 && preg_match('/^\d+$/', $slug) === 0,
                $label,
                'slug_no_internal_ids',
                'slug "' . $slug . '" must not be an id or UID',
            );
        }

        $this->report->expect(count($slugs) === count(array_unique($slugs)), 'site', 'slug_unique', count($slugs) . ' slugs, ' . count(array_unique($slugs)) . ' distinct');
    }

    // ------------------------------------------------------- render safety

    private function auditRender(PageDoc $doc, string $label): void
    {
        $text = $doc->bodyText();
        $bad = [];

        foreach (self::PLACEHOLDER_PATTERNS as $name => $pattern) {
            // "Whoops"/stack traces are matched on the raw HTML too: an error page may hide them from the text.
            $haystack = $name === 'exception / stack trace' || $name === 'raw blade / component syntax' ? $doc->html : $text;

            if (preg_match($pattern, $haystack, $match) === 1) {
                $bad[] = $name . ' ("' . trim(mb_substr($match[0], 0, 40)) . '")';
            }
        }

        $this->report->expect($bad === [], $label, 'no_placeholder_or_broken_copy', $bad === [] ? 'no placeholder, token, blade, exception or malformed-punctuation copy' : implode('; ', $bad));

        // A phone number must read like one: no bare run of digits in visible text.
        preg_match_all('/(?<![\d(+\-])\d{10,11}(?![\d\-])/', $text, $bare);
        $this->report->expect($bare[0] === [], $label, 'phone_number_formatted', $bare[0] === [] ? 'no unformatted phone number in visible text' : 'unformatted: ' . implode(', ', array_unique($bare[0])));

        $dupes = $doc->duplicateIds();
        $this->report->expect($dupes === [], $label, 'no_duplicate_ids', $dupes === [] ? 'every id is unique' : 'duplicate ids: ' . implode(', ', $dupes));

        $this->report->expect($doc->lang() !== null && $doc->hasViewport(), $label, 'document_basics', 'lang=' . ($doc->lang() ?? 'missing') . ', viewport=' . ($doc->hasViewport() ? 'yes' : 'missing'));
    }

    // --------------------------------------------------------------- title

    /** @param  array<string, array<int, string>>  $titles */
    private function auditTitle(PageDoc $doc, string $label, AuditContext $ctx, array &$titles): void
    {
        $found = $doc->titles();
        $title = $found[0] ?? '';

        $this->report->expect(count($found) === 1 && $title !== '', $label, 'title_single_nonempty', count($found) . ' <title> element(s): "' . $title . '"');

        $page = trim(explode('—', $title)[0] ?? '');
        $useful = $page !== ''
            && ! in_array(mb_strtolower($page), self::GENERIC_TITLES, true)
            && mb_strtolower($page) !== mb_strtolower($ctx->businessName)
            && mb_strlen($title) >= 10
            && mb_strlen($title) <= 120;
        $this->report->expect($useful, $label, 'title_useful', '"' . $title . '" (' . mb_strlen($title) . ' chars): must be page-specific, not generic or just the brand');

        $titles[mb_strtolower($title)][] = $label;
    }

    // --------------------------------------------------------- description

    /** @param  array<string, array<int, string>>  $descriptions */
    private function auditDescription(PageDoc $doc, string $label, AuditContext $ctx, array &$descriptions): void
    {
        $found = $doc->metaNamed('description');
        $description = $found[0] ?? '';

        $this->report->expect(count($found) === 1 && $description !== '', $label, 'description_single_nonempty', count($found) . ' meta description(s), ' . mb_strlen($description) . ' chars');

        // Guardrails, not folklore: long enough to say something, short enough not to be pasted prose.
        $this->report->expect(mb_strlen($description) >= 40 && mb_strlen($description) <= 320, $label, 'description_sane_length', mb_strlen($description) . ' chars (guardrail 40-320)');

        $h1 = $doc->h1s()[0] ?? '';
        $relevant = array_intersect($this->tokens($h1 . ' ' . ($doc->titles()[0] ?? '')), $this->tokens($description)) !== [];
        $this->report->expect($relevant, $label, 'description_relevant', 'shares a significant word with the page heading/title');

        $descriptions[mb_strtolower($description)][] = $label;
    }

    // ----------------------------------------------- canonical and robots

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, array<int, string>>  $canonicals
     */
    private function auditCanonicalAndRobots(PageDoc $doc, string $label, AuditContext $ctx, array $entry, array &$canonicals): void
    {
        $found = $doc->canonicals();
        $robots = (string) $doc->robotsMeta();
        $header = (string) ($doc->headers['x-robots-tag'] ?? '');

        if ($ctx->surface === 'custom_domain') {
            $expected = $ctx->canonicalOrigin . ($entry['slug'] === null ? '/' : '/' . $entry['slug']);
            $this->report->expect(count($found) === 1 && $found[0] === $expected, $label, 'canonical_correct', 'canonical ' . json_encode($found) . ', expected ' . $expected);
            $canonicals[$found[0] ?? ''][] = $label;
        } elseif ($ctx->surface === 'preview') {
            $this->report->expect(! in_array($doc->url, $found, true) && $found === [], $label, 'canonical_correct', 'a preview page must carry no canonical (found ' . json_encode($found) . ')');
        } else {
            // Platform path: no domain => no canonical; with a domain => that domain's URL, never the platform URL.
            $ok = $found === [] || (count($found) === 1 && ($ctx->canonicalOrigin !== null && str_starts_with($found[0], $ctx->canonicalOrigin . '/')));
            $this->report->expect($ok, $label, 'canonical_correct', 'platform-path canonical ' . json_encode($found));
        }

        if ($ctx->indexingAllowed) {
            $shouldIndex = ! $entry['noindex'];
            $isIndex = str_starts_with($robots, 'index');
            $this->report->expect($isIndex === $shouldIndex && str_starts_with($header, $shouldIndex ? 'index' : 'noindex'), $label, 'robots_directive_matches_page_setting', 'page noindex=' . ($entry['noindex'] ? 'yes' : 'no') . ', meta "' . $robots . '", header "' . $header . '"');
            $this->report->expect(! $entry['noindex'], $label, 'page_is_indexable', $entry['noindex'] ? 'The page is hidden from search engines (noindex)' : 'indexable');
        } elseif ($ctx->surface === 'preview') {
            // Preview sits behind the owner's login; the page itself must still say noindex.
            $this->report->expect(str_starts_with($robots, 'noindex'), $label, 'robots_noindex_on_non_public_surface', 'meta "' . $robots . '"');
        } else {
            $this->report->expect(str_starts_with($robots, 'noindex') && str_starts_with($header, 'noindex'), $label, 'robots_noindex_on_non_public_surface', 'meta "' . $robots . '", header "' . $header . '"');
        }
    }

    // ------------------------------------------------------------ headings

    private function auditHeadings(PageDoc $doc, string $label): void
    {
        $h1s = array_filter($doc->h1s(), fn ($t) => $t !== '');
        $this->report->expect(count($doc->h1s()) === 1 && count($h1s) === 1, $label, 'h1_exactly_one', count($doc->h1s()) . ' <h1>: ' . json_encode($doc->h1s()));

        $headings = $doc->headingsAnywhere();
        $empty = array_filter($headings, fn ($h) => $h['text'] === '');
        $this->report->expect($empty === [], $label, 'no_empty_headings', count($empty) . ' empty heading(s) of ' . count($headings));

        $skips = [];
        $previous = 0;
        foreach ($headings as $h) {
            if ($previous > 0 && $h['level'] > $previous + 1) {
                $skips[] = 'h' . $previous . ' -> h' . $h['level'] . ' ("' . mb_substr($h['text'], 0, 30) . '")';
            }
            $previous = $h['level'];
        }
        $this->report->expect($skips === [], $label, 'heading_hierarchy', $skips === [] ? count($headings) . ' headings, no skipped levels' : implode('; ', $skips));
    }

    // ------------------------------------------------------------- content

    /** @param  array<string, mixed>  $entry */
    private function auditContent(PageDoc $doc, string $label, AuditContext $ctx, array $entry): void
    {
        $sections = $doc->sections();
        $empty = array_filter($sections, fn ($s) => $s['text'] === '' && $s['images'] === 0 && $s['links'] === 0);
        $this->report->expect($sections !== [] && $empty === [], $label, 'no_empty_sections', count($sections) . ' sections, ' . count($empty) . ' empty');

        // The same section (type + identical text) twice on one page is duplicated generated content.
        $seen = [];
        $dupes = [];
        foreach ($sections as $s) {
            $key = $s['type'] . '|' . mb_strtolower($s['text']);
            if ($s['text'] !== '' && isset($seen[$key])) {
                $dupes[] = $s['type'];
            }
            $seen[$key] = true;
        }
        $this->report->expect($dupes === [], $label, 'no_duplicated_sections', $dupes === [] ? 'no repeated section on the page' : 'repeated: ' . implode(', ', $dupes));

        $main = $doc->mainText();

        if ($entry['type'] === 'location' && $entry['area'] !== null) {
            $area = $entry['area'];
            $h1 = $doc->h1s()[0] ?? '';
            $inBody = substr_count(mb_strtolower($main), mb_strtolower($area));
            $this->report->expect(stripos($h1, $area) !== false && $inBody >= 3 && stripos($doc->titles()[0] ?? '', $area) !== false, $label, 'location_page_has_location_context', '"' . $area . '" appears in title=' . (stripos($doc->titles()[0] ?? '', $area) !== false ? 'yes' : 'no') . ', h1=' . (stripos($h1, $area) !== false ? 'yes' : 'no') . ', body x' . $inBody);
        }

        if ($entry['type'] === 'service' && $entry['service'] !== null) {
            $service = $entry['service'];
            $h1 = $doc->h1s()[0] ?? '';
            $inBody = substr_count(mb_strtolower($main), mb_strtolower($service));
            $this->report->expect(stripos($h1, $service) !== false && $inBody >= 2, $label, 'service_page_has_service_context', '"' . $service . '" in h1=' . (stripos($h1, $service) !== false ? 'yes' : 'no') . ', body x' . $inBody);
        }

        // Money: well-formed, and (on the Packages page) exactly the canonical price.
        preg_match_all('/(?:USD|CAD|EUR|GBP)\s?[\d,]+(?:\.\d+)?|\$\s?[\d,]+(?:\.\d+)?/', $main, $matches);
        $malformed = array_filter($matches[0], fn ($m) => preg_match('/^(?:USD|CAD|EUR|GBP|\$)\s?\d{1,3}(?:,\d{3})*\.\d{2}$/', $m) !== 1 && preg_match('/^(?:USD|CAD|EUR|GBP|\$)\s?\d{1,3}(?:,\d{3})*$/', $m) !== 1);
        $this->report->expect($malformed === [], $label, 'currency_well_formed', count($matches[0]) . ' price(s) found' . ($malformed === [] ? '' : '; malformed: ' . implode(', ', $malformed)));

        if ($entry['slug'] === 'packages') {
            foreach ($ctx->packages as $name => $priceLabel) {
                $this->report->expect(str_contains($main, $name) && str_contains($main, $priceLabel), $label, 'package_price_matches_catalog', $name . ' shows ' . $priceLabel . ': ' . (str_contains($main, $priceLabel) ? 'yes' : 'NO'));
            }

            $shown = array_unique($matches[0]);
            $unexpected = array_diff($shown, array_values($ctx->packages));
            $this->report->expect($unexpected === [], $label, 'no_package_price_mismatch', $unexpected === [] ? 'every price on the page is a current catalog price' : 'prices not in the catalog: ' . implode(', ', $unexpected));
        }
    }

    // -------------------------------------------------------------- images

    private function auditImages(PageDoc $doc, string $label, AuditContext $ctx): void
    {
        $images = $doc->images();
        $this->report->expect($images !== [], $label, 'page_has_images', count($images) . ' <img>');

        $seenEager = [];

        foreach ($images as $i => $img) {
            $id = $label . ' img#' . ($i + 1);
            $path = $this->imagePath($img['src']);

            if ($path === null) {
                $this->report->fail($id, 'image_internal_and_exists', 'src is not a stored image under /images: ' . $img['src']);

                continue;
            }

            $file = $ctx->publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $exists = is_file($file);
            $this->report->expect($exists && ! str_ends_with(strtolower($path), '.svg'), $id, 'image_internal_and_exists', $path . ($exists ? '' : ' (file missing)'));

            if (! $exists) {
                continue;
            }

            $owned = false;
            foreach ($ctx->allowedImageDirs as $dir) {
                if (str_starts_with($path, rtrim($dir, '/') . '/')) {
                    $owned = true;
                }
            }
            $this->report->expect($owned, $id, 'image_owned_by_this_business', $path);

            [$fileWidth, $fileHeight] = getimagesize($file) ?: [0, 0];
            $this->report->expect(
                $img['width'] !== null && $img['height'] !== null && (int) $img['width'] === $fileWidth && (int) $img['height'] === $fileHeight && $fileWidth > 0,
                $id,
                'image_dimensions_declared',
                'attributes ' . ($img['width'] ?? '?') . 'x' . ($img['height'] ?? '?') . ' vs file ' . $fileWidth . 'x' . $fileHeight,
            );

            $decorative = str_contains($img['class'], 'wd-hero-bg');
            $alt = $img['alt'];
            $altOk = $alt !== null && ($decorative ? $alt === '' : (trim($alt) !== '' && preg_match('/\.(?:jpe?g|png|webp)$|^IMG[_ -]?\d+|^image\d*$/i', trim($alt)) !== 1));
            $this->report->expect($altOk, $id, 'image_alt_text', $decorative ? 'decorative hero background, alt="' . $alt . '"' : 'alt "' . ($alt ?? '(missing)') . '"');

            $this->auditImageVariants($img, $id, $path, $file, $ctx);
            $this->auditImageLoading($img, $id, $path);

            if ($img['loading'] !== 'lazy') {
                $seenEager[$path] = ($seenEager[$path] ?? 0) + 1;
            }
        }

        $duplicates = array_filter($seenEager, fn ($n) => $n > 1);
        $this->report->expect($duplicates === [], $label, 'no_duplicate_eager_image_requests', $duplicates === [] ? 'no image is requested twice eagerly' : 'requested twice: ' . implode(', ', array_keys($duplicates)));
    }

    /** @param  array<string, mixed>  $img */
    private function auditImageVariants(array $img, string $id, string $path, string $file, AuditContext $ctx): void
    {
        $isVariant = (bool) preg_match('#/v/[^/]+-\d+x\d+\.webp$#', $path);
        $original = $this->originalOf($path, $ctx->publicRoot);

        // A stored original that HAS derivatives must never be the file a page loads.
        if (! $isVariant && $original !== null && $this->variantsOnDisk($path, $ctx->publicRoot) !== []) {
            $this->report->fail($id, 'responsive_image_served', 'src is the original ' . $path . ' although derivatives exist');

            return;
        }

        if (! $isVariant) {
            // Legacy asset without derivatives: the original is the safe fallback (reported, not a failure).
            $this->report->info($id, 'responsive_image_served', 'no derivatives on disk, original served: ' . $path);

            return;
        }

        $candidates = [];
        foreach (array_filter(array_map('trim', explode(',', (string) $img['srcset']))) as $candidate) {
            if (preg_match('/^(\S+)\s+(\d+)w$/', $candidate, $m) === 1) {
                $candidates[] = ['path' => $this->imagePath($m[1]), 'w' => (int) $m[2]];
            }
        }

        $widthsOk = $candidates !== [] && count(array_unique(array_column($candidates, 'w'))) === count($candidates);
        $filesOk = true;
        foreach ($candidates as $candidate) {
            $f = $candidate['path'] === null ? null : $ctx->publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate['path']);
            $filesOk = $filesOk && $f !== null && is_file($f) && (getimagesize($f)[0] ?? 0) === $candidate['w'];
        }

        $this->report->expect(
            $widthsOk && $filesOk && trim((string) $img['sizes']) !== '' && in_array($path, array_column($candidates, 'path'), true),
            $id,
            'responsive_image_served',
            count($candidates) . ' srcset candidate(s), widths ' . implode('/', array_column($candidates, 'w')) . ', sizes "' . ($img['sizes'] ?? '') . '", src is one of them',
        );

        $originalBytes = $original !== null ? filesize($ctx->publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $original)) : null;
        $this->report->expect($originalBytes === null || filesize($file) < $originalBytes, $id, 'variant_smaller_than_original', filesize($file) . ' bytes served vs original ' . ($originalBytes ?? 'n/a'));
    }

    /** @param  array<string, mixed>  $img */
    private function auditImageLoading(array $img, string $id, string $path): void
    {
        $eagerPriority = $img['loading'] !== 'lazy' && $img['fetchpriority'] === 'high';
        $kind = $img['in_hero'] ? 'hero' : ($img['region'] === 'header' ? 'logo' : 'below_fold');
        $evidence = 'loading=' . ($img['loading'] ?? 'eager(default)') . ', fetchpriority=' . ($img['fetchpriority'] ?? 'none');

        match ($kind) {
            'hero' => $this->report->expect($eagerPriority, $id, 'hero_eager_high_priority', $evidence),
            'logo' => $this->report->expect($img['loading'] !== 'lazy' && $img['fetchpriority'] === null, $id, 'logo_eager_not_high_priority', $evidence),
            default => $this->report->expect($img['loading'] === 'lazy' && $img['fetchpriority'] === null, $id, 'below_fold_lazy', $evidence . ' (' . $img['region'] . ')'),
        };
    }

    // --------------------------------------------------------------- links

    private function auditLinks(PageDoc $doc, string $label, AuditContext $ctx): void
    {
        $checked = 0;
        $broken = [];
        $loops = [];
        $host = parse_url($ctx->origin, PHP_URL_HOST);

        foreach ($doc->links() as $link) {
            $href = $link['href'];

            if (preg_match('#^(mailto:|tel:)#i', $href) === 1) {
                $ok = preg_match('#^mailto:[^@\s]+@[^@\s]+\.[a-z]{2,}$#i', $href) === 1 || preg_match('#^tel:\+?\d{7,15}$#', $href) === 1;
                $checked++;
                if (! $ok) {
                    $broken[] = $href . ' (malformed)';
                }

                continue;
            }

            if (str_starts_with($href, '#')) {
                $checked++;
                if (strlen($href) > 1 && ! in_array(substr($href, 1), $doc->ids(), true)) {
                    $broken[] = $href . ' (no such anchor)';
                }

                continue;
            }

            $target = SiteCrawler::normalise($href, $doc->url);
            if ($target === null) {
                $broken[] = $href . ' (unparseable)';

                continue;
            }

            // External hosts are recorded, never fetched.
            if (parse_url($target, PHP_URL_HOST) !== $host && parse_url($target, PHP_URL_HOST) !== parse_url($doc->url, PHP_URL_HOST)) {
                $checked++;

                continue;
            }

            $checked++;
            $result = $this->crawler->resolve($target);

            if ($result['loop']) {
                $loops[] = $href;
            } elseif ($result['status'] !== 200) {
                $broken[] = $href . ' -> HTTP ' . $result['status'] . ' (' . $link['region'] . ': "' . mb_substr($link['text'], 0, 30) . '")';
            }
        }

        $this->report->expect($broken === [], $label, 'internal_links_resolve', $checked . ' link(s) checked' . ($broken === [] ? ', 0 broken' : '; BROKEN: ' . implode(' | ', array_slice($broken, 0, 6))));
        $this->report->expect($loops === [], $label, 'no_redirect_loops', $loops === [] ? 'no redirect loops' : implode(', ', $loops));

        $leaks = array_filter($doc->links(), fn ($l) => preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $l['href']) === 1 && $ctx->surface === 'custom_domain');
        $this->report->expect($leaks === [], $label, 'no_internal_uids_in_public_urls', $leaks === [] ? 'no UID in any link' : 'UID in ' . implode(', ', array_column($leaks, 'href')));
    }

    // ------------------------------------------------------ structured data

    /** @param  array<string, mixed>  $entry */
    private function auditStructuredData(PageDoc $doc, string $label, AuditContext $ctx, array $entry): void
    {
        $blocks = $doc->jsonLd();

        if (! $ctx->indexingAllowed || $entry['noindex']) {
            $this->report->expect($blocks === [], $label, 'schema_only_on_indexable_pages', count($blocks) . ' block(s) on a non-indexable surface/page');

            return;
        }

        $this->report->expect($blocks !== [], $label, 'schema_present', count($blocks) . ' JSON-LD block(s)');

        $types = [];
        foreach ($blocks as $raw) {
            $data = json_decode($raw, true);
            $this->report->expect(is_array($data), $label, 'schema_valid_json', 'block parses as JSON');

            if (! is_array($data)) {
                continue;
            }

            $type = (string) ($data['@type'] ?? '');
            $types[] = $type;

            $this->report->expect(($data['@context'] ?? null) === 'https://schema.org', $label, 'schema_context', $type . ' @context ' . json_encode($data['@context'] ?? null));

            $flat = mb_strtolower($raw);
            $this->report->expect(! str_contains($flat, 'aggregaterating') && ! str_contains($flat, '"review') && ! str_contains($flat, 'ratingvalue'), $label, 'schema_no_invented_ratings', $type . ' carries no rating or review markup');
            $this->report->expect(preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $raw) === 0 && ! str_contains($raw, '127.0.0.1') && ! str_contains($raw, 'localhost'), $label, 'schema_no_internal_ids', $type . ' has no UID or local address');

            if ($type === 'LocalBusiness' || str_contains($type, 'Business')) {
                $this->report->expect(($data['name'] ?? null) === $ctx->businessName, $label, 'schema_business_identity', 'name ' . json_encode($data['name'] ?? null) . ' vs ' . $ctx->businessName);
                $this->report->expect(isset($data['url']) && str_starts_with((string) $data['url'], (string) $ctx->canonicalOrigin), $label, 'schema_canonical_url', 'url ' . json_encode($data['url'] ?? null));
            }

            if ($type === 'BreadcrumbList') {
                $urls = array_map(fn ($item) => (string) ($item['item'] ?? ''), $data['itemListElement'] ?? []);
                $ok = $urls !== [] && count(array_filter($urls, fn ($u) => str_starts_with($u, (string) $ctx->canonicalOrigin))) === count($urls);
                $this->report->expect($ok, $label, 'schema_breadcrumb_urls', implode(' > ', $urls));
            }
        }

        $this->report->expect(in_array('LocalBusiness', $types, true) || count(array_filter($types, fn ($t) => str_contains($t, 'Business'))) > 0, $label, 'schema_local_business', 'types: ' . implode(', ', $types));

        $hasFaq = count(array_filter($doc->sections(), fn ($s) => $s['type'] === 'faq')) > 0;
        if ($hasFaq && ! in_array('FAQPage', $types, true)) {
            $this->report->gap($label, 'schema_faq_page', 'Website V1 emits no FAQPage structured data for its FAQ sections');
        }
    }

    // -------------------------------------------------------------- social

    private function auditSocial(PageDoc $doc, string $label, AuditContext $ctx): void
    {
        $title = $doc->metaProperty('og:title')[0] ?? '';
        $description = $doc->metaProperty('og:description')[0] ?? '';

        $this->report->expect($title !== '' && str_contains(mb_strtolower($doc->titles()[0] ?? ''), mb_strtolower($title)), $label, 'og_title', 'og:title "' . $title . '"');
        $this->report->expect($description !== '' && $description === ($doc->metaNamed('description')[0] ?? null), $label, 'og_description', 'og:description matches the meta description');

        foreach (['og:image' => $doc->metaProperty('og:image'), 'og:url' => $doc->metaProperty('og:url'), 'og:type' => $doc->metaProperty('og:type'), 'twitter:card' => $doc->metaNamed('twitter:card')] as $tag => $values) {
            if ($values === []) {
                $this->report->gap($label, 'social_' . str_replace(':', '_', $tag), 'Website V1 does not emit ' . $tag);
            } else {
                $this->report->pass($label, 'social_' . str_replace(':', '_', $tag), $values[0]);
            }
        }
    }

    // --------------------------------------------------------- html weight

    private function auditHtmlWeight(PageDoc $doc, string $label): void
    {
        $this->report->expect($doc->bytes() <= 150_000 && $doc->inlineStyleBytes() <= 20_000 && $doc->dataUriLengths() === [], $label, 'html_weight', $doc->bytes() . ' bytes of HTML, ' . $doc->inlineStyleBytes() . ' bytes inline CSS, ' . count($doc->dataUriLengths()) . ' large data URI(s)');
    }

    // ------------------------------------------------------------ site-level

    /**
     * @param  array<string, array<int, string>>  $titles
     * @param  array<string, array<int, string>>  $descriptions
     * @param  array<string, array<int, string>>  $canonicals
     */
    private function auditUniqueness(array $titles, array $descriptions, array $canonicals): void
    {
        $dupTitles = array_filter($titles, fn ($pages) => count($pages) > 1);
        $dupDescriptions = array_filter($descriptions, fn ($pages) => count($pages) > 1);
        $dupCanonicals = array_filter($canonicals, fn ($pages, $url) => $url !== '' && count($pages) > 1, ARRAY_FILTER_USE_BOTH);

        $this->report->expect($dupTitles === [], 'site', 'titles_unique', count($titles) . ' distinct titles' . ($dupTitles === [] ? '' : '; duplicated: ' . json_encode(array_values($dupTitles))));
        $this->report->expect($dupDescriptions === [], 'site', 'descriptions_unique', count($descriptions) . ' distinct descriptions' . ($dupDescriptions === [] ? '' : '; duplicated: ' . json_encode(array_values($dupDescriptions))));
        $this->report->expect($dupCanonicals === [], 'site', 'canonicals_unique', $dupCanonicals === [] ? 'no two pages share a canonical' : json_encode(array_values($dupCanonicals)));
    }

    /** @param  array<string, PageDoc>  $docs */
    private function auditCrossPageDuplication(array $docs, AuditContext $ctx): void
    {
        foreach (['location', 'service'] as $type) {
            $group = array_values(array_filter($ctx->manifest, fn ($e) => $e['type'] === $type && isset($docs[$e['url']])));
            $max = 0.0;
            $worst = '';

            for ($a = 0; $a < count($group); $a++) {
                for ($b = $a + 1; $b < count($group); $b++) {
                    $similarity = $this->shingleSimilarity($this->bodyOf($docs[$group[$a]['url']]), $this->bodyOf($docs[$group[$b]['url']]));
                    if ($similarity > $max) {
                        $max = $similarity;
                        $worst = $this->label($group[$a]) . ' vs ' . $this->label($group[$b]);
                    }
                }
            }

            $this->report->expect($max < 0.6, 'site', $type . '_pages_not_duplicated_copy', count($group) . ' ' . $type . ' page(s); most similar pair ' . round($max * 100) . '% (' . $worst . ')');
        }
    }

    /**
     * Header navigation: every intended page is reachable, exactly once in the
     * footer index and (where the architecture puts it there) in the header.
     *
     * @param  array<string, PageDoc>  $docs
     */
    private function auditNavigation(array $docs, AuditContext $ctx): void
    {
        $home = null;
        foreach ($ctx->manifest as $entry) {
            if ($entry['type'] === 'home') {
                $home = $docs[$entry['url']] ?? null;
            }
        }

        if ($home === null) {
            $this->report->fail('site', 'navigation_present', 'home page was not crawled');

            return;
        }

        $reachable = [];
        foreach ($home->links() as $link) {
            if (in_array($link['region'], ['header', 'footer'], true)) {
                $reachable[SiteCrawler::normalise($link['href'], $home->url) ?? ''] = true;
            }
        }

        foreach ($ctx->manifest as $entry) {
            $this->report->expect(isset($reachable[$entry['url']]) || $entry['type'] === 'home', $this->label($entry), 'page_reachable_from_header_or_footer', 'linked from the home page header/footer: ' . (isset($reachable[$entry['url']]) ? 'yes' : 'NO'));
        }

        $cta = array_filter($home->links(), fn ($l) => $l['testid'] === 'header-cta');
        $this->report->expect(count($cta) === 1, 'site', 'header_cta_present', count($cta) . ' header CTA');
    }

    // ------------------------------------------------------------- sitemap

    /**
     * @param  array<int, string>  $expectedUrls  every intended indexable page URL
     */
    public function auditSitemap(?string $xml, array $expectedUrls, string $canonicalOrigin): void
    {
        $dom = new \DOMDocument();
        $valid = $xml !== null && @$dom->loadXML($xml);
        $this->report->expect($valid, 'sitemap', 'sitemap_valid_xml', $valid ? 'parses as XML' : 'does not parse');

        if (! $valid) {
            return;
        }

        $urls = [];
        foreach ($dom->getElementsByTagName('loc') as $loc) {
            $urls[] = trim($loc->textContent);
        }

        $this->report->expect(count($urls) === count(array_unique($urls)), 'sitemap', 'sitemap_no_duplicates', count($urls) . ' URLs, ' . count(array_unique($urls)) . ' distinct');
        $this->report->expect(array_diff($expectedUrls, $urls) === [] && array_diff($urls, $expectedUrls) === [], 'sitemap', 'sitemap_matches_indexable_pages', count($urls) . ' listed, ' . count($expectedUrls) . ' intended; missing ' . json_encode(array_values(array_diff($expectedUrls, $urls))) . ', unexpected ' . json_encode(array_values(array_diff($urls, $expectedUrls))));
        $this->report->expect(count($urls) === count(array_filter($urls, fn ($u) => str_starts_with($u, $canonicalOrigin . '/') && ! str_contains($u, '/preview/') && ! str_contains($u, '/workspaces/'))), 'sitemap', 'sitemap_canonical_urls_only', 'every URL is on ' . $canonicalOrigin . ' and none is a preview URL');

        foreach ($urls as $url) {
            $result = $this->crawler->resolve($url);
            $this->report->expect($result['status'] === 200 && $result['hops'] === 0, 'sitemap', 'sitemap_url_resolves', $url . ' -> HTTP ' . $result['status']);
        }
    }

    // -------------------------------------------------------------- robots

    public function auditRobots(string $robotsTxt): void
    {
        $normalised = str_replace("\r\n", "\n", $robotsTxt);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $normalised)), fn ($l) => $l !== '' && ! str_starts_with($l, '#')));

        $agents = array_filter($lines, fn ($l) => stripos($l, 'user-agent:') === 0);
        $sane = $agents !== [] && count(array_filter($lines, fn ($l) => preg_match('/^(user-agent|disallow|allow|sitemap|crawl-delay):/i', $l) !== 1)) === 0;
        $this->report->expect($sane, 'robots.txt', 'robots_syntax', count($lines) . ' directive(s), ' . count($agents) . ' user-agent group(s), only known directives');

        $blocks = array_filter($lines, fn ($l) => preg_match('/^disallow:\s*\/\s*$/i', $l) === 1);
        $this->report->expect($blocks === [], 'robots.txt', 'robots_does_not_block_site', $blocks === [] ? 'no "Disallow: /"' : 'blocks the whole site');

        $this->report->expect(strlen($robotsTxt) > 0, 'robots.txt', 'robots_renders', strlen($robotsTxt) . ' bytes');

        if (count(array_filter($lines, fn ($l) => stripos($l, 'sitemap:') === 0)) === 0) {
            $this->report->gap('robots.txt', 'robots_sitemap_directive', 'robots.txt is one static platform-wide file with no Sitemap: line; each site\'s sitemap is at /sitemap and must be submitted to search engines');
        }
    }

    // ------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $entry */
    private function label(array $entry): string
    {
        return $entry['slug'] ?? 'home';
    }

    /** @return array<int, string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[a-z0-9]{4,}/', mb_strtolower($text), $m);

        return array_values(array_diff(array_unique($m[0]), self::STOP_WORDS));
    }

    private function bodyOf(PageDoc $doc): string
    {
        return mb_strtolower($doc->mainText());
    }

    /** Jaccard similarity of 4-word shingles. */
    private function shingleSimilarity(string $a, string $b): float
    {
        $shingles = function (string $text): array {
            $words = preg_split('/\W+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $out = [];
            for ($i = 0; $i + 3 < count($words); $i++) {
                $out[implode(' ', array_slice($words, $i, 4))] = true;
            }

            return $out;
        };

        $x = $shingles($a);
        $y = $shingles($b);

        if ($x === [] || $y === []) {
            return 0.0;
        }

        return count(array_intersect_key($x, $y)) / count($x + $y);
    }

    /** The /images/... path of an image URL, or null for anything else. */
    private function imagePath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = ltrim($path, '/');

        return str_starts_with($path, 'images/') && ! str_contains($path, '..') ? $path : null;
    }

    /** The original a derivative was made from (or $path itself when it is an original), if it is on disk. */
    private function originalOf(string $path, string $root): ?string
    {
        if (preg_match('#^(.*)/v/([^/]+)-\d+x\d+\.webp$#', $path, $m) === 1) {
            foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
                if (is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $m[1] . '/' . $m[2] . '.' . $ext))) {
                    return $m[1] . '/' . $m[2] . '.' . $ext;
                }
            }

            return null;
        }

        return is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path)) ? $path : null;
    }

    /** @return array<int, string> derivative files that exist for an original */
    private function variantsOnDisk(string $path, string $root): array
    {
        $info = pathinfo($path);
        $glob = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $info['dirname'] . '/v/' . $info['filename']) . '-*x*.webp';

        return glob($glob) ?: [];
    }
}
