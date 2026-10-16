<?php

namespace App\Library\ExternalSite;

/**
 * The only thing the rest of the system learns about a fetched HTML page: a
 * handful of normalised values. No DOM node, no HTML, no header and no body
 * leaves the extractor, so remote markup cannot reach the audit engine or storage.
 *
 * Every value describes THIS page only. Counts such as `h1Count` are the number
 * of <h1> elements on this one document — never a total over the crawl — which is
 * what lets the audit say "this page has 2 main headings" and mean it.
 */
final class ExtractedPage
{
    /**
     * @param  list<string>  $links  absolute, deduplicated, fragment-free http(s) links found in the page
     * @param  list<array{url: string, anchor: string, nofollow: bool}>  $linkDetails  the same links with their visible anchor text
     * @param  list<array{src: string, alt: ?string, decorative: bool, linked: bool, width: ?int, height: ?int, lazy: bool}>  $images  alt === null means NO alt attribute
     * @param  list<array{level: int, text: string}>  $headings  h1..h6 in document order (bounded)
     * @param  list<string>  $h1Texts  the non-empty <h1> texts (bounded)
     * @param  list<string>  $canonicalAll  every canonical URL declared in the page's markup
     * @param  list<array{types: list<string>, valid: bool, error: ?string, missing: list<string>}>  $structuredData  one entry per JSON-LD block
     * @param  list<string>  $phones  distinct phone numbers (digits only) seen in the page text or tel: links
     * @param  list<string>  $insecureResources  http:// resources referenced by an https page
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $metaDescription,
        public readonly bool $noindex,
        public readonly ?string $canonicalUrl,
        public readonly int $h1Count,
        public readonly array $links,
        public readonly int $imageCount,
        public readonly int $imagesMissingAlt,
        public readonly bool $hasOpenGraph,
        public readonly bool $hasJsonLd,
        public readonly int $wordCount,
        public readonly ?string $metaRobots = null,
        public readonly int $titleCount = 0,
        public readonly array $canonicalAll = [],
        public readonly array $h1Texts = [],
        public readonly int $emptyHeadings = 0,
        public readonly array $headings = [],
        public readonly array $linkDetails = [],
        public readonly array $images = [],
        public readonly array $structuredData = [],
        public readonly bool $hasViewport = false,
        public readonly ?string $lang = null,
        public readonly bool $hasOgTitle = false,
        public readonly bool $hasOgImage = false,
        public readonly array $phones = [],
        public readonly bool $hasTelLink = false,
        public readonly bool $hasMailtoLink = false,
        public readonly bool $hasAddressElement = false,
        public readonly bool $hasMapEmbed = false,
        public readonly int $formCount = 0,
        public readonly int $scriptCount = 0,
        public readonly int $stylesheetCount = 0,
        public readonly array $insecureResources = [],
        public readonly bool $nofollowAll = false,
        /** @var array<string, array{title: bool, h1: bool, body: bool}> where each requested term (for example a city name) appears on THIS page */
        public readonly array $termHits = [],
    ) {
    }
}
