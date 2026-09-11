<?php

namespace App\Library\Dashboard;

use App\Library\Navigation\CustomerContext;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Customer Experience Slice 4, Correction 1 decision D — the legacy
 * "Login as Parent" control, preserved exactly where the old dashboard showed
 * it: to a genuine team-member (sub-account) actor, whose own user row carries
 * a parent. The route and controller are unchanged (user.account.login_as).
 *
 * Never while an administrator is impersonating the customer (the legacy
 * condition), and never during View-as-client: switching identity is a
 * prohibited action while viewing (ViewAsProhibitedActions lists
 * user.account.login_as), so it is absent, not disabled.
 */
final class ParentAccountSwitch
{
    /**
     * @return array{label: string, url: string, message: string}|null
     */
    public function for(User $user, CustomerContext $context): ?array
    {
        if ($user->parent_id === null || $context->isViewingAsClient()) {
            return null;
        }

        if (session()->has('admin_user_id') && session()->has('temp_user_id')) {
            return null;
        }

        if (! Route::has('user.account.login_as')) {
            return null;
        }

        $parent = $user->parent;

        if (! $parent instanceof User) {
            return null;
        }

        return [
            'label' => __('locale.sub_accounts.login_as_parent') . ': ' . $parent->displayName(),
            'url' => route('user.account.login_as', $parent->uid),
            'message' => __('locale.sub_accounts.login_as_parent_message'),
        ];
    }
}
