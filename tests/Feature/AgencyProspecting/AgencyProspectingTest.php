<?php

namespace Tests\Feature\AgencyProspecting;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\AppConfig;
use App\Models\Blacklists;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Library\Entitlement\EntitlementManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agency AI Prospecting foundation — a Workspace-level (never Business-
 * scoped) acquisition system. Covers: Workspace-role authorization (owner/
 * active admin allowed, staff/outsider/inactive-membership denied),
 * Agency-plan entitlement gating on the live HTTP surface, prospect/
 * campaign cross-Workspace isolation, opt-out isolation from Business
 * Blacklists, Workspace-scoped agent-settings isolation, and the absence
 * of any hardcoded agency-specific content.
 */
class AgencyProspectingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->ensureRequiredAppConfigRowsExist();
    }

    // -----------------------------------------------------------------
    // Workspace-role authorization.
    // -----------------------------------------------------------------

    public function test_owner_can_view_the_overview_page(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertOk();
    }

    public function test_active_admin_can_view_the_overview_page(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $admin = $this->createCustomerUser();
        $this->makeMembership($workspace, $admin, WorkspaceMembershipRole::Admin, true);

        $this->authenticateAsCustomer($admin);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertOk();
    }

    public function test_staff_is_denied_by_default(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $staff = $this->createCustomerUser();
        $this->makeMembership($workspace, $staff, WorkspaceMembershipRole::Staff, true);

        $this->authenticateAsCustomer($staff);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);
    }

    public function test_outsider_is_denied(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $outsider = $this->createCustomerUser();
        $this->authenticateAsCustomer($outsider);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);
    }

    public function test_inactive_admin_membership_is_denied(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $admin = $this->createCustomerUser();
        $this->makeMembership($workspace, $admin, WorkspaceMembershipRole::Admin, false);

        $this->authenticateAsCustomer($admin);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);
    }

    public function test_unknown_workspace_uid_404s_same_as_a_foreign_one(): void
    {
        [$owner] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.overview', 'no-such-workspace'))->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Entitlement gating on the live HTTP surface.
    // -----------------------------------------------------------------

    public function test_core_tier_workspace_owner_is_denied(): void
    {
        [$owner, $workspace] = $this->workspaceOnTier(WorkspacePlanTier::Core);
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);
    }

    public function test_growth_tier_workspace_owner_is_denied(): void
    {
        [$owner, $workspace] = $this->workspaceOnTier(WorkspacePlanTier::Growth);
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);
    }

    public function test_agency_tier_workspace_owner_is_allowed(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertOk();
    }

    // -----------------------------------------------------------------
    // Correction 1 — Workspace.is_active must independently gate every
    // action, in addition to (never instead of) Workspace-role authority
    // and WorkspacePlanAssignment.status. WorkspaceRepository::allForUser()
    // deliberately returns a Workspace regardless of its own active
    // state, so this boundary must enforce is_active itself.
    // -----------------------------------------------------------------

    public function test_inactive_agency_workspace_owner_is_denied(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $workspace->update(['is_active' => false]);

        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);
    }

    public function test_inactive_agency_workspace_active_admin_is_denied(): void
    {
        [, $workspace] = $this->agencyWorkspace();
        $admin = $this->createCustomerUser();
        $this->makeMembership($workspace, $admin, WorkspaceMembershipRole::Admin, true);
        $workspace->update(['is_active' => false]);

        $this->authenticateAsCustomer($admin);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);
    }

    public function test_reactivated_agency_workspace_becomes_reachable_again(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $workspace->update(['is_active' => false]);
        $this->authenticateAsCustomer($owner);
        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertStatus(404);

        $workspace->update(['is_active' => true]);

        $this->get(route('customer.workspaces.prospecting.overview', $workspace->uid))->assertOk();
    }

    // -----------------------------------------------------------------
    // Entry/selector route.
    // -----------------------------------------------------------------

    public function test_entry_route_redirects_when_exactly_one_entitled_workspace_is_accessible(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.prospecting.index'))
            ->assertRedirect(route('customer.workspaces.prospecting.overview', $workspace->uid));
    }

    public function test_entry_route_never_offers_a_non_entitled_workspace(): void
    {
        [$owner] = $this->workspaceOnTier(WorkspacePlanTier::Core);
        $this->authenticateAsCustomer($owner);

        $this->get(route('customer.prospecting.index'))->assertOk();
    }

    public function test_entry_route_never_offers_or_redirects_into_an_inactive_workspace(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $workspace->update(['is_active' => false]);
        $this->authenticateAsCustomer($owner);

        // Not a redirect into the (inactive) overview — a 302 here would
        // mean the inactive Workspace was wrongly offered as the sole
        // accessible one.
        $this->get(route('customer.prospecting.index'))->assertOk();
    }

    // -----------------------------------------------------------------
    // Prospect isolation.
    // -----------------------------------------------------------------

    public function test_workspace_a_does_not_see_workspace_bs_prospect_on_the_list(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $prospectB = $this->createProspect($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $response = $this->get(route('customer.workspaces.prospecting.prospects.index', $workspaceA->uid));
        $response->assertOk();
        $response->assertDontSee($prospectB->uid);
    }

    public function test_workspace_a_cannot_view_workspace_bs_prospect_directly(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $prospectB = $this->createProspect($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $this->get(route('customer.workspaces.prospecting.prospects.show', [$workspaceA->uid, $prospectB->uid]))
            ->assertStatus(404);
    }

    public function test_workspace_a_cannot_stop_workspace_bs_prospect(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $prospectB = $this->createProspect($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.prospects.stop', [$workspaceA->uid, $prospectB->uid]))
            ->assertStatus(404);

        $this->assertSame('active', $prospectB->fresh()->status->value);
        $this->assertNull($prospectB->fresh()->stopped_at);
    }

    public function test_workspace_a_cannot_mark_workspace_bs_prospect_booked(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $prospectB = $this->createProspect($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.prospects.mark-booked', [$workspaceA->uid, $prospectB->uid]))
            ->assertStatus(404);

        $this->assertSame('active', $prospectB->fresh()->status->value);
        $this->assertNull($prospectB->fresh()->booked_at);
    }

    // -----------------------------------------------------------------
    // Campaign isolation.
    // -----------------------------------------------------------------

    public function test_workspace_a_cannot_view_workspace_bs_campaign(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $campaignB = $this->createCampaign($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $this->get(route('customer.workspaces.prospecting.campaigns.show', [$workspaceA->uid, $campaignB->uid]))
            ->assertStatus(404);
    }

    public function test_workspace_a_cannot_update_workspace_bs_campaign_status(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $campaignB = $this->createCampaign($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.campaigns.status', [$workspaceA->uid, $campaignB->uid]), [
            'status' => 'active',
        ])->assertStatus(404);

        $this->assertSame('draft', $campaignB->fresh()->status->value);
    }

    public function test_workspace_a_cannot_enroll_a_prospect_into_workspace_bs_campaign(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $campaignB = $this->createCampaign($workspaceB);
        $prospectA = $this->createProspect($workspaceA);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspaceA->uid, $campaignB->uid]), [
            'prospect_uid' => $prospectA->uid,
        ])->assertStatus(404);

        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $campaignB->id)->count());
    }

    public function test_cannot_enroll_workspace_bs_prospect_into_workspace_as_own_campaign(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $campaignA = $this->createCampaign($workspaceA);
        $prospectB = $this->createProspect($workspaceB);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspaceA->uid, $campaignA->uid]), [
            'prospect_uid' => $prospectB->uid,
        ])->assertStatus(404);

        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $campaignA->id)->count());
    }

    public function test_enrolling_an_own_prospect_into_an_own_campaign_succeeds(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $campaign = $this->createCampaign($workspace);
        $prospect = $this->createProspect($workspace);

        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspace->uid, $campaign->uid]), [
            'prospect_uid' => $prospect->uid,
        ])->assertSessionHas('flash_success');

        $member = AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->where('prospect_id', $prospect->id)->first();
        $this->assertNotNull($member);
        $this->assertSame($workspace->id, $member->workspace_id);
        $this->assertSame(1, $member->stage->value);
    }

    // -----------------------------------------------------------------
    // Correction 1 — a terminal-status prospect (Stopped/Booked) must
    // never be newly enrolled, server-side, regardless of what a forged
    // request submits.
    // -----------------------------------------------------------------

    public function test_stopped_prospect_cannot_be_enrolled(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $campaign = $this->createCampaign($workspace);
        $prospect = $this->createProspect($workspace, ['status' => 'stopped', 'stopped_at' => now()]);

        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspace->uid, $campaign->uid]), [
            'prospect_uid' => $prospect->uid,
        ])->assertSessionHas('flash_error');

        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->count());
    }

    public function test_booked_prospect_cannot_be_enrolled(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $campaign = $this->createCampaign($workspace);
        $prospect = $this->createProspect($workspace, ['status' => 'booked', 'booked_at' => now()]);

        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.campaigns.members.store', [$workspace->uid, $campaign->uid]), [
            'prospect_uid' => $prospect->uid,
        ])->assertSessionHas('flash_error');

        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->count());
    }

    // -----------------------------------------------------------------
    // Correction 1 — duplicate phone identity within one Workspace.
    // -----------------------------------------------------------------

    public function test_a_second_prospect_with_the_same_phone_in_the_same_workspace_is_rejected(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $this->createProspect($workspace, ['phone' => '15559998888']);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.prospects.store', $workspace->uid), [
            'company_name' => 'Duplicate Co',
            'phone' => '15559998888',
        ])->assertSessionHasErrors(['phone']);

        $this->assertSame(1, AgencyProspect::where('workspace_id', $workspace->id)->where('phone', '15559998888')->count());
    }

    public function test_the_same_phone_in_a_different_workspace_is_allowed(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $this->createProspect($workspaceB, ['phone' => '15557778888']);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.prospects.store', $workspaceA->uid), [
            'company_name' => 'Workspace A Co',
            'phone' => '15557778888',
        ])->assertSessionHas('flash_success');

        $this->assertSame(1, AgencyProspect::where('workspace_id', $workspaceA->id)->where('phone', '15557778888')->count());
        $this->assertSame(1, AgencyProspect::where('workspace_id', $workspaceB->id)->where('phone', '15557778888')->count());
    }

    // -----------------------------------------------------------------
    // Correction 1 — stopping/booking a prospect synchronizes every
    // existing campaign membership to the matching terminal stage,
    // atomically, without affecting any other Workspace.
    // -----------------------------------------------------------------

    public function test_stopping_a_prospect_moves_every_membership_to_the_stopped_stage(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        [, $otherWorkspace] = $this->agencyWorkspace();
        $prospect = $this->createProspect($workspace);
        $campaignOne = $this->createCampaign($workspace, ['name' => 'Campaign One']);
        $campaignTwo = $this->createCampaign($workspace, ['name' => 'Campaign Two']);
        $memberOne = AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $campaignOne->id, 'prospect_id' => $prospect->id, 'stage' => 1, 'enrolled_at' => now()]);
        $memberTwo = AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $campaignTwo->id, 'prospect_id' => $prospect->id, 'stage' => 3, 'enrolled_at' => now()]);

        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.prospects.stop', [$workspace->uid, $prospect->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame('stopped', $prospect->fresh()->status->value);
        $this->assertSame(99, $memberOne->fresh()->stage->value);
        $this->assertSame(99, $memberTwo->fresh()->stage->value);
        $this->assertSame(0, Blacklists::count());
        $this->assertSame(0, AgencyProspect::where('workspace_id', $otherWorkspace->id)->count());
    }

    public function test_marking_a_prospect_booked_moves_every_membership_to_the_booked_stage(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        [, $otherWorkspace] = $this->agencyWorkspace();
        $prospect = $this->createProspect($workspace);
        $campaignOne = $this->createCampaign($workspace, ['name' => 'Campaign One']);
        $campaignTwo = $this->createCampaign($workspace, ['name' => 'Campaign Two']);
        $memberOne = AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $campaignOne->id, 'prospect_id' => $prospect->id, 'stage' => 1, 'enrolled_at' => now()]);
        $memberTwo = AgencyProspectCampaignMember::create(['workspace_id' => $workspace->id, 'campaign_id' => $campaignTwo->id, 'prospect_id' => $prospect->id, 'stage' => 4, 'enrolled_at' => now()]);

        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.prospects.mark-booked', [$workspace->uid, $prospect->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame('booked', $prospect->fresh()->status->value);
        $this->assertSame(6, $memberOne->fresh()->stage->value);
        $this->assertSame(6, $memberTwo->fresh()->stage->value);
        $this->assertSame(0, AgencyProspect::where('workspace_id', $otherWorkspace->id)->count());
    }

    // -----------------------------------------------------------------
    // Opt-out isolation — Workspace-scoped, never a Business Blacklist.
    // -----------------------------------------------------------------

    public function test_stopping_a_prospect_never_creates_a_business_blacklist_row(): void
    {
        [$owner, $workspace] = $this->agencyWorkspace();
        $prospect = $this->createProspect($workspace);
        $this->authenticateAsCustomer($owner);

        $this->post(route('customer.workspaces.prospecting.prospects.stop', [$workspace->uid, $prospect->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame('stopped', $prospect->fresh()->status->value);
        $this->assertNotNull($prospect->fresh()->stopped_at);
        $this->assertSame(0, Blacklists::count());
    }

    public function test_stopping_a_prospect_in_workspace_a_does_not_affect_workspace_b(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        $prospectA = $this->createProspect($workspaceA, ['phone' => '15551234567']);
        $prospectB = $this->createProspect($workspaceB, ['phone' => '15551234567']);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.prospects.stop', [$workspaceA->uid, $prospectA->uid]))
            ->assertSessionHas('flash_success');

        $this->assertSame('stopped', $prospectA->fresh()->status->value);
        $this->assertSame('active', $prospectB->fresh()->status->value);
    }

    // -----------------------------------------------------------------
    // Agent settings — Workspace-scoped, never leaked cross-Workspace.
    // -----------------------------------------------------------------

    public function test_workspace_a_cannot_view_workspace_bs_agent_settings(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        AgencyProspectingSetting::create(['workspace_id' => $workspaceB->id, 'agency_name' => 'Workspace B Secret Agency']);

        $this->authenticateAsCustomer($ownerA);

        $this->get(route('customer.workspaces.prospecting.settings.show', $workspaceB->uid))->assertStatus(404);
    }

    public function test_updating_agent_settings_only_affects_the_selected_workspace(): void
    {
        [$ownerA, $workspaceA] = $this->agencyWorkspace();
        [, $workspaceB] = $this->agencyWorkspace();
        AgencyProspectingSetting::create(['workspace_id' => $workspaceB->id, 'agency_name' => 'Workspace B Agency']);

        $this->authenticateAsCustomer($ownerA);

        $this->post(route('customer.workspaces.prospecting.settings.update', $workspaceA->uid), [
            'agency_name' => 'Workspace A Agency',
        ])->assertSessionHas('flash_success');

        $this->assertSame('Workspace A Agency', AgencyProspectingSetting::where('workspace_id', $workspaceA->id)->first()->agency_name);
        $this->assertSame('Workspace B Agency', AgencyProspectingSetting::where('workspace_id', $workspaceB->id)->first()->agency_name);
    }

    // -----------------------------------------------------------------
    // No hardcoded agency-specific content.
    // -----------------------------------------------------------------

    public function test_no_hardcoded_agency_specific_content_in_the_controller_or_views(): void
    {
        $paths = array_merge(
            [base_path('app/Http/Controllers/Customer/Workspace/AgencyProspectingController.php')],
            glob(base_path('resources/views/customer/workspaces/prospecting/*.blade.php')),
        );

        $needles = ['jazmin', 'photobooth', 'photo booth'];

        foreach ($paths as $path) {
            $contents = strtolower(file_get_contents($path));

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $contents, "Unexpected hardcoded content [{$needle}] in {$path}.");
            }
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createCustomerUser(): User
    {
        $user = User::create([
            'first_name' => 'Test', 'last_name' => 'User',
            'email' => 'user' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
            'email_verified_at' => now(),
        ]);

        \App\Models\Customer::create(['user_id' => $user->id]);

        return $user;
    }

    private function createPlatformAdmin(): int
    {
        return User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin',
            'email' => 'platform-admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceOnTier(WorkspacePlanTier $tier): array
    {
        $owner = $this->createCustomerUser();
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);

        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->createPlatformAdmin(), 'Fixture assignment.', true, 0);

        return [$owner, $workspace->fresh()];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function agencyWorkspace(): array
    {
        return $this->workspaceOnTier(WorkspacePlanTier::Agency);
    }

    private function makeMembership(Workspace $workspace, User $user, WorkspaceMembershipRole $role, bool $isActive): WorkspaceMembership
    {
        return WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'is_active' => $isActive,
        ]);
    }

    private function createProspect(Workspace $workspace, array $overrides = []): AgencyProspect
    {
        return AgencyProspect::create(array_merge([
            'workspace_id' => $workspace->id,
            'company_name' => 'Test Prospect Co',
            'phone' => '15550001111',
            'status' => 'active',
        ], $overrides));
    }

    private function createCampaign(Workspace $workspace, array $overrides = []): AgencyProspectCampaign
    {
        return AgencyProspectCampaign::create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => 'Test Campaign',
            'status' => 'draft',
        ], $overrides));
    }

    private function authenticateAsCustomer(User $user): void
    {
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($user);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
