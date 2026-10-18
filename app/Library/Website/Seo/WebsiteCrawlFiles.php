<?php

namespace App\Library\Website\Seo;

/**
 * SEO V1 final — the plain-text/XML crawl files of a published Website
 * (robots.txt and sitemap.xml), built in ONE place so the platform host
 * and every custom domain agree on their shape.
 *
 * - Line endings are always "\n", whatever the checkout/OS that built it.
 * - robots.txt never blocks anything: CSS, JS and images must stay
 *   crawlable, and a noindex page is only honoured when the crawler is
 *   allowed to fetch it.
 * - The Sitemap: line appears only when the sitemap would actually list
 *   at least one indexable page.
 * - The sitemap lists the canonical, indexable pages of the published
 *   snapshot. `lastmod` is omitted on purpose: a snapshot knows when the
 *   whole site was last published, not when each page last changed, and
 *   a lastmod that is merely "the publish date" is noise to crawlers.
 */
final class WebsiteCrawlFiles
{
    /** The protocol limit; a Website is capped far below it (20 pages) — checked anyway. */
    public const SITEMAP_MAX_URLS = 50000;

    /** The platform host's own robots.txt (the platform path is noindex, so nothing is blocked). */
    public static function platformRobots(): string
    {
        return "User-agent: *\nDisallow:\n";
    }

    public static function customDomainRobots(string $domain, array $snapshot): string
    {
        $body = self::platformRobots();

        if (self::indexableUrls($domain, $snapshot) !== []) {
            $body .= "\nSitemap: https://".$domain."/sitemap.xml\n";
        }

        return $body;
    }

    /**
     * @return array<int, string> absolute canonical URLs of the indexable pages, de-duplicated, in page order
     */
    public static function indexableUrls(string $domain, array $snapshot, array $extraUrls = []): array
    {
        $urls = [];

        foreach ((array) ($snapshot['pages'] ?? []) as $page) {
            if (! empty($page['seo']['noindex'])) {
                continue;
            }

            if (empty($page['is_home']) && ($page['slug'] ?? null) === null) {
                continue;
            }

            $urls['https://'.$domain.(! empty($page['is_home']) ? '/' : '/'.$page['slug'])] = true;
        }

        // SEO Content Engine V1: the blog index and published, indexable articles (decided by WebsiteBlogRenderer) join
        // the same list, so there is exactly one sitemap and one ordering.
        foreach ($extraUrls as $url) {
            $urls[(string) $url] = true;
        }

        return array_slice(array_keys($urls), 0, self::SITEMAP_MAX_URLS);
    }

    /** The sitemap document, or null when no page is indexable (the caller answers 404, never an empty urlset). */
    public static function sitemap(string $domain, array $snapshot, array $extraUrls = []): ?string
    {
        $urls = self::indexableUrls($domain, $snapshot, $extraUrls);

        if ($urls === []) {
            return null;
        }

        $entries = implode('', array_map(fn (string $url) => '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>', $urls));

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</urlset>'."\n";
    }
}
