<?php

namespace App\Library\Website\Seo;

/**
 * SEO V1 final — the redirect authority for a published Website.
 *
 * A page's address can change between two published revisions (the owner
 * edits its slug, or promotes it to the homepage). The old address may
 * already be indexed and linked to, so every publish records
 * "old path → new path" in the NEW snapshot's `redirects` map, matched by
 * the page's permanent uid; the public renderers answer an unknown path
 * that appears in the map with a 301 instead of a 404.
 *
 * Paths are slugs ('' = the homepage). The map is carried forward from
 * revision to revision (chains collapse to the final address, entries whose
 * target no longer exists or whose source is a live page again are dropped)
 * and is capped, so it can never grow without bound.
 */
final class WebsiteRedirectMap
{
    public const MAX_ENTRIES = 200;

    /**
     * @param  array<string, mixed>|null  $previous  the currently live snapshot, if any
     * @param  array<int, array<string, mixed>>  $pages  the new snapshot's pages
     * @return array<string, string> old path => new path
     */
    public function compute(?array $previous, array $pages): array
    {
        if ($previous === null) {
            return [];
        }

        $newPathByUid = [];
        $livePaths = [];

        foreach ($pages as $page) {
            $path = self::pathOf($page);
            $newPathByUid[$page['uid']] = $path;
            $livePaths[$path] = true;
        }

        $map = [];
        $renamed = [];

        foreach ((array) ($previous['pages'] ?? []) as $page) {
            $uid = $page['uid'] ?? null;

            if ($uid === null || ! isset($newPathByUid[$uid])) {
                continue;
            }

            $old = self::pathOf($page);
            $new = $newPathByUid[$uid];

            if ($old !== $new) {
                $renamed[$old] = $new;
            }
        }

        // Earlier redirects first (their targets may themselves have moved),
        // then this revision's own renames.
        foreach ((array) ($previous['redirects'] ?? []) as $old => $target) {
            $map[(string) $old] = (string) $target;
        }

        foreach ($renamed as $old => $new) {
            $map[$old] = $new;
        }

        $resolved = [];

        foreach ($map as $old => $target) {
            $seen = [$old => true];

            // Follow chains (about -> about-us -> about-our-team) to the final address.
            while (isset($renamed[$target]) && ! isset($seen[$target]) && ! isset($livePaths[$target])) {
                $seen[$target] = true;
                $target = $renamed[$target];
            }

            if ($old === '' || isset($livePaths[$old]) || ! isset($livePaths[$target]) || $target === $old) {
                continue;
            }

            $resolved[$old] = $target;
        }

        return array_slice($resolved, 0, self::MAX_ENTRIES, true);
    }

    /**
     * The redirect target (a path; '' = home) for an unknown request path, if
     * this snapshot recorded one.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function target(array $snapshot, string $path): ?string
    {
        $redirects = (array) ($snapshot['redirects'] ?? []);

        return array_key_exists($path, $redirects) ? (string) $redirects[$path] : null;
    }

    /** @param  array<string, mixed>  $page */
    private static function pathOf(array $page): string
    {
        return ! empty($page['is_home']) ? '' : (string) ($page['slug'] ?? '');
    }
}
