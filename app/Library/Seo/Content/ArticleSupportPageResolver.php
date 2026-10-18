<?php

namespace App\Library\Seo\Content;

use Illuminate\Support\Str;

/**
 * SEO Content Engine V1 — which published page an article topic SUPPORTS, decided deterministically from
 * the topic and the Business's own published pages (ArticleSiteInventory). Pure: no queries, no AI.
 *
 * Order of decision:
 *   1. an explicit hint (from a Niche Blueprint topic): a page kind (packages, services_hub, home, contact,
 *      service, location) or a word found in a service page ("wedding", "360", "corporate") or a city;
 *   2. the topic text: cost / price words -> packages; a word that distinguishes ONE service page
 *      ("wedding", "360") -> that service page; a served city -> its serving-* page;
 *   3. fall back to the services hub, then the home page.
 *
 * Only `linkable` pages (published and not noindex) are ever returned, so an article never "supports" a page
 * that search engines are told to ignore.
 */
final class ArticleSupportPageResolver
{
    /** Words every page of a niche shares or that say nothing about WHICH service a topic is about. */
    private const COMMON_WORDS = [
        'event', 'events', 'party', 'parties', 'rental', 'rentals', 'hire', 'photo', 'photos', 'booth', 'booths',
        'service', 'services', 'package', 'packages', 'for', 'and', 'the', 'of', 'in', 'a', 'to',
    ];

    private const COST_WORDS = ['cost', 'costs', 'price', 'prices', 'pricing', 'afford', 'affordable', 'budget', 'quote'];

    /**
     * @param  array<int, array{uid: string, slug: ?string, title: string, kind: string, linkable: bool}>  $pages
     * @return array<int, array{uid: string, slug: ?string, title: string, kind: string, linkable: bool}>
     */
    public static function linkable(array $pages): array
    {
        return array_values(array_filter($pages, fn (array $p) => $p['linkable']));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages  ArticleSiteInventory pages
     * @param  array<string, mixed>|null  $servicePage  the service page a per-service topic was expanded for
     * @return array<string, mixed>|null
     */
    public function resolve(array $pages, ?string $hint, string $topic, ?array $servicePage = null, ?string $city = null): ?array
    {
        $pages = self::linkable($pages);

        if ($pages === []) {
            return null;
        }

        $hint = $hint !== null ? strtolower(trim($hint)) : null;

        if ($hint !== null && $hint !== '') {
            $byHint = $this->byHint($pages, $hint, $servicePage, $city);

            if ($byHint !== null) {
                return $byHint;
            }
        }

        $lower = ' ' . strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $topic) ?? $topic) . ' ';

        foreach (self::COST_WORDS as $word) {
            if (str_contains($lower, ' ' . $word . ' ')) {
                $packages = $this->ofKind($pages, ArticleSiteInventory::KIND_PACKAGES);

                if ($packages !== null) {
                    return $packages;
                }

                break;
            }
        }

        if ($servicePage !== null) {
            return $servicePage;
        }

        $distinct = $this->distinguishingServicePage($pages, $lower);

        if ($distinct !== null) {
            return $distinct;
        }

        $cityPage = $this->pageForCityInText($pages, $lower);

        if ($cityPage !== null) {
            return $cityPage;
        }

        if ($city !== null && ($page = $this->pageForCity($pages, $city)) !== null) {
            return $page;
        }

        return $this->ofKind($pages, ArticleSiteInventory::KIND_SERVICES_HUB) ?? $this->ofKind($pages, ArticleSiteInventory::KIND_HOME);
    }

    /**
     * The serving-* page for a city, if the Business has published one.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, mixed>|null
     */
    public function pageForCity(array $pages, string $city): ?array
    {
        $slug = Str::slug($city);

        if ($slug === '') {
            return null;
        }

        foreach (self::linkable($pages) as $page) {
            if ($page['kind'] === ArticleSiteInventory::KIND_LOCATION && ($page['slug'] ?? '') === 'serving-' . $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, mixed>|null
     */
    public function pageForCityInText(array $pages, string $loweredText): ?array
    {
        $text = ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $loweredText) ?? $loweredText) . ' ';

        foreach (self::linkable($pages) as $page) {
            if ($page['kind'] !== ArticleSiteInventory::KIND_LOCATION) {
                continue;
            }

            $citySlug = (string) preg_replace('/^serving-/', '', (string) ($page['slug'] ?? ''));
            $cityWords = trim(str_replace('-', ' ', $citySlug));

            if ($cityWords !== '' && str_contains($text, ' ' . $cityWords . ' ')) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, mixed>|null
     */
    private function byHint(array $pages, string $hint, ?array $servicePage, ?string $city): ?array
    {
        switch ($hint) {
            case 'packages':
            case 'package':
            case 'pricing':
                return $this->ofKind($pages, ArticleSiteInventory::KIND_PACKAGES);
            case 'home':
                return $this->ofKind($pages, ArticleSiteInventory::KIND_HOME);
            case 'services_hub':
            case 'services':
                return $this->ofKind($pages, ArticleSiteInventory::KIND_SERVICES_HUB);
            case 'contact':
                return $this->ofKind($pages, ArticleSiteInventory::KIND_CONTACT);
            case 'service':
                return $servicePage
                    ?? $this->ofKind($pages, ArticleSiteInventory::KIND_SERVICES_HUB)
                    ?? $this->ofKind($pages, ArticleSiteInventory::KIND_HOME);
            case 'location':
                return ($city !== null ? $this->pageForCity($pages, $city) : null)
                    ?? $this->ofKind($pages, ArticleSiteInventory::KIND_LOCATION)
                    ?? $this->ofKind($pages, ArticleSiteInventory::KIND_HOME);
        }

        $word = ' ' . trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $hint) ?? $hint) . ' ';

        foreach ($pages as $page) {
            if ($page['kind'] !== ArticleSiteInventory::KIND_SERVICE) {
                continue;
            }

            $haystack = ' ' . strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', ($page['title'] ?? '') . ' ' . str_replace('-', ' ', (string) ($page['slug'] ?? ''))) ?? '') . ' ';

            if (str_contains($haystack, $word)) {
                return $page;
            }
        }

        foreach ($pages as $page) {
            if ($page['kind'] === ArticleSiteInventory::KIND_LOCATION && str_contains(' ' . str_replace('-', ' ', (string) ($page['slug'] ?? '')) . ' ', $word)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, mixed>|null
     */
    private function ofKind(array $pages, string $kind): ?array
    {
        foreach ($pages as $page) {
            if ($page['kind'] === $kind) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The one service page a topic names by a word only that page has ("wedding", "360", "glam").
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, mixed>|null
     */
    private function distinguishingServicePage(array $pages, string $loweredText): ?array
    {
        $services = array_values(array_filter($pages, fn (array $p) => $p['kind'] === ArticleSiteInventory::KIND_SERVICE));
        $wordsByPage = [];
        $count = [];

        foreach ($services as $i => $page) {
            $words = array_values(array_unique(array_filter(
                explode(' ', strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', ($page['title'] ?? '') . ' ' . str_replace('-', ' ', (string) preg_replace('/^service-/', '', (string) ($page['slug'] ?? '')))) ?? '')),
                fn (string $w) => $w !== '' && ! in_array($w, self::COMMON_WORDS, true),
            )));
            $wordsByPage[$i] = $words;

            foreach ($words as $w) {
                $count[$w] = ($count[$w] ?? 0) + 1;
            }
        }

        foreach ($services as $i => $page) {
            foreach ($wordsByPage[$i] as $w) {
                if ($count[$w] === 1 && str_contains($loweredText, ' ' . $w . ' ')) {
                    return $page;
                }
            }
        }

        return null;
    }
}
