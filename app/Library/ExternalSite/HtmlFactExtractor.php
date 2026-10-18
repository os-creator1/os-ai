<?php

namespace App\Library\ExternalSite;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Turns one bounded HTML document into an ExtractedPage of plain values.
 *
 * The document is parsed with the network disabled and errors collected silently
 * (real websites are malformed). Only facts the audit rules need are read: title,
 * meta description, robots meta, canonical(s), headings (per page), links with
 * their anchor text, images and their alt attributes, Open Graph, JSON-LD (parsed
 * by StructuredDataInspector), viewport, and a few local-business signals. Text is
 * length-capped; nothing is executed, nothing is fetched (an <img>, <script>,
 * <link> or form is only ever LOOKED AT, never followed or submitted), and the DOM
 * is discarded before returning.
 *
 * Alt text: an image is "missing" its description only when it has NO alt
 * attribute at all and is not marked presentational. An empty alt="" is the correct
 * way to mark a decorative image and is respected; 1-pixel tracking images are
 * ignored.
 */
final class HtmlFactExtractor
{
    private const MAX_LINKS = 300;

    private const MAX_IMAGES = 100;

    private const MAX_HEADINGS = 60;

    private const MAX_TITLE = 512;

    private const MAX_DESCRIPTION = 1000;

    private const MAX_JSON_LD_BLOCKS = 15;

    /** Static assets / non-page documents we never treat as a page to audit. */
    private const NON_PAGE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'css', 'js', 'json', 'xml', 'txt', 'zip', 'gz', 'rar', 'mp3', 'mp4', 'mov', 'avi', 'webm', 'woff', 'woff2', 'ttf', 'eot', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv'];

    /** XPath 1.0 has no lower-case(): attribute comparisons translate A-Z to a-z explicitly. */
    private static function lc(string $attribute): string
    {
        return 'translate('.$attribute.',"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")';
    }

    /**
     * @param  list<string>  $terms  words to look for in the title, the main heading and the text (for example the business's cities)
     */
    public function extract(string $html, string $pageUrl, array $terms = []): ExtractedPage
    {
        $html = $this->toUtf8($html);

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return new ExtractedPage(null, null, false, null, 0, [], 0, 0, false, false, 0);
        }

        $xp = new DOMXPath($dom);
        $isHttps = str_starts_with(strtolower($pageUrl), 'https://');

        $titles = $xp->query('//title[not(ancestor::svg)]');
        $titleCount = $titles === false ? 0 : $titles->length;
        $title = $this->clean($titles !== false && $titles->length > 0 ? $titles->item(0)->textContent : '', self::MAX_TITLE);
        $description = $this->clean($xp->evaluate('string((//meta['.self::lc('@name').'="description"]/@content)[1])'), self::MAX_DESCRIPTION);

        $robotsParts = [];
        foreach ($xp->query('//meta['.self::lc('@name').'="robots" or '.self::lc('@name').'="googlebot" or '.self::lc('@name').'="bingbot"]/@content') ?: [] as $attr) {
            $robotsParts[] = strtolower(trim($attr->nodeValue ?? ''));
        }
        $metaRobots = $robotsParts === [] ? null : mb_substr(implode(', ', $robotsParts), 0, 255);
        $robotsTokens = preg_split('/[\s,]+/', (string) $metaRobots, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $noindex = in_array('noindex', $robotsTokens, true) || in_array('none', $robotsTokens, true);
        $nofollowAll = in_array('nofollow', $robotsTokens, true) || in_array('none', $robotsTokens, true);

        // <base href> changes how every relative link resolves, so it has to be honoured.
        $base = $pageUrl;
        $baseHref = trim((string) $xp->evaluate('string((//base/@href)[1])'));
        if ($baseHref !== '') {
            $base = UrlResolver::resolve($pageUrl, $baseHref) ?? $pageUrl;
        }

        $canonicalAll = [];
        foreach ($xp->query('//link[@href]') ?: [] as $link) {
            if ($link instanceof DOMElement && in_array('canonical', preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [], true)) {
                $resolved = UrlResolver::resolve($base, $link->getAttribute('href'));

                if ($resolved !== null && ! in_array($resolved, $canonicalAll, true)) {
                    $canonicalAll[] = $resolved;
                }
            }
        }

        $h1Count = 0;
        $emptyHeadings = 0;
        $h1Texts = [];
        $headings = [];
        foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $heading) {
            $level = (int) substr($heading->nodeName, 1);
            $text = $this->headingText($heading);

            if ($text === '') {
                $emptyHeadings++;

                continue;
            }

            if ($level === 1) {
                $h1Count++;
                if (count($h1Texts) < 10) {
                    $h1Texts[] = mb_substr($text, 0, 160);
                }
            }

            if (count($headings) < self::MAX_HEADINGS) {
                $headings[] = ['level' => $level, 'text' => mb_substr($text, 0, 120)];
            }
        }

        $links = [];
        $linkDetails = [];
        $hasTel = false;
        $hasMailto = false;
        $phones = [];
        foreach ($xp->query('//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }

            $href = trim($a->getAttribute('href'));

            if (stripos($href, 'tel:') === 0) {
                $hasTel = true;
                $this->addPhone($phones, urldecode(substr($href, 4)));

                continue;
            }

            if (stripos($href, 'mailto:') === 0) {
                $hasMailto = true;

                continue;
            }

            if ($href === '' || str_starts_with($href, '#') || preg_match('#^(sms|javascript|data|ftp|whatsapp):#i', $href) === 1 || count($links) >= self::MAX_LINKS) {
                continue;
            }

            $resolved = UrlResolver::resolve($base, $href);

            if ($resolved === null || isset($links[$resolved])) {
                continue;
            }

            $links[$resolved] = true;
            $linkDetails[] = [
                'url' => $resolved,
                'anchor' => $this->anchorText($a),
                'nofollow' => in_array('nofollow', preg_split('/\s+/', strtolower($a->getAttribute('rel'))) ?: [], true),
            ];
        }

        $images = [];
        $imageCount = 0;
        $missingAlt = 0;
        $insecure = [];
        foreach ($xp->query('//img') ?: [] as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }

            $width = $img->getAttribute('width');
            $height = $img->getAttribute('height');

            if ($width !== '' && ctype_digit($width) && (int) $width <= 1) {
                continue; // a tracking pixel, not content
            }

            $src = trim($img->getAttribute('src'));
            $resolvedSrc = $src === '' || str_starts_with(strtolower($src), 'data:') ? null : UrlResolver::resolve($base, $src);
            $role = strtolower($img->getAttribute('role'));
            $decorative = $role === 'presentation' || $role === 'none' || strtolower($img->getAttribute('aria-hidden')) === 'true';
            $alt = $img->hasAttribute('alt') ? $this->clean($img->getAttribute('alt'), 200) : null;
            $linked = false;
            for ($p = $img->parentNode; $p !== null; $p = $p->parentNode) {
                if ($p instanceof DOMElement && strtolower($p->nodeName) === 'a') {
                    $linked = true;

                    break;
                }
            }

            $imageCount++;

            if ($alt === null && ! $decorative) {
                $missingAlt++;
            }

            if ($isHttps && $resolvedSrc !== null && str_starts_with($resolvedSrc, 'http://') && count($insecure) < 10) {
                $insecure[] = $resolvedSrc;
            }

            if (count($images) < self::MAX_IMAGES) {
                $images[] = [
                    'src' => $resolvedSrc ?? '',
                    'alt' => $alt,
                    'decorative' => $decorative || $alt === '',
                    'linked' => $linked,
                    'width' => ctype_digit($width) ? (int) $width : null,
                    'height' => ctype_digit($height) ? (int) $height : null,
                    'lazy' => strtolower($img->getAttribute('loading')) === 'lazy',
                ];
            }
        }

        $scriptCount = 0;
        foreach ($xp->query('//script[@src]') ?: [] as $script) {
            $scriptCount++;
            $src = $script instanceof DOMElement ? UrlResolver::resolve($base, $script->getAttribute('src')) : null;

            if ($isHttps && $src !== null && str_starts_with($src, 'http://') && count($insecure) < 10) {
                $insecure[] = $src;
            }
        }

        $stylesheetCount = 0;
        foreach ($xp->query('//link[@href]') ?: [] as $link) {
            if ($link instanceof DOMElement && in_array('stylesheet', preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [], true)) {
                $stylesheetCount++;
                $href = UrlResolver::resolve($base, $link->getAttribute('href'));

                if ($isHttps && $href !== null && str_starts_with($href, 'http://') && count($insecure) < 10) {
                    $insecure[] = $href;
                }
            }
        }

        $hasOg = (int) $xp->evaluate('count(//meta[starts-with('.self::lc('@property').',"og:")])') > 0;
        $hasOgTitle = (int) $xp->evaluate('count(//meta['.self::lc('@property').'="og:title"])') > 0;
        $hasOgImage = (int) $xp->evaluate('count(//meta['.self::lc('@property').'="og:image"])') > 0;
        $hasViewport = (int) $xp->evaluate('count(//meta['.self::lc('@name').'="viewport"])') > 0;
        $lang = trim((string) $xp->evaluate('string(//html/@lang)'));

        $structured = [];
        $hasJsonLd = false;
        foreach ($xp->query('//script['.self::lc('@type').'="application/ld+json"]') ?: [] as $script) {
            if (trim($script->textContent) === '') {
                continue;
            }

            $hasJsonLd = true;

            if (count($structured) < self::MAX_JSON_LD_BLOCKS) {
                $structured[] = StructuredDataInspector::inspect($script->textContent);
            }
        }

        $hasAddress = (int) $xp->evaluate('count(//address)') > 0;
        $hasMap = (int) $xp->evaluate('count(//iframe[contains('.self::lc('@src').',"google.com/maps") or contains('.self::lc('@src').',"maps.google") or contains('.self::lc('@src').',"openstreetmap.org")])') > 0;
        $formCount = (int) $xp->evaluate('count(//form)');

        foreach ($xp->query('//script|//style|//noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $xp->query('//body')->item(0);
        $text = $body === null ? '' : preg_replace('/\s+/u', ' ', trim($body->textContent)) ?? '';
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        if ($text !== '') {
            preg_match_all('/(?<![\d])(?:\+?\d{1,2}[\s.\-]?)?(?:\(\d{3}\)|\d{3})[\s.\-]?\d{3}[\s.\-]?\d{4}(?![\d])/', mb_substr($text, 0, 200_000), $m);

            foreach ($m[0] ?? [] as $candidate) {
                $this->addPhone($phones, $candidate);
            }
        }

        return new ExtractedPage(
            $title === '' ? null : $title,
            $description === '' ? null : $description,
            $noindex,
            $canonicalAll[0] ?? null,
            $h1Count,
            array_keys($links),
            $imageCount,
            $missingAlt,
            $hasOg,
            $hasJsonLd,
            $words,
            $metaRobots,
            $titleCount,
            $canonicalAll,
            $h1Texts,
            $emptyHeadings,
            $headings,
            $linkDetails,
            $images,
            $structured,
            $hasViewport,
            $lang === '' ? null : mb_substr($lang, 0, 16),
            $hasOgTitle,
            $hasOgImage,
            array_slice($phones, 0, 6),
            $hasTel,
            $hasMailto,
            $hasAddress,
            $hasMap,
            $formCount,
            $scriptCount,
            $stylesheetCount,
            $insecure,
            $nofollowAll,
            $this->termHits($terms, $title, $h1Texts, $text),
        );
    }

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $h1Texts
     * @return array<string, array{title: bool, h1: bool, body: bool}>
     */
    private function termHits(array $terms, string $title, array $h1Texts, string $text): array
    {
        $hits = [];
        $h1 = implode(' ', $h1Texts);

        foreach (array_slice($terms, 0, 8) as $term) {
            $term = trim(mb_strtolower($term));

            if ($term === '' || mb_strlen($term) > 80) {
                continue;
            }

            $hits[$term] = [
                'title' => str_contains(mb_strtolower($title), $term),
                'h1' => str_contains(mb_strtolower($h1), $term),
                'body' => str_contains(mb_strtolower(mb_substr($text, 0, 300_000)), $term),
            ];
        }

        return $hits;
    }

    /** True for a URL that is plausibly an HTML page (not an image, document or asset). */
    public static function looksLikePage(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' || ! in_array($extension, self::NON_PAGE_EXTENSIONS, true);
    }

    /** A heading's text; an image-only heading counts through the image's alt text. */
    private function headingText(\DOMNode $heading): string
    {
        $text = $this->clean($heading->textContent, 300);

        if ($text !== '' || ! $heading instanceof DOMElement) {
            return $text;
        }

        foreach ($heading->getElementsByTagName('img') as $img) {
            $alt = $this->clean($img->getAttribute('alt'), 300);

            if ($alt !== '') {
                return $alt;
            }
        }

        return '';
    }

    /** What a link says to a visitor: its text, else an image's alt text, else an aria-label or title. */
    private function anchorText(DOMElement $a): string
    {
        $text = $this->clean($a->textContent, 120);

        if ($text !== '') {
            return $text;
        }

        foreach ($a->getElementsByTagName('img') as $img) {
            $alt = $this->clean($img->getAttribute('alt'), 120);

            if ($alt !== '') {
                return $alt;
            }
        }

        return $this->clean($a->getAttribute('aria-label') ?: $a->getAttribute('title'), 120);
    }

    /** @param list<string> $phones */
    private function addPhone(array &$phones, string $raw): void
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            $digits = strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;

            if (! in_array($digits, $phones, true) && count($phones) < 12) {
                $phones[] = $digits;
            }
        }
    }

    private function clean(mixed $value, int $max): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return mb_substr($value, 0, $max);
    }

    private function toUtf8(string $html): string
    {
        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        $converted = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');

        return is_string($converted) ? $converted : '';
    }
}
