<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\Seo\WebsitePageIntent;

/**
 * Which pages of a rebuilt website are the SAME pages as ones in its live (published) revision — so a rebuilt page can keep the
 * uid that published articles reference (`supports_page_uid`, `[text](page:<uid>)`).
 *
 * A uid is carried over only when the match is unique and reliable on BOTH sides, by what the page IS, never by position or title:
 *
 *   home          the homepage;
 *   service/event the stored service uid (the booth or event type it is about);
 *   location      the full city AND region ("Portland, OR" is not "Portland, ME"); a page whose old place has no region matches only
 *                 when that city is the only one on both sides;
 *   one-of-a-kind packages, services overview, about, faq, contact, gallery, backdrops: the role, when each side has exactly one;
 *   legacy        a page with no stored identity matches only by its exact address, and only when the roles do not conflict.
 *
 * A published page with no counterpart is simply gone (its uid is never handed to a different page); a new page keeps a fresh uid.
 * Pure: no queries, so the caller decides which snapshot is "published" (always this website's own).
 */
final class PublishedPageIdentity
{
    private const SINGLETON_ROLES = [
        WebsitePageIntent::SERVICES_HUB, WebsitePageIntent::PACKAGES, WebsitePageIntent::ABOUT, WebsitePageIntent::FAQ,
        WebsitePageIntent::CONTACT, WebsitePageIntent::GALLERY, WebsitePageIntent::BACKDROPS, WebsitePageIntent::AREA_HUB,
    ];

    /**
     * @param  array<int, array{is_home: bool, slug: ?string, role: ?string, intent: ?array}>  $newPages  in generation order
     * @param  array<int, array<string, mixed>>  $publishedPages  a revision snapshot's `pages` (uid, slug, is_home, role, intent)
     * @return array<int, ?string>  for each new page (same index), the published uid it keeps, or null for a new identity
     */
    public static function match(array $newPages, array $publishedPages): array
    {
        $assigned = array_fill(0, count($newPages), null);
        $used = [];
        $old = array_values(array_filter($publishedPages, fn ($page) => is_array($page) && is_string($page['uid'] ?? null) && $page['uid'] !== ''));

        $newKeys = array_map(fn (array $page) => self::key($page), $newPages);
        $oldKeys = array_map(fn (array $page) => self::key($page), $old);

        // 1. Exact identity keys, each unique on both sides.
        foreach ($newKeys as $i => $key) {
            if ($key === null) {
                continue;
            }

            $sameNew = array_keys($newKeys, $key, true);
            $sameOld = array_keys($oldKeys, $key, true);

            if (count($sameNew) === 1 && count($sameOld) === 1 && ! isset($used[$sameOld[0]])) {
                $assigned[$i] = $old[$sameOld[0]]['uid'];
                $used[$sameOld[0]] = true;
            }
        }

        // 2. A place whose old identity had no region: the same city, when it is the only one of that name on both sides.
        foreach ($newPages as $i => $page) {
            if ($assigned[$i] !== null || ! str_starts_with((string) $newKeys[$i], 'loc:')) {
                continue;
            }

            $city = self::cityOf($newKeys[$i]);
            $sameNew = array_filter($newKeys, fn ($key) => str_starts_with((string) $key, 'loc:') && self::cityOf($key) === $city);
            $sameOld = array_filter($oldKeys, fn ($key, $j) => ! isset($used[$j]) && str_starts_with((string) $key, 'loc:') && self::cityOf($key) === $city, ARRAY_FILTER_USE_BOTH);
            $oldKey = $sameOld === [] ? null : array_values($sameOld)[0];

            if (count($sameNew) === 1 && count($sameOld) === 1 && $oldKey !== null && (str_ends_with($oldKey, '|') || str_ends_with($newKeys[$i], '|'))) {
                $j = array_key_first($sameOld);
                $assigned[$i] = $old[$j]['uid'];
                $used[$j] = true;
            }
        }

        // 3. Legacy pages with no usable identity: the exact address, when nothing conflicts.
        foreach ($newPages as $i => $page) {
            $slug = (string) ($page['slug'] ?? '');

            if ($assigned[$i] !== null || $slug === '' || ! empty($page['is_home'])) {
                continue;
            }

            $candidates = array_keys(array_filter($old, fn ($published, $j) => ! isset($used[$j]) && $oldKeys[$j] === null && empty($published['is_home']) && (string) ($published['slug'] ?? '') === $slug, ARRAY_FILTER_USE_BOTH));
            $sameSlugNew = array_filter($newPages, fn ($other) => (string) ($other['slug'] ?? '') === $slug);

            if (count($candidates) !== 1 || count($sameSlugNew) !== 1) {
                continue;
            }

            $oldRole = WebsitePageIntent::roleOf($old[$candidates[0]]);

            $newRole = $page['role'] ?? null;

            // The roles must agree. An old page whose role is unknown may take over only a page that is not identified by a
            // service or a place: those need that identity (matched above), never just a shared address.
            if ($oldRole !== null && $newRole !== null && $oldRole !== $newRole) {
                continue;
            }

            if ($oldRole === null && in_array($newRole, [WebsitePageIntent::SERVICE, WebsitePageIntent::EVENT, WebsitePageIntent::LOCATION], true)) {
                continue;
            }

            $assigned[$i] = $old[$candidates[0]]['uid'];
            $used[$candidates[0]] = true;
        }

        return $assigned;
    }

    /** The page's identity key, or null when it has none that can be trusted (it then falls back to its exact address). */
    private static function key(array $page): ?string
    {
        if (! empty($page['is_home'])) {
            return 'home';
        }

        $role = WebsitePageIntent::validRole($page['role'] ?? null) ?? WebsitePageIntent::legacyRole(false, (string) ($page['slug'] ?? ''));
        $intent = is_array($page['intent'] ?? null) ? $page['intent'] : [];

        if ($role === WebsitePageIntent::SERVICE || $role === WebsitePageIntent::EVENT) {
            return ! empty($intent['service_uid']) ? 'svc:' . $role . ':' . $intent['service_uid'] : null;
        }

        if ($role === WebsitePageIntent::LOCATION) {
            if (empty($intent['place'])) {
                return null;
            }

            [$city, $region] = GuidedGenerationCommitService::placeParts((string) $intent['place']);

            return $city === '' ? null : 'loc:' . $city . '|' . ($region ?? '');
        }

        // A stored role says what the page is; a role inferred from an address alone is not trusted as a unique identity.
        if (in_array($role, self::SINGLETON_ROLES, true) && WebsitePageIntent::validRole($page['role'] ?? null) !== null) {
            return 'role:' . $role;
        }

        return null;
    }

    private static function cityOf(?string $key): string
    {
        return explode('|', substr((string) $key, 4))[0];
    }
}
