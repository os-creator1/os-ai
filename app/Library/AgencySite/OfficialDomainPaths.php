<?php

namespace App\Library\AgencySite;

use App\Models\AgencySite;
use App\Models\Workspace;

/**
 * The ONLY authority on who may publish under the official MotionGrove root domain.
 *
 * Exactly two Agency workspaces own an official path, identified by their immutable workspace UID (never a name, an
 * e-mail or a site id). The map lives in config (marketing.landing.official_paths: path => workspace uid), not in
 * anything an Agency or a request can write. No other root-level path exists for an Agency: the Landing Page editor
 * has no setting that names one (SiteSchema refuses unknown keys), and the public routes consult ONLY this map, so a
 * crafted request, editor parameter or API call cannot claim, move or add a path.
 *
 * Every Agency keeps its ordinary hosted address (/w/{publicId}); this class never touches it.
 */
final class OfficialDomainPaths
{
    /** @return array<string, string> path => workspace uid */
    public static function map(): array
    {
        return array_filter((array) config('marketing.landing.official_paths', []), fn ($uid) => is_string($uid) && $uid !== '');
    }

    /** Is this one of the official paths at all? */
    public static function isOfficialPath(string $path): bool
    {
        return array_key_exists($path, self::map());
    }

    /** May the workspace with this uid publish at this official path? Exact, case-insensitive uid match only. */
    public static function permits(string $path, ?string $workspaceUid): bool
    {
        $owner = self::map()[$path] ?? null;

        return $owner !== null && $workspaceUid !== null && hash_equals(strtolower($owner), strtolower($workspaceUid));
    }

    /** The official path assigned to this workspace, if any. */
    public static function pathFor(Workspace $agency): ?string
    {
        foreach (self::map() as $path => $uid) {
            if (self::permits($path, (string) $agency->uid)) {
                return $path;
            }
        }

        return null;
    }

    /** The public URL assigned to this workspace, if any. */
    public static function urlFor(Workspace $agency): ?string
    {
        $path = self::pathFor($agency);

        return $path === null ? null : url($path);
    }

    /** The Agency site that owns an official path (resolved from the immutable workspace uid), if one exists. */
    public static function siteFor(string $path): ?AgencySite
    {
        $uid = self::map()[$path] ?? null;
        $agency = $uid === null ? null : Workspace::query()->where('uid', $uid)->first();

        return $agency === null ? null : AgencySite::query()->where('agency_workspace_id', $agency->id)->first();
    }
}
