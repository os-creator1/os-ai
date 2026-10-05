<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoIndexabilityState;

/**
 * Contract 18 §5.2.4 (revised, SEO V1 final) — the single, honest "can search
 * engines find my website?" line shown on the SEO Overview and the Website
 * check.
 *
 * PURE: a published snapshot plus one fact (does the Website have an ACTIVE
 * PRIMARY domain?) in, plain words out. It reads nothing and calls nothing.
 *
 * WHAT IS TRUE TODAY. A site served from the platform path is always
 * `noindex`. A site served from its Active primary custom domain is indexable
 * page by page: each page is `index, follow` unless the owner marked THAT page
 * hidden from search (ResolveCustomDomainWebsite). So, from the PUBLISHED
 * snapshot only:
 *
 *   no published website                       -> NoPublishedWebsite
 *   published, no Active primary domain        -> PlatformPathNotIndexable
 *   published + domain, every page hidden      -> HiddenFromSearch
 *   published + domain, at least one visible   -> Indexable ("N of M pages")
 *
 * Counts are exact: M is the number of pages in the published revision, N is M
 * minus the pages whose own SEO setting hides them. It is a STATUS, never a
 * finding, and says nothing about ranking.
 */
final class SeoIndexability
{
    public const TONE_GOOD = 'good';
    public const TONE_ATTENTION = 'attention';
    public const TONE_ACTION = 'action';

    public const ACTION_PUBLISH = 'publish';
    public const ACTION_CONNECT_DOMAIN = 'connect_domain';
    public const ACTION_ALLOW_INDEXING = 'allow_indexing';

    public function __construct(
        public readonly SeoIndexabilityState $state,
        public readonly int $indexablePages = 0,
        public readonly int $totalPages = 0,
    ) {
    }

    public static function resolve(?SeoPublishedContent $published, bool $hasActivePrimaryDomain): self
    {
        if ($published === null || $published->pageCount() === 0) {
            return new self(SeoIndexabilityState::NoPublishedWebsite);
        }

        $total = $published->pageCount();
        $indexable = $total - $published->pagesMarkedNoindex();

        $state = match (true) {
            ! $hasActivePrimaryDomain => SeoIndexabilityState::PlatformPathNotIndexable,
            $indexable === 0 => SeoIndexabilityState::HiddenFromSearch,
            default => SeoIndexabilityState::Indexable,
        };

        return new self($state, $indexable, $total);
    }

    public function hiddenPages(): int
    {
        return $this->totalPages - $this->indexablePages;
    }

    public function label(): string
    {
        return match ($this->state) {
            SeoIndexabilityState::NoPublishedWebsite => 'No published website yet',
            SeoIndexabilityState::PlatformPathNotIndexable => 'Your website is live, but search engines cannot find it yet',
            SeoIndexabilityState::HiddenFromSearch => 'Your site is live but hidden from search',
            SeoIndexabilityState::Indexable => 'Search engines can find ' . $this->indexablePages . ' of ' . $this->totalPages . ' ' . ($this->totalPages === 1 ? 'page' : 'pages'),
        };
    }

    public function detail(): string
    {
        return match ($this->state) {
            SeoIndexabilityState::NoPublishedWebsite => 'Publish your website to have something for search engines to find.',
            SeoIndexabilityState::PlatformPathNotIndexable => 'Your site is only reachable at the platform address, which search engines are asked not to list. Connect your own domain so customers can find it in search.',
            SeoIndexabilityState::HiddenFromSearch => ($this->totalPages === 1 ? 'Your only page is' : 'All ' . $this->totalPages . ' pages are') . ' marked as hidden from search engines, so nothing on your site can appear in results. Allow search engines in Website, then Pages.',
            SeoIndexabilityState::Indexable => $this->hiddenPages() === 0
                ? 'Your website is live on your own domain and every page can appear in search results. Being findable does not guarantee a ranking.'
                : $this->hiddenPages() . ($this->hiddenPages() === 1 ? ' page is' : ' pages are') . ' marked as hidden from search and will not appear in results. If customers should find them, allow search engines in Website, then Pages.',
        };
    }

    /** Plain-word status: good / attention / action. */
    public function tone(): string
    {
        return match ($this->state) {
            SeoIndexabilityState::Indexable => $this->hiddenPages() === 0 ? self::TONE_GOOD : self::TONE_ATTENTION,
            default => self::TONE_ACTION,
        };
    }

    public function toneWord(): string
    {
        return match ($this->tone()) {
            self::TONE_GOOD => 'Good',
            self::TONE_ATTENTION => 'Needs attention',
            default => 'Action',
        };
    }

    /** Which existing Website screen fixes it, or null when nothing needs doing. */
    public function action(): ?string
    {
        return match ($this->state) {
            SeoIndexabilityState::NoPublishedWebsite => self::ACTION_PUBLISH,
            SeoIndexabilityState::PlatformPathNotIndexable => self::ACTION_CONNECT_DOMAIN,
            SeoIndexabilityState::HiddenFromSearch => self::ACTION_ALLOW_INDEXING,
            SeoIndexabilityState::Indexable => $this->hiddenPages() > 0 ? self::ACTION_ALLOW_INDEXING : null,
        };
    }

    public function actionLabel(): ?string
    {
        return match ($this->action()) {
            self::ACTION_PUBLISH => 'Open your website',
            self::ACTION_CONNECT_DOMAIN => 'Connect a domain',
            self::ACTION_ALLOW_INDEXING => 'Allow search engines',
            default => null,
        };
    }
}
