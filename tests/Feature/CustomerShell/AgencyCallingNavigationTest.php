<?php

namespace Tests\Feature\CustomerShell;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/** Settings -> Calling is reachable from the Agency interface and the Business interface, and never guesses a tenant. */
class AgencyCallingNavigationTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private function callingUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.calling.show', [$workspace->uid, $business->uid]);
    }

    public function test_an_agency_with_one_business_gets_a_calling_entry_that_opens_that_business(): void
    {
        [$agency, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Northwind Studio', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->switchToAccount($workspace);

        $html = $this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertOk()->getContent();
        $this->assertContains('calling', $this->settingsHubModules($html)['outreach'] ?? [], 'Calling is in the Agency Settings hub.');
        $this->assertStringContainsString($this->callingUrl($workspace, $business), $html, 'The entry opens THIS agency\'s own business, never another tenant.');

        // The existing business-scoped Calling page opens for the Agency owner, and so does the chooser (straight through).
        $this->get($this->callingUrl($workspace, $business))->assertOk()->assertSee('When someone calls');
        $this->get(route('customer.workspaces.calling.index', $workspace->uid))->assertRedirect($this->callingUrl($workspace, $business));
    }

    public function test_the_agency_chooser_and_calling_pages_are_closed_to_other_tenants(): void
    {
        // The platform allows one Business per Workspace, so the chooser is a defensive fallback; it must still never leak.
        [$agency, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Northwind Studio', 'Northwind Agency');
        [$otherOwner, $foreignBusiness, $foreignWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Other Studio', 'Other Agency');
        $this->authenticateAs($agency);
        $this->switchToAccount($workspace);

        $this->get(route('customer.workspaces.calling.index', $foreignWorkspace->uid))->assertStatus(404);
        $this->get($this->callingUrl($foreignWorkspace, $foreignBusiness))->assertStatus(404);
        $this->put(route('customer.workspaces.businesses.calling.update', [$foreignWorkspace->uid, $foreignBusiness->uid]), ['mode' => 'off', 'browser_ring_secs' => 20])->assertStatus(404);
        $this->assertSame(0, \App\Models\BusinessInboundCallSetting::query()->count());
    }

    public function test_the_business_settings_calling_entry_is_preserved(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertContains('calling', $this->settingsHubModules($html)['communication'] ?? []);
    }
}
