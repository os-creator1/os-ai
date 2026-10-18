<?php

namespace Tests\Feature\Concerns;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Customer;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;

/**
 * An active Staff member of an existing Workspace who is NOT its owner. The
 * Workspace owner now manages credential-class integrations by ownership
 * alone, so "an actor without the manage permission" has to be someone else.
 */
trait CreatesNonOwnerStaff
{
    /** @param  array<int, string>  $permissions  persisted on the staff member's customer row */
    protected function nonOwnerStaff(Workspace $workspace, array $permissions): Customer
    {
        $staff = $this->createCustomer();
        DB::table('customers')->where('id', $staff->id)->update(['permissions' => json_encode($permissions)]);

        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $staff->user_id,
            'role' => WorkspaceMembershipRole::Staff->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'location_access_scope' => LocationAccessScope::All->value,
            'is_active' => true,
        ]);

        return $staff->fresh();
    }

    /** The Workspace this Customer owns, or null when they own none. */
    protected function workspaceOwnedBy(Customer $owner): ?Workspace
    {
        return Workspace::query()->where('owner_user_id', $owner->user_id)->first();
    }
}
