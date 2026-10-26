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

        if (array_key_exists($path, $redirects)) {
            return (string) $redirects[$path];
        }

        // Addresses are case-insensitive for visitors (an old "/Check-availability" link must reach "/check-availability").
        $folded = strtolower($path);
        foreach ($redirects as $old => $target) {
            if (strtolower((string) $old) === $folded) {
                return (string) $target;
            }
        }

        if ($folded !== $path) {
            foreach ((array) ($snapshot['pages'] ?? []) as $page) {
                if (empty($page['is_home']) && (string) ($page['slug'] ?? '') === $folded) {
                    return $folded;
                }
            }
        }

        return self::legacySpellingTarget($snapshot, $path);
    }

    /**
     * The comparison key two spellings of the same address share: case, hyphens/punctuation and the joining word "and"
     * are ignored. Sites moved from builders that wrote slugs as one run of words ("weddingphotoboothrental",
     * "Terms-Conditions") are found at their hyphenated equivalents ("wedding-photo-booth-rental",
     * "terms-and-conditions") without anyone having to key in a redirect.
     */
    public static function slugKey(string $slug): string
    {
        $slug = mb_strtolower(trim($slug, '/'));
        $slug = preg_replace('/(?<![a-z0-9])and(?![a-z0-9])/', '', $slug) ?? $slug;

        return preg_replace('/[^a-z0-9]+/', '', $slug) ?? '';
    }

    /**
     * A previously published address that only differs by spelling from EXACTLY ONE live page. Never a guess: an
     * ambiguous key (two pages share it), an empty key, a live page's own address or the homepage never redirect.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private static function legacySpellingTarget(array $snapshot, string $path): ?string
    {
        $key = self::slugKey($path);

        if ($key === '') {
            return null;
        }

        $matches = [];

        foreach ((array) ($snapshot['pages'] ?? []) as $page) {
            $slug = (string) ($page['slug'] ?? '');

            if (empty($page['is_home']) && $slug !== '') {
                if (strtolower($slug) === strtolower(trim($path, '/'))) {
                    return null;
                }

                if (self::slugKey($slug) === $key) {
                    $matches[$slug] = true;
                }
            }
        }

        return count($matches) === 1 ? (string) array_key_first($matches) : null;
    }

    /**
     * Folds the owner's custom redirects into the computed map. An entry survives only if its target is a live page of
     * THIS revision and its old address is not itself a live page — so a redirect can never hide a page, loop, or point
     * off-site. Owner entries win over automatic ones.
     *
     * @param  array<string, string>  $computed
     * @param  array<string, string>|null  $custom
     * @return array<string, string>
     */
    public function withCustom(array $computed, ?array $custom, array $pages): array
    {
        $live = [];
        foreach ($pages as $page) {
            $live[strtolower(self::pathOf($page))] = true;
        }

        foreach ((array) $custom as $old => $target) {
            $old = strtolower(trim((string) $old, '/'));
            $target = strtolower(trim((string) $target, '/'));

            if ($old === '' || isset($live[$old]) || ! isset($live[$target]) || $old === $target) {
                continue;
            }
            $computed[$old] = $target;
        }

        return array_slice($computed, 0, self::MAX_ENTRIES + 50, true);
    }

    /** @param  array<string, mixed>  $page */
    private static function pathOf(array $page): string
    {
        return ! empty($page['is_home']) ? '' : (string) ($page['slug'] ?? '');
    }
}
