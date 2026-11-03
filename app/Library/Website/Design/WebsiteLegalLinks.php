<?php

namespace App\Library\Website\Design;

/**
 * The owner's own legal-notice links for a published site: a privacy notice and (optionally) terms. They are
 * ADDRESSES the owner supplies — nothing here writes, copies or implies any legal text, and the platform's own
 * policies are never substituted. Stored with the other owner tokens in the Website theme (`privacy_notice_url`,
 * `terms_url`), so they survive a rebuild or template change and are frozen into each published revision.
 */
final class WebsiteLegalLinks
{
    public const KEYS = ['privacy_notice_url', 'terms_url'];

    /**
     * Only an absolute https address with a host is ever linked (never javascript:, data:, relative or http).
     */
    public static function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || mb_strlen($value) > 500 || preg_match('/\s/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    /**
     * @param  ?array<string, mixed>  $theme
     * @param  array<string, string>  $pageUrls  this site's own pages: slug => absolute URL ('' = home), when known
     * @return array{privacy: ?string, terms: ?string}
     */
    public static function fromTheme(?array $theme, array $pageUrls = []): array
    {
        return [
            'privacy' => self::ownPage(self::clean($theme['privacy_notice_url'] ?? null), $pageUrls),
            'terms' => self::ownPage(self::clean($theme['terms_url'] ?? null), $pageUrls),
        ];
    }

    /**
     * An address the owner pasted that points at THIS site under an older spelling ("/Terms-Conditions" for the page
     * "/terms-and-conditions") is linked at the page's real address, so the footer never depends on a redirect and
     * never links to a missing page. Anything else (another host, an external policy, an unknown path) is untouched.
     *
     * @param  array<string, string>  $pageUrls
     */
    private static function ownPage(?string $url, array $pageUrls): ?string
    {
        if ($url === null || $pageUrls === []) {
            return $url;
        }

        $parts = parse_url($url);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $key = \App\Library\Website\Seo\WebsiteRedirectMap::slugKey($path);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($path === '' || $key === '' || str_contains($path, '/')) {
            return $url;
        }

        $sameHost = collect($pageUrls)->filter(fn ($pageUrl) => strtolower((string) parse_url((string) $pageUrl, PHP_URL_HOST)) === $host);

        if ($sameHost->isEmpty() || $sameHost->has($path)) {
            return $url;
        }

        $matches = $sameHost->filter(fn ($pageUrl, $slug) => (string) $slug !== '' && \App\Library\Website\Seo\WebsiteRedirectMap::slugKey((string) $slug) === $key);

        return $matches->count() === 1 ? (string) $matches->first() : $url;
    }
}
