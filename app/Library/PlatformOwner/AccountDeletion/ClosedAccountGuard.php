<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use App\Models\PlatformAccountClosure;

/**
 * The one rule for "may this account be switched back on": never, once it has
 * a platform_account_closures record (closing, closed, or deleted).
 *
 * Reactivation would undo the access block while provider cleanup may still be
 * pending, or revive an account whose billing was cancelled. The rule is
 * enforced at every write seam, so no single caller can bypass it:
 *  - the User model (any per-model save that turns `status` on);
 *  - every bulk `status = true` repository write;
 *  - the Platform Owner action and the legacy toggle, which call it first so
 *    the operator gets a clear message.
 * It is checked BEFORE anything is written and before any reactivation event
 * is queued, so a refused request changes nothing and fires nothing.
 */
final class ClosedAccountGuard
{
    /**
     * @param  array<int, string>  $uids
     * @return array<int, string> the uids that have a closure record
     */
    public static function closedAmong(array $uids): array
    {
        $uids = array_values(array_filter(array_unique($uids), fn ($u) => is_string($u) && $u !== ''));

        if ($uids === []) {
            return [];
        }

        return PlatformAccountClosure::query()->whereIn('user_uid', $uids)->pluck('user_uid')->all();
    }

    /**
     * All-or-nothing: if ANY uid is closed, the whole request is refused.
     *
     * @param  array<int, string>  $uids
     *
     * @throws AccountClosureRefused
     */
    public static function assertMayActivate(array $uids): void
    {
        if (self::closedAmong($uids) !== []) {
            throw new AccountClosureRefused(__('A closed or closing account cannot be reactivated. Nothing was changed.'));
        }
    }
}
