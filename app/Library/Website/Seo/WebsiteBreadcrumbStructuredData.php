<?php

namespace App\Library\Website\Seo;

use App\Library\Website\Design\WebsiteNavigationBuilder;

/**
 * Website Generator + Local SEO Completion. BreadcrumbList (schema.org)
 * structured data, built deterministically from the exact page/URL
 * facts already resolved for canonical-URL/navigation purposes — never
 * from AI, never fabricated.
 *
 * SEO V1 final: the trail is ONE thing, computed once by trail() and used
 * for BOTH the visible breadcrumb (public.website.breadcrumbs) and this
 * JSON-LD, so the markup always matches what a visitor sees. Names come
 * from the navigation's own label authority (WebsiteNavigationBuilder::
 * label), never the raw page title ("Serving Austin" is crumb "Austin",
 * exactly as the menu shows it). A parent hub is only ever a page that
 * really exists (a service page's "Services" overview); location pages
 * have no hub page, so their trail is Home > Location.
 *
 * The Home page itself never gets a breadcrumb.
 */
final class WebsiteBreadcrumbStructuredData
{
    /**
     * @param  array{uid?: string, slug?: ?string, title: string, is_home?: bool}  $page  the current page
     * @param  array<int, array{uid?: string, slug?: ?string, title: string, is_home?: bool, url: string}>  $pagesWithUrl  every page of the site, each carrying its public URL
     * @return array<int, array{name: string, url: string}>  empty on the Home page
     */
    public static function trail(array $page, array $pagesWithUrl): array
    {
        if (! empty($page['is_home'])) {
            return [];
        }

        $byPath = [];
        $home = null;

        foreach ($pagesWithUrl as $candidate) {
            if (! empty($candidate['is_home'])) {
                $home = $candidate;
            } elseif (($candidate['slug'] ?? null) !== null) {
                $byPath[(string) $candidate['slug']] = $candidate;
            }
        }

        if ($home === null) {
            return [];
        }

        $trail = [['name' => 'Home', 'url' => (string) $home['url']]];

        $parentSlug = str_starts_with((string) ($page['slug'] ?? ''), 'service-') ? 'services' : null;

        if ($parentSlug !== null && isset($byPath[$parentSlug])) {
            $parent = $byPath[$parentSlug];
            $trail[] = ['name' => WebsiteNavigationBuilder::label($parent), 'url' => (string) $parent['url']];
        }

        $self = $byPath[(string) ($page['slug'] ?? '')] ?? null;

        if ($self === null) {
            return [];
        }

        $trail[] = ['name' => WebsiteNavigationBuilder::label($self), 'url' => (string) $self['url']];

        return $trail;
    }

    /**
     * @param  array<int, array{name: string, url: string}>  $trail  from trail()
     * @return ?array<string, mixed>  null when there is no trail (the Home page)
     */
    public function build(array $trail): ?array
    {
        if (count($trail) < 2) {
            return null;
        }

        $items = [];

        foreach (array_values($trail) as $index => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $crumb['name'], 'item' => $crumb['url']];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
