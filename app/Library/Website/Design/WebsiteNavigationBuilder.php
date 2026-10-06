<?php

namespace App\Library\Website\Design;

/**
 * Website V1 final — builds the site's COMPACT navigation, deliberately
 * separate from the page tree. The tree can hold up to 14 pages (a Services
 * overview, a page per service, a page per area, ...); a header that lists
 * them all overflows on a phone. So:
 *
 *   primary:  Home · Services ▾ · Packages · Gallery ▾ · Locations ▾ · About · Contact
 *   footer:   Explore (every top-level page) · Services · Areas we serve
 *
 * Every page is still reachable (no orphan pages — each one is a child of a
 * dropdown or a footer link), but the header never shows more than ~7 items.
 * Pages are classified by the deterministic slug conventions the generator
 * already owns (`services`, `service-*`, `serving-*`, ...).
 *
 * Input items: ['uid', 'title', 'is_home', 'slug', 'url'] — from the draft
 * pages (preview) or the immutable snapshot (public / custom domain).
 */
final class WebsiteNavigationBuilder
{
    private const MAX_DROPDOWN_CHILDREN = 10;

    /**
     * @param  array<int, array{uid: string, title: string, is_home: bool, slug?: ?string, url: string}>  $pages
     * @return array{primary: array<int, array<string, mixed>>, footer: array<string, array<int, array{title: string, url: string}>>, has_pages: bool}
     */
    public function build(array $pages, ?string $currentUid = null): array
    {
        $home = null;
        $overview = null;
        $services = [];
        $packages = null;
        $gallery = null;
        $backdrops = null;
        $about = null;
        $faq = null;
        $contact = null;
        $areas = [];
        $other = [];

        // Fixed pages carry a canonical menu label (never the AI-written page
        // title, which may be a headline); area pages drop their "Serving ".
        $labels = ['services' => 'Services', 'packages' => 'Packages', 'gallery' => 'Gallery', 'backdrops' => 'Backdrops', 'photo-booth-about' => 'About', 'photo-booth-faq' => 'FAQ', 'photo-booth-contact' => 'Contact'];

        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');

            if ((bool) ($page['is_home'] ?? false)) {
                $page['title'] = 'Home';
            } elseif (isset($labels[$slug])) {
                $page['title'] = $labels[$slug];
            } elseif (str_starts_with($slug, 'serving-')) {
                $page['title'] = trim((string) preg_replace('/^Serving\s+/i', '', (string) $page['title'])) ?: $page['title'];
            }

            match (true) {
                (bool) ($page['is_home'] ?? false) => $home = $page,
                $slug === 'services' => $overview = $page,
                str_starts_with($slug, 'service-') => $services[] = $page,
                $slug === 'packages' => $packages = $page,
                $slug === 'gallery' => $gallery = $page,
                $slug === 'backdrops' => $backdrops = $page,
                $slug === 'photo-booth-about' => $about = $page,
                $slug === 'photo-booth-faq' => $faq = $page,
                $slug === 'photo-booth-contact' => $contact = $page,
                str_starts_with($slug, 'serving-') => $areas[] = $page,
                default => $other[] = $page,
            };
        }

        $item = fn (array $page, array $children = []): array => [
            'title' => $page['title'],
            'url' => $page['url'],
            'current' => $currentUid !== null && ($page['uid'] ?? null) === $currentUid,
            'children' => $children,
        ];
        $child = fn (array $page): array => $item($page);
        $children = fn (array $list): array => array_map($child, array_slice($list, 0, self::MAX_DROPDOWN_CHILDREN));

        $primary = [];

        if ($home !== null) {
            $primary[] = $item($home);
        }

        if ($overview !== null) {
            $primary[] = $item($overview, $children($services));
        } elseif ($services !== []) {
            $primary[] = ['title' => 'Services', 'url' => null, 'current' => false, 'children' => $children($services)];
        }

        if ($packages !== null) {
            $primary[] = $item($packages);
        }

        if ($gallery !== null) {
            $primary[] = $item($gallery, $backdrops !== null ? [$child($backdrops)] : []);
        } elseif ($backdrops !== null) {
            $primary[] = $item($backdrops);
        }

        if ($areas !== []) {
            $primary[] = ['title' => 'Locations', 'url' => null, 'current' => false, 'children' => $children($areas)];
        }

        if ($about !== null) {
            $primary[] = $item($about);
        }

        if ($contact !== null) {
            $primary[] = $item($contact);
        }

        // Any dropdown whose child is the current page is itself "current".
        foreach ($primary as &$entry) {
            $entry['current'] = $entry['current'] || (bool) array_filter($entry['children'], fn ($c) => $c['current']);
        }
        unset($entry);

        $link = fn (array $page): array => ['title' => $page['title'], 'url' => $page['url']];
        $explore = array_values(array_map($link, array_filter([$home, $overview, $packages, $gallery, $backdrops, $about, $faq, $contact], fn ($p) => $p !== null)));

        foreach ($other as $page) {
            $explore[] = $link($page);
        }

        return [
            'primary' => $primary,
            'footer' => [
                'explore' => $explore,
                'services' => array_map($link, array_slice($services, 0, 8)),
                'areas' => array_map($link, array_slice($areas, 0, 8)),
            ],
            'has_pages' => count($pages) > 1,
        ];
    }
}
