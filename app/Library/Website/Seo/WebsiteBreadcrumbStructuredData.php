<?php

namespace App\Library\Website\Seo;

/**
 * Website Generator + Local SEO Completion. BreadcrumbList (schema.org)
 * structured data, built deterministically from the exact page/URL
 * facts already resolved for canonical-URL/navigation purposes — never
 * from AI, never fabricated. Google supports BreadcrumbList as a real
 * rich-result signal (unlike the deprecated FAQPage feature or a
 * self-served AggregateRating, which this codebase never emits — see
 * WebsiteLocalBusinessStructuredData's own docblock).
 *
 * A page's position in the trail is inferred only from this Website's
 * own, already-deterministic slug convention (WebsiteStarterDraftService
 * ::createServiceDetailPage()/createLocationPage() prefix a service page
 * `service-*` and a location page `serving-*`) — never a stored
 * "page_type" the renderer would need to become template-aware about.
 * The Home page itself never gets a breadcrumb (a single "Home" crumb on
 * Home would be redundant self-reference).
 */
final class WebsiteBreadcrumbStructuredData
{
    /**
     * @param  array{is_home: bool, slug: ?string, title: string}  $page
     * @param  array<int, array{slug: ?string, title: string, is_home: bool}>  $sitePages  every page on this Website, for resolving a service/location page's parent hub
     */
    public function build(array $page, string $homeUrl, callable $urlFor, array $sitePages): ?array
    {
        if ($page['is_home'] ?? false) {
            return null;
        }

        $items = [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $homeUrl],
        ];

        $position = 2;
        $parentSlug = $this->parentHubSlug((string) ($page['slug'] ?? ''));

        if ($parentSlug !== null) {
            $parent = collect($sitePages)->firstWhere('slug', $parentSlug);
            if ($parent !== null) {
                $items[] = ['@type' => 'ListItem', 'position' => $position++, 'name' => $parent['title'], 'item' => $urlFor($parent)];
            }
        }

        $items[] = ['@type' => 'ListItem', 'position' => $position, 'name' => $page['title'], 'item' => $urlFor($page)];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    private function parentHubSlug(string $slug): ?string
    {
        if (str_starts_with($slug, 'service-')) {
            return 'services';
        }

        return null;
    }
}
