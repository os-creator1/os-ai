<?php

namespace App\Library\Navigation;

use App\Helpers\Helper;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Platform Owner shell V1 — turns the static admin menu
 * (Helper::menuData()['admin']) into the tree one signed-in backend user may
 * actually see, so the sidebar and horizontal menu render the result instead
 * of each re-deriving visibility.
 *
 * Rules, all additive over the existing data:
 *  - `access`      pipe-separated abilities; visible when the user holds any
 *                  (same check the old `@canany` made).
 *  - `admin_only`  additionally requires `users.is_admin` (the Platform Owner
 *                  marker); a permission string alone never grants it.
 *  - `staff_only`  hidden from Platform Owners (the Home entry replaces it).
 *  - `requires_config`  hidden unless that config flag is truthy (a surface
 *                  that answers 404 while its module is off is not linked).
 *  - a parent whose children are all hidden is hidden;
 *  - a `navheader` with no visible entry before the next header is hidden;
 *  - exactly one entry is marked active: the one whose slug is the longest
 *    segment-aware prefix of the request path, so `platform-owner` (Home) is
 *    not lit up on `platform-owner/audit`, and `dashboard` is not lit up on
 *    `reports/dashboard`.
 *
 * Visibility here is presentation only: every route re-checks authority
 * server-side whether or not its link is shown.
 */
class AdminMenuBuilder
{
    public function __construct(private readonly Gate $gate)
    {
    }

    /**
     * @return array<int, object>  stdClass entries, same shape the Blade
     *                             panels already consume, plus `active`.
     */
    public function build(?User $user, string $path, bool $withHeaders = true): array
    {
        if ($user === null) {
            return [];
        }

        $tree = json_decode(json_encode(Helper::menuData()['admin']));
        $visible = $this->prune($tree, $user);
        $visible = $this->dropEmptyHeaders($visible);

        $this->markActive($visible, trim($path, '/'));

        if (! $withHeaders) {
            $visible = array_values(array_filter($visible, fn (object $entry) => ! isset($entry->navheader)));
        }

        return $visible;
    }

    /**
     * @param array<int, object> $entries
     * @return array<int, object>
     */
    private function prune(array $entries, User $user): array
    {
        $out = [];

        foreach ($entries as $entry) {
            if (! $this->passesAccountBoundary($entry, $user)) {
                continue;
            }

            if (isset($entry->navheader)) {
                $out[] = $entry;

                continue;
            }

            if (! $this->passesAccess($entry, $user)) {
                continue;
            }

            if (isset($entry->submenu)) {
                $entry->submenu = $this->prune($entry->submenu, $user);

                if ($entry->submenu === []) {
                    continue;
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    private function passesAccountBoundary(object $entry, User $user): bool
    {
        $isOwner = (bool) ($user->is_admin ?? false);

        if (! empty($entry->admin_only) && ! $isOwner) {
            return false;
        }

        if (! empty($entry->staff_only) && $isOwner) {
            return false;
        }

        if (! empty($entry->requires_config) && ! config($entry->requires_config)) {
            return false;
        }

        return true;
    }

    private function passesAccess(object $entry, User $user): bool
    {
        $abilities = array_filter(explode('|', (string) ($entry->access ?? '')));

        return $abilities === [] || $this->gate->forUser($user)->any($abilities, $user);
    }

    /**
     * @param array<int, object> $entries
     * @return array<int, object>
     */
    private function dropEmptyHeaders(array $entries): array
    {
        $out = [];
        $pendingHeader = null;

        foreach ($entries as $entry) {
            if (isset($entry->navheader)) {
                $pendingHeader = $entry;

                continue;
            }

            if ($pendingHeader !== null) {
                $out[] = $pendingHeader;
                $pendingHeader = null;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param array<int, object> $entries
     */
    private function markActive(array $entries, string $path): void
    {
        $best = null;
        $bestLength = 0;
        $leaves = [];

        $collect = function (array $list) use (&$collect, &$leaves): void {
            foreach ($list as $entry) {
                if (isset($entry->navheader)) {
                    continue;
                }

                $entry->active = false;
                $leaves[] = $entry;

                if (isset($entry->submenu)) {
                    $collect($entry->submenu);
                }
            }
        };
        $collect($entries);

        foreach ($leaves as $entry) {
            $slug = trim((string) ($entry->slug ?? ''), '/');

            if ($slug === '') {
                continue;
            }

            if ($path === $slug || str_starts_with($path, $slug . '/')) {
                if (strlen($slug) > $bestLength) {
                    $best = $entry;
                    $bestLength = strlen($slug);
                }
            }
        }

        if ($best !== null) {
            $best->active = true;
        }
    }
}
