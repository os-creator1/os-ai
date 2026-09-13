<?php

namespace Tests\Feature\QueryBudget;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use App\Repositories\Contracts\WorkspaceRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared customer request query-budget optimization (Automations V2 §18,
 * the V2-E blocker on PR #280).
 *
 * The optimization is repository-level memoization of facts (Business,
 * Workspace, plan assignment/catalog, overrides, toggles) that a
 * Business-scoped request previously re-read several times over —
 * userCanAccessBusiness()'s own check, EntitlementManager::decide()'s
 * check, and the menu/shell's entitlement snapshot each re-derived the
 * same rows independently. Caching a fact that could change mid-request
 * is exactly the kind of thing that can silently weaken authorization, so
 * this file's job is proving the one property that actually matters: a
 * WRITE within a request is never masked by a read cached before it.
 * Every scenario below performs a real write through the same repository
 * whose read it just cached, then re-reads and asserts the fresh value —
 * never the stale one.
 */
class SharedRequestCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * @return array{workspace: Workspace, business: Business}
     */
    private function createWorkspaceWithBusiness(bool $assign = true): array
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Test Business', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);

        if ($assign) {
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture assignment.', true, 0);
        }

        return ['workspace' => $workspace->fresh(), 'business' => $business->fresh()];
    }

    // =================================================================
    // The memoization actually fires (precondition for every test below:
    // if it never cached anything, the invalidation proofs would be
    // vacuous).
    // =================================================================

    public function test_business_find_by_id_is_read_once_per_request_for_repeated_calls(): void
    {
        ['business' => $business] = $this->createWorkspaceWithBusiness();
        $repo = app(BusinessRepository::class);

        $repo->findById($business->id);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $repo->findById($business->id);
        $repo->findById($business->id);

        $this->assertSame(0, $queries, 'A second and third findById() for the same id in the same request must be cache hits.');
    }

    public function test_workspace_plan_assignment_find_by_workspace_id_is_read_once_per_request(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness();
        $repo = app(WorkspacePlanAssignmentRepository::class);

        $repo->findByWorkspaceId($workspace->id);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $repo->findByWorkspaceId($workspace->id);

        $this->assertSame(0, $queries);
    }

    // =================================================================
    // The security boundary: a write in the same request is never
    // masked by an earlier cached read.
    // =================================================================

    public function test_a_business_status_change_is_visible_to_the_very_next_read_in_the_same_request(): void
    {
        ['business' => $business] = $this->createWorkspaceWithBusiness();
        $repo = app(BusinessRepository::class);

        $before = $repo->findById($business->id);
        $this->assertNotNull($before);
        $originalStatus = $before->status;

        $repo->updateStatus($business, $originalStatus === \App\Enums\Business\BusinessStatus::Active
            ? \App\Enums\Business\BusinessStatus::Draft
            : \App\Enums\Business\BusinessStatus::Active);

        $after = $repo->findById($business->id);
        $this->assertNotSame($originalStatus, $after->status, 'A same-request write must never be shadowed by a cached pre-write read.');
    }

    public function test_a_workspace_deactivation_is_visible_to_the_very_next_read_in_the_same_request(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness();
        $repo = app(WorkspaceRepository::class);

        $before = $repo->findById($workspace->id);
        $this->assertTrue($before->is_active);

        $repo->setActive($workspace, false);

        $afterById = $repo->findById($workspace->id);
        $afterByUid = $repo->findByUid($workspace->uid);

        $this->assertFalse($afterById->is_active, 'findById() must reflect the deactivation immediately, not a cached active row.');
        $this->assertFalse($afterByUid->is_active, 'findByUid() must reflect it too — this is precisely the security-relevant read userCanAccessBusiness() ultimately depends on.');
    }

    /**
     * The concrete authorization consequence of the scenario above:
     * WorkspaceManager::userCanAccessBusiness() must deny the moment the
     * Workspace it just read as active is deactivated in the SAME
     * request — this is the exact fact §14.1's tenancy chain (Workspace
     * active -> Business access) depends on, and the one this whole
     * optimization could most plausibly have broken.
     */
    public function test_deactivating_a_workspace_mid_request_immediately_denies_business_access(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $owner = User::find($workspace->owner_user_id);
        $manager = app(\App\Library\Workspace\WorkspaceManager::class);

        $this->assertTrue($manager->userCanAccessBusiness((int) $owner->id, $business), 'Precondition: access is allowed while the Workspace is active.');

        app(WorkspaceRepository::class)->setActive($workspace, false);

        $this->assertFalse(
            $manager->userCanAccessBusiness((int) $owner->id, $business),
            'An inactive Workspace must deny access on the very next check in the same request — never a cached "was active" answer.',
        );
    }

    public function test_assigning_a_plan_mid_request_is_visible_to_the_next_entitlement_decision(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness(assign: false);
        $manager = app(EntitlementManager::class);

        $unassigned = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());
        $this->assertFalse($unassigned->allowed);
        $this->assertSame('workspace_plan_unassigned', $unassigned->reason);

        $manager->assignFirstPlan($workspace->fresh(), WorkspacePlanTier::Core, $this->createAdmin(), 'Mid-request assignment.', true, 0);

        $afterAssignment = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());
        $this->assertTrue($afterAssignment->allowed, 'A plan assigned mid-request must be visible to the very next decide() call — never the earlier cached "unassigned" null.');
    }

    public function test_suspending_a_plan_mid_request_immediately_denies_the_next_decision(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $manager = app(EntitlementManager::class);

        $before = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());
        $this->assertTrue($before->allowed);

        $manager->changePlanStatus($workspace->fresh(), WorkspacePlanAssignmentStatus::Suspended, $this->createAdmin(), 'Mid-request suspension.');

        $after = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $this->createAdmin());
        $this->assertFalse($after->allowed, 'A plan suspended mid-request must deny the very next decision.');
        $this->assertSame('plan_suspended', $after->reason);
    }

    public function test_a_workspace_override_created_mid_request_is_visible_to_the_next_decision(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $manager = app(EntitlementManager::class);
        $admin = $this->createAdmin();

        $before = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $admin);
        $this->assertTrue($before->allowed, 'Precondition: Crm is plan-entitled and not overridden.');

        $manager->createOrChangeOverride($workspace->fresh(), PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $admin, 'Mid-request deny override.');

        $after = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $admin);
        $this->assertFalse($after->allowed, 'A deny override created mid-request must never be shadowed by a cached "no override" answer.');
        $this->assertSame('denied_by_workspace_override', $after->reason);

        $summary = $manager->getWorkspaceEntitlementSummary($workspace);
        $this->assertSame(WorkspaceEntitlementOverrideState::Deny, $summary->overrides[PlatformFeature::Crm->value] ?? null, 'getWorkspaceEntitlementSummary() (also cached) must see the same fresh override.');
    }

    public function test_a_business_feature_toggle_disabled_mid_request_is_visible_to_the_next_decision(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $manager = app(EntitlementManager::class);
        $admin = $this->createAdmin();

        $before = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $admin);
        $this->assertTrue($before->allowed);

        // disableBusinessFeature() requires the Workspace's own owner (or
        // an active admin member) as the acting authority — a platform
        // admin unrelated to this Workspace is correctly refused.
        $manager->disableBusinessFeature($business->fresh(), PlatformFeature::Crm, (int) $workspace->owner_user_id, 'Mid-request disable.');

        $after = $manager->decide($workspace, $business, PlatformFeature::Crm->value, $admin);
        $this->assertFalse($after->allowed, 'A toggle disabled mid-request must never be shadowed by a cached "no toggle" answer.');
        $this->assertSame('disabled_for_business', $after->reason);
    }

    // =================================================================
    // Cross-request isolation: two different requests, two different
    // Workspaces sharing no state whatsoever.
    // =================================================================

    public function test_two_different_workspaces_never_share_a_cached_answer(): void
    {
        ['workspace' => $workspaceA, 'business' => $businessA] = $this->createWorkspaceWithBusiness();
        ['workspace' => $workspaceB, 'business' => $businessB] = $this->createWorkspaceWithBusiness(assign: false);
        $manager = app(EntitlementManager::class);

        $a = $manager->decide($workspaceA, $businessA, PlatformFeature::Crm->value, $this->createAdmin());
        $b = $manager->decide($workspaceB, $businessB, PlatformFeature::Crm->value, $this->createAdmin());

        $this->assertTrue($a->allowed, 'Workspace A has an active plan.');
        $this->assertFalse($b->allowed, 'Workspace B has none — it must never resolve to A\'s cached "allowed".');
        $this->assertSame('workspace_plan_unassigned', $b->reason);
    }

    /**
     * The container persists across $this->get()/$this->post() calls in
     * one test method, so this is the real proof RequestScopedCache is
     * bound to the Illuminate Request, not to the container: rebinding
     * 'request' — exactly what happens between two real HTTP requests —
     * must reset every cache this optimization added.
     */
    public function test_a_fresh_request_never_reuses_the_previous_requests_cached_rows(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        app(BusinessRepository::class)->findById($business->id);
        app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId($workspace->id);

        $this->app->instance('request', Request::create('/next'));

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        app(BusinessRepository::class)->findById($business->id);
        app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceId($workspace->id);

        $this->assertSame(2, $queries, 'A fresh request must start with an empty cache — both reads must hit the database again.');
    }
}
