<?php

namespace App\Library\ExternalSite;

/**
 * Decides, from the URL alone and BEFORE anything is fetched, whether an address
 * is an ordinary content page worth auditing or something that must never be
 * judged as one.
 *
 * Why it exists: the first version fetched every extension-less link as a page,
 * so Cloudflare's `/cdn-cgi/l/email-protection` endpoint, feeds and API routes were
 * "audited" and reported as pages with a missing title and description. Those
 * addresses are not landing pages; reporting them as such is false. They are
 * recorded as excluded, with the reason, and counted separately from pages.
 *
 * It also keeps the crawler out of private areas (log-in, admin, account, cart and
 * checkout) and away from parameter explosions (search, sort and filter
 * addresses).
 */
final class UrlClassifier
{
    public const PAGE = 'page';

    public const ASSET = 'non_html_asset';

    public const UTILITY = 'utility_endpoint';

    public const PRIVATE_AREA = 'private_area';

    public const QUERY_VARIANT = 'query_variant';

    /** Path prefixes of platform plumbing that is not a content page. */
    private const UTILITY_PREFIXES = ['/cdn-cgi/', '/wp-json', '/wp-content/', '/wp-includes/', '/_next/', '/_nuxt/', '/.well-known/', '/api/', '/feed', '/comments/feed', '/xmlrpc.php', '/wp-cron.php', '/admin-ajax'];

    /** Path prefixes that are private or transactional areas, never crawled. */
    private const PRIVATE_PREFIXES = ['/wp-admin', '/wp-login', '/admin', '/login', '/log-in', '/signin', '/sign-in', '/logout', '/my-account', '/account', '/dashboard', '/cart', '/checkout', '/basket', '/user/', '/members'];

    private const ASSET_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'css', 'js', 'mjs', 'json', 'xml', 'txt', 'zip', 'gz', 'rar', 'mp3', 'mp4', 'mov', 'avi', 'webm', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'rss', 'atom', 'map'];

    /**
     * @param  bool  $fromSitemap  a URL the owner's own sitemap lists is trusted a little more: a parameter URL in the sitemap is still audited
     * @return array{class: string, reason: ?string}
     */
    public static function classify(string $url, bool $fromSitemap = false): array
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension !== '' && in_array($extension, self::ASSET_EXTENSIONS, true)) {
            return ['class' => self::ASSET, 'reason' => $extension];
        }

        foreach (self::UTILITY_PREFIXES as $prefix) {
            if (preg_match('#^'.preg_quote(rtrim($prefix, '/'), '#').'(?:[/.]|$)#', $path) === 1) {
                return ['class' => self::UTILITY, 'reason' => trim($prefix, '/')];
            }
        }

        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if (preg_match('#^'.preg_quote(rtrim($prefix, '/'), '#').'(?:[/.]|$)#', $path) === 1) {
                return ['class' => self::PRIVATE_AREA, 'reason' => trim($prefix, '/')];
            }
        }

        if (! $fromSitemap && UrlResolver::hasMeaningfulQuery($url)) {
            return ['class' => self::QUERY_VARIANT, 'reason' => 'query'];
        }

        return ['class' => self::PAGE, 'reason' => null];
    }

    public static function isPage(string $url, bool $fromSitemap = false): bool
    {
        return self::classify($url, $fromSitemap)['class'] === self::PAGE;
    }

    /** The owner-facing explanation of an exclusion reason class. */
    public static function describe(string $class): string
    {
        return match ($class) {
            self::ASSET => 'A file such as an image, document, script or stylesheet, not a web page.',
            self::UTILITY => 'A technical address used by the website platform (for example an email-protection or API address), not a page visitors land on.',
            self::PRIVATE_AREA => 'A log-in, account, cart or admin area. MotionGrove never crawls private or transactional areas.',
            self::QUERY_VARIANT => 'An address with search, sort or filter parameters. These are variations of other pages and are not crawled one by one.',
            default => 'Not treated as a page.',
        };
    }
}
