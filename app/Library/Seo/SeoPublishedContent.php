<?php

namespace App\Library\Seo;

/**
 * Contract 18 §9.1 — the published Website snapshot's SEO-relevant facts,
 * read once, immutable, and derived from `website_revisions.snapshot` only.
 */
final class SeoPublishedContent
{
    /**
     * @param  array<int, SeoPublishedPage>  $pages
     * @param  array<int, array{uid: string, alt_text: ?string}>  $assets  assets referenced by the snapshot
     */
    public function __construct(
        public readonly int $websiteId,
        public readonly int $revisionId,
        public readonly array $pages,
        private readonly array $assets,
    ) {
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function pagesWithMetaDescription(): int
    {
        return count(array_filter($this->pages, fn (SeoPublishedPage $p) => $p->hasMetaDescription()));
    }

    public function pagesWithSeoTitle(): int
    {
        return count(array_filter($this->pages, fn (SeoPublishedPage $p) => $p->hasSeoTitle()));
    }

    public function pagesMarkedNoindex(): int
    {
        return count(array_filter($this->pages, fn (SeoPublishedPage $p) => $p->noindex));
    }

    /**
     * Uids of referenced assets that have no alt text. (Findings built on
     * this belong to the audit sub-slice; the read lives here so the
     * snapshot is parsed in one place.)
     *
     * @return array<int, string>
     */
    public function assetsMissingAltText(): array
    {
        return array_values(array_map(
            fn (array $a) => $a['uid'],
            array_filter($this->assets, fn (array $a) => $a['alt_text'] === null || trim($a['alt_text']) === ''),
        ));
    }
}
