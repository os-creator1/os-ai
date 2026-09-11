<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Account settings on the customer account page are the account name and the
 * team. Deactivating the account and the technical ownership-transfer form
 * (User UID, previous-owner disposition, Business access) are not customer
 * controls: cancellation belongs to Plan & subscription, and a real ownership
 * handover needs its own designed flow. The backend actions are kept.
 */
class WorkspaceAccountSettingsViewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_the_owner_sees_rename_and_team_but_no_deactivation_or_ownership_transfer(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($owner);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('data-workspace-action="rename"', false);
        $response->assertSee('data-workspace-action="members"', false);

        foreach (['data-workspace-action="deactivate"', 'data-workspace-action="ownership/transfer"', 'name="new_owner_user_uid"', 'name="previous_owner_disposition"', 'id="ownership-transfer-scope"'] as $markup) {
            $response->assertDontSee($markup, false);
        }

        foreach (['Deactivate account', 'Transfer ownership', 'New owner User UID', 'Previous owner disposition', 'User UID'] as $text) {
            $response->assertDontSee($text);
        }

        $this->assertTrue(Route::has('customer.workspaces.deactivate'), 'Kept for support.');
        $this->assertTrue(Route::has('customer.workspaces.ownership.transfer'), 'Kept for support and a future designed flow.');
    }

    public function test_an_already_inactive_account_can_still_be_reactivated_by_its_owner(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $workspace->forceFill(['is_active' => false])->save();
        $this->authenticateAs($owner);

        $response = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk();

        $response->assertSee('data-workspace-action="reactivate"', false);
        $response->assertDontSee('data-workspace-action="deactivate"', false);
    }
}
