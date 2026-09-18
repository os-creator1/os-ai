<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Account → Members → Add member: the Business access controls follow the
 * REAL number of Businesses the person adding can grant.
 *
 * Contract 13 (businesses_workspace_id_unique) makes "two or more
 * Businesses in one Workspace" permanently impossible, never just a
 * transitional/grandfathered state — so the count reaching this form is
 * now always exactly one: the actors who can reach team.show at all
 * (the Workspace owner, or an active All-scope Admin — Correction Round 1
 * §5.2/§5.4 refuses the account frame to anyone else) always have full
 * access to whatever sole Business the Workspace already holds.
 *
 * Contract 14 removed the form's old two-or-more-Businesses checkbox
 * branch as unreachable dead code (manageableBusinesses() is always scoped
 * to this one Workspace's own Businesses, at most one now) — see
 * resources/views/customer/workspaces/show.blade.php's own comment at that
 * site. This file's own former "two Businesses" / "several client
 * accounts" tests exercised exactly that removed branch and are deleted
 * alongside it, not replaced: there is no current V1 topology left that
 * could reach it.
 */
class WorkspaceAddMemberBusinessAccessFormTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_one_business_hides_the_access_controls_and_grants_that_business_only(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $mila = $this->customerAccount('mila@example.test');
        $this->authenticateAs($owner);

        $form = $this->membersForm($workspace->uid);

        $this->assertStringNotContainsString('<select class="form-control" id="member-scope"', $form, 'No Business access selector.');
        $this->assertStringNotContainsString('type="checkbox"', $form, 'No Business checkboxes.');
        $this->assertStringNotContainsString('All Businesses', $form);
        $this->assertStringContainsString("They'll get access to Harbor Lane Studios.", $form);

        // Submit exactly what the rendered form carries.
        $this->post(route('customer.workspaces.members.store', $workspace->uid), $this->hiddenFields($form) + [
            'member_email' => 'mila@example.test',
            'role' => 'staff',
        ])->assertSessionHas('flash_success');

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $mila->id)->firstOrFail();
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $membership->business_access_scope, 'Least privilege: selected, not all.');
        $this->assertSame([$business->id], $this->assignedBusinessIds($membership));
    }

    /**
     * The same rule for an agency-wide admin adding staff: one Business they
     * can grant, one simple form, selected access to it.
     */
    public function test_an_admin_in_a_one_business_account_gets_the_simple_form_too(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Harbor Lane');
        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $mila = $this->customerAccount('mila@example.test');
        $this->authenticateAs($admin);

        $form = $this->membersForm($workspace->uid);
        $this->assertStringContainsString("They'll get access to Harbor Lane Studios.", $form);
        $this->assertStringNotContainsString('id="member-scope"', $form);

        $this->post(route('customer.workspaces.members.store', $workspace->uid), $this->hiddenFields($form) + [
            'member_email' => 'mila@example.test',
            'role' => 'staff',
        ])->assertSessionHas('flash_success');

        $added = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $mila->id)->firstOrFail();
        $this->assertSame(WorkspaceBusinessAccessScope::Selected, $added->business_access_scope);
        $this->assertSame([$business->id], $this->assignedBusinessIds($added));
    }

    // -----------------------------------------------------------------------

    private function customerAccount(string $email): User
    {
        return User::create([
            'first_name' => 'Mila',
            'last_name' => 'Rivera',
            'email' => $email,
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
    }

    private function membersForm(string $workspaceUid): string
    {
        $html = $this->get(route('customer.workspaces.team.show', $workspaceUid))->assertOk()->getContent();
        $start = strpos($html, 'data-workspace-action="members"');
        $this->assertNotFalse($start, 'The Add member form must render.');
        $end = strpos($html, '</form>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    /**
     * The hidden inputs the rendered form would submit.
     *
     * @return array<string, mixed>
     */
    private function hiddenFields(string $form): array
    {
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)"/', $form, $matches, PREG_SET_ORDER);
        $fields = [];

        foreach ($matches as [, $name, $value]) {
            if ($name === '_token') {
                continue;
            }

            if (str_ends_with($name, '[]')) {
                $fields[substr($name, 0, -2)][] = html_entity_decode($value);
            } else {
                $fields[$name] = html_entity_decode($value);
            }
        }

        $this->assertSame('selected', $fields['business_access_scope'] ?? null, 'The simple form submits selected access.');

        return $fields;
    }

    /** @return array<int, int> */
    private function assignedBusinessIds(WorkspaceMembership $membership): array
    {
        return WorkspaceMembershipBusiness::where('workspace_membership_id', $membership->id)
            ->pluck('business_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }
}
