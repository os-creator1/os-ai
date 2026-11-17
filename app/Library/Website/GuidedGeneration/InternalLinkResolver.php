<?php

namespace App\Library\Website\GuidedGeneration;

/**
 * The authoritative internal link targets of a generation plan, and the one narrow correction applied to AI output
 * before validation: a CTA that points at a well-known generic alias ("/contact", "/booking", "/about-us") is pointed at
 * the page of that kind the plan actually has, but ONLY when exactly one planned page has that kind. Anything else
 * (an alias with no, or several, matching pages; any other unknown path) is left untouched so the validator rejects it —
 * a link is never guessed. Home is "/".
 */
final class InternalLinkResolver
{
    /** alias path (no leading slash, lowercase) => planned page_type that is the one page it can mean. */
    private const ALIASES = [
        'contact' => 'contact', 'contact-us' => 'contact', 'book' => 'contact', 'booking' => 'contact', 'book-now' => 'contact',
        'get-a-quote' => 'contact', 'quote' => 'contact', 'request-a-quote' => 'contact',
        'about' => 'about', 'about-us' => 'about',
        'faq' => 'faq', 'faqs' => 'faq',
        'gallery' => 'gallery', 'photos' => 'gallery',
        'services' => 'services_overview', 'our-services' => 'services_overview',
        'packages' => 'packages', 'pricing' => 'packages',
        'backdrops' => 'backdrops',
    ];

    private const HOME_ALIASES = ['home', 'index', 'index.html'];

    /**
     * Every valid internal path of the plan, Home first.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, string>
     */
    public static function paths(array $plan): array
    {
        $paths = [];
        foreach ($plan as $page) {
            $paths[] = ! empty($page['is_home']) ? '/' : '/' . ltrim((string) $page['slug'], '/');
        }

        usort($paths, fn ($a, $b) => ($a === '/' ? 0 : 1) <=> ($b === '/' ? 0 : 1));

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages  the AI batch
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array<string, mixed>>
     */
    public static function correct(array $pages, array $plan): array
    {
        foreach ($pages as $pageIndex => $page) {
            foreach ((array) ($page['sections'] ?? []) as $sectionIndex => $section) {
                if (! is_array($section) || ! is_array($section['data'] ?? null)) {
                    continue;
                }

                foreach (['primary_cta', 'secondary_cta'] as $field) {
                    if (is_array($section['data'][$field] ?? null) && isset($section['data'][$field]['url'])) {
                        $pages[$pageIndex]['sections'][$sectionIndex]['data'][$field]['url'] = self::resolve((string) $section['data'][$field]['url'], $plan);
                    }
                }

                foreach ((array) ($section['data']['buttons'] ?? []) as $buttonIndex => $button) {
                    if (is_array($button) && isset($button['url'])) {
                        $pages[$pageIndex]['sections'][$sectionIndex]['data']['buttons'][$buttonIndex]['url'] = self::resolve((string) $button['url'], $plan);
                    }
                }
            }
        }

        return $pages;
    }

    /** The planned path for $url, or $url unchanged when it is not an internal path or cannot be resolved without guessing. */
    public static function resolve(string $url, array $plan): string
    {
        $trimmed = trim($url);

        if (! str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//')) {
            return $url;
        }

        // Only the path decides the page; a query string or fragment is dropped, never kept on an internal page link.
        $path = strtolower(trim((string) parse_url($trimmed, PHP_URL_PATH), '/'));

        if ($path === '' || in_array($path, self::HOME_ALIASES, true)) {
            return '/';
        }

        $slugs = [];
        foreach ($plan as $page) {
            if (empty($page['is_home'])) {
                $slugs[strtolower((string) $page['slug'])] = (string) $page['slug'];
            }
        }

        if (isset($slugs[$path])) {
            return '/' . $slugs[$path];
        }

        $type = self::ALIASES[$path] ?? null;
        if ($type === null) {
            return $url;
        }

        $matches = array_values(array_filter($plan, fn ($page) => empty($page['is_home']) && ($page['page_type'] ?? null) === $type));

        return count($matches) === 1 ? '/' . $matches[0]['slug'] : $url;
    }
}
