<?php

namespace Tests\Feature\Workspace\Concerns;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

/**
 * Requires the consuming test class to also use
 * Tests\Feature\Business\Concerns\CreatesBusinessTestData, since
 * createBusinessForCustomer() relies on its businessAttributes() helper.
 */
trait CreatesWorkspaceTestData
{
    protected function createWorkspace(User $owner, array $overrides = []): Workspace
    {
        return Workspace::create(array_merge([
            'name' => 'Test Workspace',
            'owner_user_id' => $owner->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function createMembership(Workspace $workspace, User $user, array $overrides = []): WorkspaceMembership
    {
        return WorkspaceMembership::create(array_merge([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ], $overrides));
    }

    protected function createBusinessForCustomer(int $customerId, ?int $workspaceId = null): Business
    {
        $business = Business::create(array_merge(
            $this->businessAttributes(),
            ['customer_id' => $customerId]
        ));

        if ($workspaceId !== null) {
            $business->workspace_id = $workspaceId;
            $business->save();
        }

        return $business;
    }
}
