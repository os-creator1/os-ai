<?php

namespace Tests\Feature\Calendar\Concerns;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;

/**
 * Implementation Contract 15 §6 — the cast of actors the availability
 * authority table distinguishes, plus the Location topology its boundary
 * cases need.
 *
 * Every membership here is created through the real repository seam the
 * production guard reads, so nothing in these fixtures can accidentally
 * prove a rule that LocationAccessGuard would not agree with.
 */
trait CreatesCalendarAuthorityFixtures
{
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    protected Customer $owner;
    protected Workspace $workspace;
    protected Business $business;
    protected BusinessLocation $locationA;
    protected BusinessLocation $locationB;
    protected User $admin;
    protected User $staff;
    protected User $outsider;

    protected function bootCalendarAuthorityFixtures(): void
    {
        // Owner of BOTH the Workspace and the Business — §6 names
        // Workspace.owner_user_id and Business.customer_id as the two owner
        // branches, and this fixture satisfies both at once.
        $this->owner = $this->createCustomer();
        $this->workspace = $this->createWorkspace($this->owner->user);
        $this->business = $this->createBusinessForCustomer($this->owner->user_id, $this->workspace->id);

        $this->locationA = $this->makeLocation($this->business, ['name' => 'Location A']);
        $this->locationB = $this->makeLocation($this->business, ['name' => 'Location B']);

        $this->admin = $this->memberWithFullReach(WorkspaceMembershipRole::Admin);
        $this->staff = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);

        // No membership anywhere: the "ungranted" actor.
        $this->outsider = $this->createCustomer()->user;
    }

    protected function makeLocation(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    /**
     * An active member who can reach every Business and every Location in
     * the Workspace.
     */
    protected function memberWithFullReach(WorkspaceMembershipRole $role): User
    {
        $user = $this->createCustomer()->user;

        $this->createMembership($this->workspace, $user, [
            'role' => $role,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        return $user;
    }

    /**
     * An active member whose Location reach is `Selected` and who holds an
     * explicit grant for ONE Location only — the boundary case that proves
     * a sibling Location is genuinely out of reach rather than incidentally
     * unreachable.
     */
    protected function memberGrantedOnly(BusinessLocation $location, WorkspaceMembershipRole $role = WorkspaceMembershipRole::Staff): User
    {
        $user = $this->createCustomer()->user;

        $membership = $this->createMembership($this->workspace, $user, [
            'role' => $role,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        return $user;
    }

    /**
     * A completely separate Workspace + Business + Location, for the
     * cross-Business boundary: its uid must never resolve from this
     * Business's routes or services.
     *
     * @return array{0: Business, 1: BusinessLocation, 2: Customer}
     */
    protected function foreignBusinessWithLocation(): array
    {
        $foreignOwner = $this->createCustomer();
        $foreignWorkspace = $this->createWorkspace($foreignOwner->user);
        $foreignBusiness = $this->createBusinessForCustomer($foreignOwner->user_id, $foreignWorkspace->id);
        $foreignLocation = $this->makeLocation($foreignBusiness, ['name' => 'Foreign Location']);

        return [$foreignBusiness, $foreignLocation, $foreignOwner];
    }
}
