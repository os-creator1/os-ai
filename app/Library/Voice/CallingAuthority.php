<?php

namespace App\Library\Voice;

use App\Library\Workspace\BusinessRouteAccess;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may place or take browser calls for a Business. The Workspace owner (an Agency owner for the Agency's own Business, a
 * Business owner for theirs) holds the authority implicitly, so they never have to grant themselves a permission; every
 * other member still needs the explicit `place_voice_calls` permission. Ownership never overrides tenancy (callers resolve
 * the Business through the normal tenancy chain first) and is not honoured during a View-As session, where only the
 * explicit permission applies, exactly as for the other owner-aware management authorities.
 */
final class CallingAuthority
{
    public const PERMISSION = 'place_voice_calls';

    public function __construct(private readonly BusinessRouteAccess $routes)
    {
    }

    public function mayCall(?User $user, Business $business): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->routes->viewedBusinessIdFor((int) $user->id) === null) {
            $workspace = $business->workspace;
            if ($workspace !== null && $workspace->is_active && (int) $workspace->owner_user_id === (int) $user->id) {
                return true;
            }
        }

        return Gate::forUser($user)->allows(self::PERMISSION);
    }
}
