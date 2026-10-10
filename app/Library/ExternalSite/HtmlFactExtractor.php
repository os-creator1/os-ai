<?php

namespace App\Library\ExternalSite;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Turns one bounded HTML document into an ExtractedPage of plain values.
 *
 * The document is parsed with the network disabled and errors collected silently
 * (real websites are malformed). Only the facts the audit rules need are read:
 * title, meta description, robots meta, canonical, h1 count, links, images and
 * their alt attributes, Open Graph and JSON-LD presence, a word count. Text is
 * length-capped; nothing is executed, nothing is fetched (an <img>, <script>,
 * <link> or form is only ever LOOKED AT, never followed or submitted), and the
 * DOM is discarded before returning.
 *
 * Alt text: an image counts as "without a description" only when it has NO alt
 * attribute at all. An empty alt="" is the correct way to mark a decorative image
 * and is respected; 1-pixel tracking images are ignored.
 */
final class HtmlFactExtractor
{
    private const MAX_LINKS = 300;

    private const MAX_TITLE = 512;

    private const MAX_DESCRIPTION = 1000;

    /** Static assets / non-page documents we never treat as a page to audit. */
    private const NON_PAGE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'css', 'js', 'json', 'xml', 'txt', 'zip', 'gz', 'rar', 'mp3', 'mp4', 'mov', 'avi', 'webm', 'woff', 'woff2', 'ttf', 'eot', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv'];

    /** XPath 1.0 has no lower-case(): attribute comparisons translate A-Z to a-z explicitly. */
    private static function lc(string $attribute): string
    {
        return 'translate('.$attribute.',"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")';
    }

    public function extract(string $html, string $pageUrl): ExtractedPage
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

        $title = $this->clean($xp->evaluate('string((//title)[1])'), self::MAX_TITLE);
        $description = $this->clean($xp->evaluate('string((//meta['.self::lc('@name').'="description"]/@content)[1])'), self::MAX_DESCRIPTION);

        $robots = strtolower((string) $xp->evaluate('string(//meta['.self::lc('@name').'="robots" or '.self::lc('@name').'="googlebot"]/@content)'));
        $noindex = str_contains($robots, 'noindex') || str_contains($robots, 'none');

        $canonical = null;
        foreach ($xp->query('//link[@href]') ?: [] as $link) {
            if ($link instanceof DOMElement && in_array('canonical', preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [], true)) {
                $canonical = UrlResolver::resolve($pageUrl, $link->getAttribute('href'));

                break;
            }
        }

        $h1Count = 0;
        foreach ($xp->query('//h1') ?: [] as $h1) {
            if (trim($h1->textContent) !== '') {
                $h1Count++;
            }
        }

        $links = [];
        foreach ($xp->query('//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement || count($links) >= self::MAX_LINKS) {
                continue;
            }

            $href = trim($a->getAttribute('href'));

            if ($href === '' || preg_match('#^(mailto|tel|sms|javascript|data|ftp|whatsapp):#i', $href) === 1) {
                continue;
            }

            $resolved = UrlResolver::resolve($pageUrl, $href);

            if ($resolved !== null) {
                $links[$resolved] = true;
            }
        }

        $images = 0;
        $missingAlt = 0;
        foreach ($xp->query('//img') ?: [] as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }

            $width = $img->getAttribute('width');

            if ($width !== '' && (int) $width <= 1 && ctype_digit($width)) {
                continue; // a tracking pixel, not content
            }

            $images++;

            if (! $img->hasAttribute('alt')) {
                $missingAlt++;
            }
        }

        $hasOg = (int) $xp->evaluate('count(//meta[starts-with('.self::lc('@property').',"og:")])') > 0;

        $hasJsonLd = false;
        foreach ($xp->query('//script['.self::lc('@type').'="application/ld+json"]') ?: [] as $script) {
            if (trim($script->textContent) !== '') {
                $hasJsonLd = true;

                break;
            }
        }

        foreach ($xp->query('//script|//style|//noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $xp->query('//body')->item(0);
        $text = $body === null ? '' : preg_replace('/\s+/u', ' ', trim($body->textContent)) ?? '';
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        return new ExtractedPage(
            $title === '' ? null : $title,
            $description === '' ? null : $description,
            $noindex,
            $canonical,
            $h1Count,
            array_keys($links),
            $images,
            $missingAlt,
            $hasOg,
            $hasJsonLd,
            $words,
        );
    }

    /** True for a URL that is plausibly an HTML page (not an image, document or asset). */
    public static function looksLikePage(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === '' || ! in_array($extension, self::NON_PAGE_EXTENSIONS, true);
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
