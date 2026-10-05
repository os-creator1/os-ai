<?php

namespace Tests\Support\WebsiteAcceptance;

use Closure;

/**
 * Website V1 full-site acceptance — a crawler over the application's own
 * HTTP kernel. It is given a `fetch` closure (url => status/body/headers),
 * so it works unchanged for the authenticated Preview, the platform path and
 * a custom domain. It follows only links the pages themselves expose and
 * resolves redirects explicitly (so a redirect loop is a finding, not a hang).
 */
final class SiteCrawler
{
    private const MAX_REDIRECTS = 5;

    /** @var array<string, array{status: int, body: string, headers: array<string, string>, location: ?string}> */
    private array $cache = [];

    /** @param  Closure(string): array{status: int, body: string, headers: array<string, string>, location: ?string}  $fetch */
    public function __construct(private readonly Closure $fetch) {}

    /** @return array{status: int, body: string, headers: array<string, string>, location: ?string} */
    public function get(string $url): array
    {
        return $this->cache[$url] ??= ($this->fetch)($url);
    }

    /**
     * Follow redirects.
     *
     * @return array{status: int, final: string, hops: int, loop: bool}
     */
    public function resolve(string $url): array
    {
        $seen = [];
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (isset($seen[$current])) {
                return ['status' => 310, 'final' => $current, 'hops' => $hop, 'loop' => true];
            }
            $seen[$current] = true;

            $response = $this->get($current);

            if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== null) {
                $current = self::absolute($response['location'], $current);

                continue;
            }

            return ['status' => $response['status'], 'final' => $current, 'hops' => $hop, 'loop' => false];
        }

        return ['status' => 310, 'final' => $current, 'hops' => self::MAX_REDIRECTS, 'loop' => true];
    }

    /**
     * Breadth-first crawl of every HTML page reachable by internal links.
     *
     * @param  array<int, string>  $seeds
     * @param  Closure(string): bool  $isPage  true for a URL the crawler should open as a page (internal, not an asset)
     * @return array<string, PageDoc>  keyed by URL as first requested
     */
    public function crawl(array $seeds, Closure $isPage, int $limit = 120): array
    {
        $queue = $seeds;
        $docs = [];

        while ($queue !== [] && count($docs) < $limit) {
            $url = array_shift($queue);

            if (isset($docs[$url])) {
                continue;
            }

            $response = $this->get($url);
            $docs[$url] = new PageDoc($url, $response['status'], $response['body'], $response['headers']);

            if ($response['status'] !== 200) {
                continue;
            }

            foreach ($docs[$url]->links() as $link) {
                $target = self::normalise($link['href'], $url);

                if ($target !== null && $isPage($target) && ! isset($docs[$target]) && ! in_array($target, $queue, true)) {
                    $queue[] = $target;
                }
            }
        }

        return $docs;
    }

    /** An absolute URL for an href found on $base, or null for anchors / mailto / tel / javascript. */
    public static function normalise(string $href, string $base): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || preg_match('#^(mailto:|tel:|javascript:|data:|sms:)#i', $href) === 1) {
            return null;
        }

        return self::stripFragment(self::absolute($href, $base));
    }

    public static function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($href, '//')) {
            return ($parts['scheme'] ?? 'http') . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $directory . '/' . $href;
    }

    private static function stripFragment(string $url): string
    {
        $position = strpos($url, '#');

        return $position === false ? $url : substr($url, 0, $position);
    }
}
