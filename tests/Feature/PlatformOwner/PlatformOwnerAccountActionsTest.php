<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspaceEntitlementTransitionType;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformOwner\NothingToRestoreException;
use App\Library\PlatformOwner\PlatformOwnerAccountActions;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementTransition;
use App\Repositories\Contracts\WorkspaceEntitlementTransitionRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner / Admin V1 §§7, 10 — the two account-state writes this
 * surface exposes (restore access, Business status) go through the canonical
 * domain operation, require authority independently of the controller, need a
 * reason where they are high-impact, refuse stale or ineligible targets, are
 * idempotent, and leave exactly one audit row.
 */
class PlatformOwnerAccountActionsTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
    }

    private function assignment(Workspace $workspace)
    {
        return app(WorkspacePlanAssignmentRepository::class)->findByWorkspaceIdForUpdate($workspace->id);
    }

    private function lock(Workspace $workspace): void
    {
        app(EntitlementManager::class)->lockForNonPayment($workspace, $this->platformAdminId(), 'Fixture lock.');
    }

    private function restoreUrl(Workspace $workspace): string
    {
        return route('admin.platform-owner.workspaces.restore-access', $workspace);
    }

    private function restoredRows(Workspace $workspace)
    {
        return WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', WorkspaceEntitlementTransitionType::AccessRestored->value)
            ->get();
    }

    // -- Restore access ----------------------------------------------------------

    public function test_a_platform_owner_restores_a_locked_workspace_through_the_canonical_manager(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lock($workspace);
        $this->assertTrue(app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->isLocked());
        $owner = $this->actingAsPlatformOwner();

        $this->post($this->restoreUrl($workspace), ['reason' => 'Verified payment in Stripe, ticket 4411.', 'confirm' => 1])
            ->assertRedirect(route('admin.workspaces.show', $workspace))
            ->assertSessionHas('po_flash_success');

        $assignment = $this->assignment($workspace);
        $this->assertNull($assignment->locked_at);
        $this->assertNull($assignment->grace_started_at);
        $this->assertFalse(app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->isLocked());

        // The customer-facing gate lets them in again.
        $this->authenticateAs($customer);
        $this->assertNotSame(route('customer.account-locked.show'), $this->home()->headers->get('Location'));

        // Exactly one audit row: actor, action, reason.
        $rows = $this->restoredRows($workspace);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $owner->id, (int) $rows->first()->actor_user_id);
        $this->assertSame('Verified payment in Stripe, ticket 4411.', $rows->first()->reason);
    }

    public function test_a_workspace_in_grace_can_also_be_restored(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->enterGracePeriod($workspace, $this->platformAdminId(), 'Fixture grace.');
        $this->actingAsPlatformOwner();

        $this->post($this->restoreUrl($workspace), ['reason' => 'Card updated.', 'confirm' => 1])->assertSessionHas('po_flash_success');

        $this->assertNull($this->assignment($workspace)->grace_started_at);
        $this->assertCount(1, $this->restoredRows($workspace));
    }

    public function test_the_restore_form_is_offered_only_when_there_is_something_to_restore_and_carries_csrf_and_confirmation(): void
    {
        [, , $healthy] = $this->tenant(WorkspacePlanTier::Core, 'Healthy Co', 'Healthy WS');
        [, , $locked] = $this->tenant(WorkspacePlanTier::Core, 'Locked Co', 'Locked WS');
        $this->lock($locked);
        $this->actingAsPlatformOwner();

        $this->get(route('admin.workspaces.show', $healthy))->assertOk()->assertDontSee('data-testid="po-restore-form"', false);

        $html = $this->get(route('admin.workspaces.show', $locked))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="po-restore-form"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="confirm"', $html);
        $this->assertStringContainsString('name="reason"', $html);
    }

    public function test_a_reason_and_an_explicit_confirmation_are_required(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lock($workspace);
        $this->actingAsPlatformOwner();

        $this->post($this->restoreUrl($workspace), ['confirm' => 1])->assertSessionHasErrors('reason');
        $this->post($this->restoreUrl($workspace), ['reason' => '   ', 'confirm' => 1])->assertSessionHasErrors('reason');
        $this->post($this->restoreUrl($workspace), ['reason' => 'Because.'])->assertSessionHasErrors('confirm');

        $this->assertNotNull($this->assignment($workspace)->locked_at, 'A refused request must change nothing.');
        $this->assertCount(0, $this->restoredRows($workspace));
    }

    public function test_repeating_the_submission_is_safe_and_audits_exactly_once(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lock($workspace);
        $this->actingAsPlatformOwner();

        $payload = ['reason' => 'Paid by wire.', 'confirm' => 1];
        $this->post($this->restoreUrl($workspace), $payload)->assertSessionHas('po_flash_success');
        $this->post($this->restoreUrl($workspace), $payload)->assertSessionHas('po_flash_error');
        $this->post($this->restoreUrl($workspace), $payload)->assertSessionHas('po_flash_error');

        $this->assertCount(1, $this->restoredRows($workspace));
    }

    public function test_a_stale_target_with_nothing_to_restore_is_refused_without_a_write(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        $before = WorkspaceEntitlementTransition::query()->count();

        $this->post($this->restoreUrl($workspace), ['reason' => 'Nothing was wrong.', 'confirm' => 1])
            ->assertSessionHas('po_flash_error');

        $this->assertSame($before, WorkspaceEntitlementTransition::query()->count());
    }

    public function test_a_running_trial_is_never_converted_by_the_restore_control(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $trialEnd = now()->addDays(5);
        \App\Models\WorkspacePlanAssignment::query()->where('workspace_id', $workspace->id)->update(['trial_ends_at' => $trialEnd]);
        $this->actingAsPlatformOwner();

        $this->get(route('admin.workspaces.show', $workspace))->assertOk()->assertDontSee('data-testid="po-restore-form"', false);
        $this->post($this->restoreUrl($workspace), ['reason' => 'Trying.', 'confirm' => 1])->assertSessionHas('po_flash_error');

        $this->assertNotNull($this->assignment($workspace)->trial_ends_at, 'recoverAccess() clears the trial, so it must not run for a trial-only account.');
        $this->assertCount(0, $this->restoredRows($workspace));
    }

    public function test_suspended_and_inactive_plans_are_not_restored_by_this_control(): void
    {
        foreach ([WorkspacePlanAssignmentStatus::Suspended, WorkspacePlanAssignmentStatus::Inactive] as $status) {
            [, , $workspace] = $this->tenant(WorkspacePlanTier::Core, "{$status->value} Co", "{$status->value} WS");
            $this->lock($workspace);
            app(EntitlementManager::class)->changePlanStatus($workspace, $status, $this->platformAdminId(), 'Fixture.');
            $this->actingAsPlatformOwner();

            $this->post($this->restoreUrl($workspace), ['reason' => 'Trying.', 'confirm' => 1])->assertSessionHas('po_flash_error');

            $this->assertNotNull($this->assignment($workspace)->locked_at, "[{$status->value}] the lock must be untouched.");
            $this->assertCount(0, $this->restoredRows($workspace));
        }
    }

    public function test_a_workspace_without_a_plan_is_refused(): void
    {
        $fixture = $this->unassignedWorkspace('No Plan WS');
        $this->actingAsPlatformOwner();

        $this->post($this->restoreUrl($fixture['workspace']), ['reason' => 'Trying.', 'confirm' => 1])->assertSessionHas('po_flash_error');
        $this->assertCount(0, $this->restoredRows($fixture['workspace']));
    }

    public function test_the_service_rechecks_authority_itself_and_does_not_trust_its_caller(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lock($workspace);
        $actions = app(PlatformOwnerAccountActions::class);

        try {
            $actions->restoreAccess($workspace->id, (int) $customer->user_id, 'Called directly.');
            $this->fail('A non-administrator must be refused by the service.');
        } catch (AuthorizationException) {
            $this->assertNotNull($this->assignment($workspace)->locked_at);
        }

        try {
            $actions->changeBusinessStatus($business->id, BusinessStatus::Inactive, (int) $customer->user_id, 'Called directly.');
            $this->fail('A non-administrator must be refused by the service.');
        } catch (AuthorizationException) {
            $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        }

        $this->assertSame(0, WorkspaceEntitlementTransition::query()->where('actor_user_id', $customer->user_id)->count());
    }

    public function test_the_service_refuses_when_there_is_nothing_to_restore(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->expectException(NothingToRestoreException::class);
        app(PlatformOwnerAccountActions::class)->restoreAccess($workspace->id, $this->platformAdminId(), 'Nothing outstanding.');
    }

    // -- Business status ---------------------------------------------------------

    private function statusRows(Business $business)
    {
        return WorkspaceEntitlementTransition::query()
            ->where('workspace_id', $business->workspace_id)
            ->where('transition_type', WorkspaceEntitlementTransitionType::BusinessStatusChanged->value)
            ->get();
    }

    public function test_deactivating_a_business_requires_a_reason_and_is_audited_once(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Core);
        $owner = $this->actingAsPlatformOwner();
        $url = route('admin.businesses.status.update', $business);

        // No reason: refused, nothing written, nothing audited.
        $this->patch($url, ['status' => 'inactive'])->assertSessionHasErrors('reason');
        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);
        $this->assertCount(0, $this->statusRows($business));

        // With a reason: written and audited exactly once, with before/after.
        $this->patch($url, ['status' => 'inactive', 'reason' => 'Chargeback dispute, ticket 77.'])
            ->assertRedirect(route('admin.businesses.show', $business))
            ->assertSessionHas('status', 'success');

        $this->assertSame(BusinessStatus::Inactive, $business->fresh()->status);

        $rows = $this->statusRows($business);
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame((int) $owner->id, (int) $row->actor_user_id);
        $this->assertSame('Chargeback dispute, ticket 77.', $row->reason);
        // MySQL JSON reorders object keys: compare as maps, not as ordered arrays.
        $this->assertEquals(['business_id' => $business->id, 'business_uid' => $business->uid, 'from' => 'active', 'to' => 'inactive'], $row->payload);

        // Repeating it changes nothing and audits nothing more.
        $this->patch($url, ['status' => 'inactive', 'reason' => 'Chargeback dispute, ticket 77.'])->assertSessionHas('status', 'success');
        $this->assertCount(1, $this->statusRows($business));
    }

    public function test_reactivating_a_business_needs_no_reason_but_is_still_audited(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();
        $url = route('admin.businesses.status.update', $business);

        $this->patch($url, ['status' => 'inactive', 'reason' => 'Paused.']);
        $this->patch($url, ['status' => 'active'])->assertSessionHas('status', 'success');

        $this->assertSame(BusinessStatus::Active, $business->fresh()->status);

        $rows = $this->statusRows($business)->sortBy('id')->values();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[1]->reason);
        $this->assertSame('inactive', $rows[1]->payload['from']);
        $this->assertSame('active', $rows[1]->payload['to']);
    }

    public function test_activating_a_draft_business_still_stamps_activated_at(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Core);
        $business->forceFill(['status' => BusinessStatus::Draft, 'activated_at' => null])->save();
        $this->actingAsPlatformOwner();

        $this->patch(route('admin.businesses.status.update', $business), ['status' => 'active'])->assertSessionHas('status', 'success');

        $fresh = $business->fresh();
        $this->assertSame(BusinessStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->activated_at);
        $this->assertCount(1, $this->statusRows($business));
    }

    public function test_a_stale_business_target_is_a_404_and_a_foreign_status_value_is_rejected(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Core);
        $this->actingAsPlatformOwner();

        $this->patch(route('admin.businesses.status.update', 'no-such-business'), ['status' => 'active'])->assertNotFound();
        $this->patch(route('admin.businesses.status.update', $business), ['status' => 'deleted', 'reason' => 'x'])->assertSessionHasErrors('status');
        $this->assertCount(0, $this->statusRows($business));
    }

    // -- Audit trail --------------------------------------------------------------

    public function test_the_audit_page_lists_platform_owner_actions_and_only_those(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lock($workspace);
        $owner = $this->actingAsPlatformOwner();

        $this->post($this->restoreUrl($workspace), ['reason' => 'Audit page restore reason.', 'confirm' => 1]);
        $this->patch(route('admin.businesses.status.update', $business), ['status' => 'inactive', 'reason' => 'Audit page status reason.']);

        // A customer-driven row and a system row must not appear.
        app(WorkspaceEntitlementTransitionRepository::class)->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceEntitlementTransitionType::PlanChanged,
            'actor_user_id' => $customer->user_id,
            'reason' => 'CUSTOMER-DRIVEN-REASON',
        ]);
        app(WorkspaceEntitlementTransitionRepository::class)->create([
            'workspace_id' => $workspace->id,
            'transition_type' => WorkspaceEntitlementTransitionType::GraceStarted,
            'actor_user_id' => null,
            'reason' => 'SYSTEM-SWEEP-REASON',
        ]);

        $response = $this->get(route('admin.platform-owner.audit'))->assertOk();

        $response->assertSee('access_restored');
        $response->assertSee('business_status_changed');
        $response->assertSee('Audit page restore reason.');
        $response->assertSee('Audit page status reason.');
        $response->assertSee($owner->displayName());
        $response->assertSee($workspace->name);
        $response->assertDontSee('CUSTOMER-DRIVEN-REASON');
        $response->assertDontSee('SYSTEM-SWEEP-REASON');
    }

    public function test_the_workspace_page_shows_its_recent_actions_with_actor_and_reason(): void
    {
        [, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->lock($workspace);
        $this->actingAsPlatformOwner();
        $this->post($this->restoreUrl($workspace), ['reason' => 'Shown on the workspace page.', 'confirm' => 1]);

        $this->get(route('admin.workspaces.show', $workspace))
            ->assertOk()
            ->assertSee('access_restored')
            ->assertSee('Shown on the workspace page.')
            ->assertSee('Pat Owner');
    }
}
