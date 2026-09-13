<?php

namespace Tests\Feature\Automations\Workflow\Http;

use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AutomationEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-E — the authorization matrix, for EVERY route.
 *
 * Each denial is proven against the whole route inventory rather than a sample,
 * because the failure mode this guards against is one endpoint added later that
 * skips a step of the chain — and a sample would not notice.
 *
 * Every assertion is on the REAL status code. That matters more than usual here:
 * this application's exception Handler answers any exception on a JSON request
 * with HTTP 200, so a route that let a denial escape to it would look, to a JSON
 * client, exactly like success. assertNotFound() on a JSON call is what proves a
 * route did not.
 */
class WorkflowHttpAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    /** @param array<string, mixed> $tenant */
    private function callEvery(
        array $tenant,
        string $workspaceUid,
        string $businessUid,
        ?string $workflowUid,
        ?string $enrollmentUid,
        int $expectedStatus,
        string $case,
        bool $onlyWorkflowRoutes = false,
    ): void {
        foreach ($this->workflowRoutes() as [$method, $name, $needsWorkflow, $needsEnrollment]) {
            if ($onlyWorkflowRoutes && ! $needsWorkflow) {
                continue;
            }

            $url = $this->routeUrl(
                $name,
                $workspaceUid,
                $businessUid,
                $needsWorkflow ? ($workflowUid ?? $tenant['workflow']->uid) : null,
                $needsEnrollment ? ($enrollmentUid ?? $tenant['enrollment']->uid) : null,
            );

            $response = $this->callJson($method, $url, $this->validBodyFor($name, $tenant['contact']));

            $this->assertSame(
                $expectedStatus,
                $response->status(),
                "{$case}: {$method} {$name} returned {$response->status()}, expected {$expectedStatus}. Body: " . $response->getContent(),
            );
        }
    }

    private function member(Workspace $workspace, WorkspaceMembershipRole $role, bool $active = true, WorkspaceBusinessAccessScope $scope = WorkspaceBusinessAccessScope::All): User
    {
        $user = $this->createCustomer()->user;

        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'business_access_scope' => $scope->value,
            'is_active' => $active,
        ]);

        return $user;
    }

    /** The router serves exactly the inventory the matrix covers — nothing escapes it. */
    public function test_the_route_inventory_matches_the_router(): void
    {
        $registered = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();
            $prefix = 'customer.workspaces.businesses.automations.workflows.';

            if (str_starts_with($name, $prefix)) {
                $registered[] = substr($name, strlen($prefix));
            }
        }

        $this->assertEqualsCanonicalizing(
            array_column($this->workflowRoutes(), 1),
            $registered,
            'Every V2-E route must be in the authorization matrix, and vice versa.',
        );
    }

    /**
     * B4 registers `automations/{automationUid}`. These routes must win, or
     * "workflows" is read as an automation uid and served by the wrong controller.
     */
    public function test_the_v2_routes_are_not_captured_by_the_b4_wildcard(): void
    {
        $tenant = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($tenant['customer']);

        $this->callJson('GET', $this->routeUrl('index', $tenant['workspace'], $tenant['business']))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']])
            ->assertJsonPath('data.0.uid', $tenant['workflow']->uid);
    }

    public function test_the_owner_is_allowed(): void
    {
        $tenant = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($tenant['customer']);

        $this->callJson('GET', $this->routeUrl('show', $tenant['workspace'], $tenant['business'], $tenant['workflow']))
            ->assertOk()
            ->assertJsonPath('data.uid', $tenant['workflow']->uid);
    }

    public function test_an_active_admin_member_is_allowed(): void
    {
        $tenant = $this->tenantWithWorkflow();
        $admin = $this->member($tenant['workspace'], WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($admin);

        $this->callJson('GET', $this->routeUrl('show', $tenant['workspace'], $tenant['business'], $tenant['workflow']))
            ->assertOk();
    }

    public function test_a_foreign_account_is_not_found_on_every_route(): void
    {
        $tenant = $this->tenantWithWorkflow();
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($tenant['customer']);

        $this->callEvery($tenant, $otherWorkspace->uid, $tenant['business']->uid, null, null, 404, 'foreign Account');
    }

    public function test_a_foreign_business_is_not_found_on_every_route(): void
    {
        $tenant = $this->tenantWithWorkflow();
        [, $otherBusiness] = $this->entitledTenant();
        $this->authenticateAsCustomer($tenant['customer']);

        // Another Business's uid, addressed through the caller's own Account.
        $this->callEvery($tenant, $tenant['workspace']->uid, $otherBusiness->uid, null, null, 404, 'foreign Business');
    }

    public function test_a_foreign_workflow_is_not_found_on_every_route(): void
    {
        $tenant = $this->tenantWithWorkflow();
        $other = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($tenant['customer']);

        // The caller's own Account and Business, another Business's workflow.
        $this->callEvery(
            $tenant,
            $tenant['workspace']->uid,
            $tenant['business']->uid,
            $other['workflow']->uid,
            null,
            404,
            'foreign workflow',
            onlyWorkflowRoutes: true,
        );
    }

    public function test_an_enrollment_from_another_workflow_is_not_found(): void
    {
        $tenant = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($tenant['customer']);

        // A second workflow in the SAME Business, and its enrollment.
        [$sibling] = $this->publishWorkflow($tenant['business'], [$this->endStep()], name: 'Sibling');
        $siblingContact = $this->contactFor($tenant['business'], 'Sibling');
        $siblingEnrollment = app(\App\Library\Automation\Workflow\Contracts\EnrollmentService::class)
            ->enroll($sibling, $siblingContact, (string) $siblingContact->id);

        $this->callJson('GET', $this->routeUrl(
            'enrollments.logs',
            $tenant['workspace'],
            $tenant['business'],
            $tenant['workflow'],
            $siblingEnrollment,
        ))->assertNotFound();

        // And another Business's enrollment entirely.
        $other = $this->tenantWithWorkflow();

        $this->callJson('GET', $this->routeUrl(
            'enrollments.logs',
            $tenant['workspace'],
            $tenant['business'],
            $tenant['workflow'],
            $other['enrollment'],
        ))->assertNotFound();
    }

    /** The portal's existing convention for a Gate denial is 401. */
    public function test_an_actor_without_the_automations_permission_is_denied_on_every_route(): void
    {
        $tenant = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($tenant['customer'], []);

        $this->callEvery($tenant, $tenant['workspace']->uid, $tenant['business']->uid, null, null, 401, 'no permission');
    }

    public function test_an_unentitled_business_is_not_found_on_every_route(): void
    {
        $tenant = $this->tenantWithWorkflow();
        [$customer, $business, $workspace] = $this->unentitledTenant();
        $this->authenticateAsCustomer($customer);

        // Workflow and enrollment uids are irrelevant: entitlement fails first.
        $this->callEvery($tenant, $workspace->uid, $business->uid, 'any', 'any', 404, 'not entitled');
    }

    public function test_an_inactive_member_is_not_found_on_every_route(): void
    {
        $tenant = $this->tenantWithWorkflow();
        $former = $this->member($tenant['workspace'], WorkspaceMembershipRole::Admin, active: false);
        $this->authenticateAsUser($former);

        $this->callEvery($tenant, $tenant['workspace']->uid, $tenant['business']->uid, null, null, 404, 'inactive member');
    }

    public function test_a_member_without_access_to_this_business_is_not_found_on_every_route(): void
    {
        $tenant = $this->tenantWithWorkflow();
        // Selected scope, assigned to nothing: a member of the Account who was
        // never given this Business.
        $limited = $this->member($tenant['workspace'], WorkspaceMembershipRole::Staff, scope: WorkspaceBusinessAccessScope::Selected);
        $this->authenticateAsUser($limited);

        $this->callEvery($tenant, $tenant['workspace']->uid, $tenant['business']->uid, null, null, 404, 'no Business access');
    }

    /**
     * An outsider addressing a victim's real uids end to end: every route 404s,
     * and — the part a status code alone would not prove — nothing about the
     * victim's workflow changed and no work was queued.
     */
    public function test_an_outsider_cannot_read_or_change_another_business(): void
    {
        $victim = $this->tenantWithWorkflow();
        $outsider = $this->tenantWithWorkflow();
        $this->authenticateAsCustomer($outsider['customer']);

        $before = [
            'status' => $victim['workflow']->fresh()->status->value,
            'draft' => $victim['workflow']->fresh()->draftVersion()?->id,
            'enrollments' => AutomationEnrollment::query()->where('workflow_id', $victim['workflow']->id)->count(),
            'workflows' => DB::table('automation_workflows')->where('business_id', $victim['business']->id)->count(),
        ];

        Bus::fake();

        $this->callEvery(
            $victim,
            $victim['workspace']->uid,
            $victim['business']->uid,
            $victim['workflow']->uid,
            $victim['enrollment']->uid,
            404,
            'outsider',
        );

        $this->assertSame($before, [
            'status' => $victim['workflow']->fresh()->status->value,
            'draft' => $victim['workflow']->fresh()->draftVersion()?->id,
            'enrollments' => AutomationEnrollment::query()->where('workflow_id', $victim['workflow']->id)->count(),
            'workflows' => DB::table('automation_workflows')->where('business_id', $victim['business']->id)->count(),
        ], 'An outsider must not be able to change anything, whatever status they were shown.');

        Bus::assertNothingDispatched();
    }

    /**
     * Tenancy is decided BEFORE the body is validated. A caller who cannot see
     * this Business gets 404 for an invalid body too, and so learns nothing about
     * what a valid one looks like.
     */
    public function test_tenancy_is_decided_before_validation(): void
    {
        $tenant = $this->tenantWithWorkflow();
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($tenant['customer']);

        $this->callJson('POST', $this->routeUrl('store', $otherWorkspace, $tenant['business']), [])
            ->assertNotFound();

        $this->callJson('POST', $this->routeUrl('enrollments.manual', $otherWorkspace, $tenant['business'], $tenant['workflow']), [
            'contact_uids' => array_fill(0, 600, 'x'),
        ])->assertNotFound();
    }

    /** A JSON denial is a real 404 status — not the application Handler's 200. */
    public function test_a_json_denial_carries_a_real_status_code(): void
    {
        $tenant = $this->tenantWithWorkflow();
        [, , $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($tenant['customer']);

        $response = $this->callJson('GET', $this->routeUrl('index', $otherWorkspace, $tenant['business']));

        $this->assertSame(404, $response->status());
        $this->assertStringNotContainsString('"status":"error"', (string) $response->getContent(), 'The generic Handler body must never be what answers.');
    }
}
