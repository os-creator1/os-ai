<?php

namespace App\Library\Seo\Content;

use App\Library\Seo\SeoPublishedContentReader;
use App\Library\Seo\SeoPublishedPage;
use App\Models\Business;

/**
 * SEO Content Engine V1 — the Website's PUBLISHED pages, as the content engine sees them: each page
 * classified (home / service hub / service / location / packages / other) from the same deterministic
 * slug conventions the Website generator and the navigation already own. It is the single input for
 * "which page does this article support", "which pages are money pages we must not compete with" and
 * "which pages may an article link to".
 *
 * It reads ONLY through SeoPublishedContentReader — the one parser of the immutable published
 * revision — so it can never see a draft page, a Preview URL or another Business's pages, and it
 * makes no provider or AI call.
 *
 * `linkable` is the single rule for "may an article link to this page": published and not noindex.
 */
final class ArticleSiteInventory
{
    public const KIND_HOME = 'home';
    public const KIND_SERVICES_HUB = 'services_hub';
    public const KIND_SERVICE = 'service';
    public const KIND_LOCATION = 'location';
    public const KIND_PACKAGES = 'packages';
    public const KIND_CONTACT = 'contact';
    public const KIND_OTHER = 'other';

    /** Kinds that carry a high-intent "hire / book this" search of their own. */
    public const MONEY_KINDS = [self::KIND_HOME, self::KIND_SERVICES_HUB, self::KIND_SERVICE, self::KIND_LOCATION, self::KIND_PACKAGES];

    public function __construct(private readonly SeoPublishedContentReader $content)
    {
    }

    /**
     * @return array<int, array{uid: string, slug: ?string, title: string, seo_title: ?string, kind: string, noindex: bool, money: bool, linkable: bool}>
     */
    public function pages(Business $business): array
    {
        $published = $this->content->forBusiness($business);

        if ($published === null) {
            return [];
        }

        return array_values(array_map(fn (SeoPublishedPage $page) => $this->describe($page), $published->pages));
    }

    /** @return array{uid: string, slug: ?string, title: string, seo_title: ?string, kind: string, noindex: bool, money: bool, linkable: bool}|null */
    public function find(Business $business, string $pageUid): ?array
    {
        foreach ($this->pages($business) as $page) {
            if ($page['uid'] === $pageUid) {
                return $page;
            }
        }

        return null;
    }

    /** @return array{uid: string, slug: ?string, title: string, seo_title: ?string, kind: string, noindex: bool, money: bool, linkable: bool} */
    private function describe(SeoPublishedPage $page): array
    {
        $kind = self::kindOf($page->isHome, (string) $page->slug);

        return [
            'uid' => $page->uid,
            'slug' => $page->slug,
            'title' => $page->title,
            'seo_title' => $page->hasSeoTitle() ? (string) $page->seoTitle : null,
            'kind' => $kind,
            'noindex' => $page->noindex,
            'money' => in_array($kind, self::MONEY_KINDS, true),
            'linkable' => ! $page->noindex,
        ];
    }

    public static function kindOf(bool $isHome, string $slug): string
    {
        return match (true) {
            $isHome => self::KIND_HOME,
            $slug === 'services' => self::KIND_SERVICES_HUB,
            str_starts_with($slug, 'service-') => self::KIND_SERVICE,
            str_starts_with($slug, 'serving-') => self::KIND_LOCATION,
            $slug === 'packages' => self::KIND_PACKAGES,
            $slug === 'photo-booth-contact' => self::KIND_CONTACT,
            default => self::KIND_OTHER,
        };
    }

    /**
     * The searches a page is built to answer: its title, SEO title and the words of its slug
     * ("service-wedding-photo-booth-rental" → "wedding photo booth rental"). These are what an article
     * must not duplicate.
     *
     * @param  array{slug: ?string, title: string, seo_title: ?string, kind: string}  $page
     * @return array<int, string>
     */
    public static function targetPhrases(array $page): array
    {
        $phrases = [$page['title']];

        if ($page['seo_title'] !== null) {
            $phrases[] = $page['seo_title'];
        }

        $slug = (string) ($page['slug'] ?? '');

        if ($slug !== '') {
            $phrases[] = str_replace('-', ' ', (string) preg_replace('/^(service|serving)-/', '', $slug));
        }

        return array_values(array_unique(array_filter(array_map('trim', $phrases))));
    }
}
