<?php

namespace App\Library\Navigation;

use App\Models\User;

/**
 * Where the top bar's account menu "Settings" entry leads, for the person
 * signed in, or null when they have no Settings page to open.
 *
 * Never a new authorization decision. Each portal reuses the exact entry its
 * own sidebar already resolved for this actor, so the account menu can never
 * offer a destination the sidebar would hide:
 *
 *  - customer portal: the "settings" entry CustomerMenuBuilder built for the
 *    current frame (the Business's Settings hub, the account's own Settings,
 *    or Agency settings), falling back to the Agency shell's "Business
 *    settings" entry. Staff without access get no entry, so no link.
 *  - admin portal: the first child of the "Settings" group AdminMenuBuilder
 *    left visible after its permission pruning (Platform Settings for the
 *    Platform Owner; whatever settings page a staff role may open).
 *
 * Every route still re-checks authority server-side whether or not its link
 * is shown.
 */
final class AccountMenuSettingsLink
{
    /** CustomerMenuBuilder keys, most specific to the frame first. */
    private const CUSTOMER_KEYS = ['settings', 'business-settings'];

    public function __construct(private readonly AdminMenuBuilder $adminMenu)
    {
    }

    /**
     * @param  array<int, MenuItem>  $customerMenu  the composed `$customerMenu`
     */
    public function urlFor(?User $user, array $customerMenu, string $path): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        if (CustomerShellComposer::isCustomerPortal($user)) {
            return $this->customerUrl($customerMenu);
        }

        if ($user->active_portal === 'admin') {
            return $this->adminUrl($user, $path);
        }

        return null;
    }

    /**
     * @param  array<int, MenuItem>  $customerMenu
     */
    private function customerUrl(array $customerMenu): ?string
    {
        foreach (self::CUSTOMER_KEYS as $key) {
            foreach ($customerMenu as $item) {
                if ($item instanceof MenuItem && $item->key === $key && ! $item->header && ! $item->isPost() && $item->url !== null) {
                    return $item->url;
                }
            }
        }

        return null;
    }

    private function adminUrl(User $user, string $path): ?string
    {
        foreach ($this->adminMenu->build($user, $path, false) as $entry) {
            if (($entry->name ?? null) !== 'Settings' || empty($entry->submenu)) {
                continue;
            }

            foreach ($entry->submenu as $child) {
                if (! empty($child->url)) {
                    return (string) $child->url;
                }
            }
        }

        return null;
    }
}
