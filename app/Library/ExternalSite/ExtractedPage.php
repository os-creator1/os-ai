<?php

namespace App\Library\ExternalSite;

/**
 * The only thing the rest of the system learns about a fetched HTML page: a
 * handful of normalised values. No DOM node, no HTML, no header and no body
 * leaves the extractor, so remote markup cannot reach the audit engine or storage.
 */
final class ExtractedPage
{
    /** @param list<string> $links absolute, deduplicated, fragment-free http(s) links found in the page */
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
    ) {
    }
}
