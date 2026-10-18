<?php

namespace App\Library\Website\Seo;

/**
 * SEO V1 final — a published snapshot freezes each photo as an absolute URL on whichever host
 * published it (a staging host, a console run and a queue worker all differ). On a custom domain
 * every such photo URL is rewritten, per request, to the customer's own origin: pages load their
 * images same-origin (no cross-origin hot-linking from the platform host), og:image and the
 * structured-data logo name the customer's domain, and a platform host change never breaks an
 * already-published revision. Only URLs under /images/websites/ — the Website's own stored,
 * Business-owned photos — are touched; any other address is left exactly as it was.
 */
final class WebsiteAssetUrls
{
    private const OWN_PHOTO = '#^https?://[^/\s]+(/images/websites/[^\s]*)$#i';

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function rebase(array $snapshot, string $origin): array
    {
        return self::walk($snapshot, rtrim($origin, '/'));
    }

    private static function walk(array $node, string $origin): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = self::walk($value, $origin);
            } elseif (is_string($value) && preg_match(self::OWN_PHOTO, $value, $match) === 1) {
                $node[$key] = $origin.$match[1];
            }
        }

        return $node;
    }
}