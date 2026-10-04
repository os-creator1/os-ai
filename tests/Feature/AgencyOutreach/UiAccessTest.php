<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\AgencyProspectingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * Who may open and change Outreach, and that one Agency never sees another's
 * script, prospects, campaigns or number on any tab.
 */
class UiAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private const TABS = ['overview', 'prospects.index', 'conversations.index', 'campaigns.index', 'script.show'];

    public function test_owner_and_admin_can_open_every_tab(): void
    {
        $a = $this->outreachAgency('Alpha');
        $admin = $this->createCustomer();
        $this->member($a['workspace'], $admin->user, WorkspaceMembershipRole::Admin);

        foreach ([$a['customer'], $admin] as $actor) {
            $this->authenticateAs($actor);

            foreach (self::TABS as $tab) {
                $this->get($this->outreachRoute($tab, $a['workspace']))->assertOk();
            }
        }
    }

    public function test_staff_another_workspace_and_a_non_agency_workspace_get_404_on_reads_and_writes(): void
    {
        $a = $this->outreachAgency('Alpha');
        $b = $this->outreachAgency('Bravo');
        [$growth, , $growthWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Biz', 'Growth WS');
        $staff = $this->createCustomer();
        $this->member($a['workspace'], $staff->user, WorkspaceMembershipRole::Staff);

        $cases = [
            [$staff, $a['workspace']],            // Staff of the Agency
            [$b['customer'], $a['workspace']],    // another Agency's owner
            [$growth, $growthWorkspace],          // owner of a non-Agency Workspace
        ];

        foreach ($cases as [$actor, $workspace]) {
            $this->authenticateAs($actor);

            foreach (self::TABS as $tab) {
                $this->get($this->outreachRoute($tab, $workspace))->assertNotFound();
            }

            $this->post($this->outreachRoute('script.update', $workspace), ['message_1' => 'Hacked'])->assertNotFound();
            $this->post($this->outreachRoute('script.preview', $workspace), ['text' => 'x'])->assertNotFound();
            $this->post($this->outreachRoute('sending.resume', $workspace))->assertNotFound();
            $this->post($this->outreachRoute('campaigns.managed.store', $workspace), ['name' => 'X', 'opening_message' => 'Hi'])->assertNotFound();
            $this->post($this->outreachRoute('conversations.pause', $workspace, [$a['member']->uid]))->assertNotFound();
        }

        $this->assertStringNotContainsString('Hacked', (string) AgencyProspectingSetting::where('workspace_id', $a['workspace']->id)->value('message_1'));
    }

    public function test_a_forged_member_uid_from_another_workspace_is_refused_and_nothing_changes(): void
    {
        $a = $this->outreachAgency('Alpha');
        $b = $this->outreachAgency('Bravo');
        $this->authenticateAs($a['customer']);

        $this->post($this->outreachRoute('conversations.pause', $a['workspace'], [$b['member']->uid]))->assertNotFound();
        $this->post($this->outreachRoute('conversations.resume', $a['workspace'], ['not-a-real-uid']))->assertNotFound();

        $this->assertNull($b['member']->fresh()->ai_paused_at);
    }

    public function test_a_view_as_session_cannot_reach_or_change_outreach(): void
    {
        $a = $this->outreachAgency('Alpha', ['agencyName' => 'Before']);
        $workspace = $a['workspace'];
        $this->authenticateAs($a['customer']);
        $this->startViewAs($workspace, $a['business'])->assertRedirect(route('user.home'));

        $this->post($this->outreachRoute('script.update', $workspace), ['agency_name' => 'During View As'])->assertNotFound();
        $this->get($this->outreachRoute('script.show', $workspace))->assertNotFound();

        $this->assertSame('Before', AgencyProspectingSetting::where('workspace_id', $workspace->id)->value('agency_name'));
    }

    public function test_no_tab_ever_shows_another_agencys_script_prospects_campaigns_or_number(): void
    {
        $a = $this->outreachAgency('Alpha');
        $b = $this->outreachAgency('Bravo');
        $bNumber = (string) \App\Models\BusinessMessagingNumber::query()->latest('id')->value('phone_number');
        $this->authenticateAs($a['customer']);

        foreach (self::TABS as $tab) {
            $html = $this->get($this->outreachRoute($tab, $a['workspace']))->assertOk()->getContent();

            foreach (['Bravo Prospect Co', 'Bravo Campaign', 'unique-script-marker-bravo', 'Bravo Agency', $bNumber, $b['prospect']->phone] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "Agency B's {$secret} leaked into the {$tab} tab.");
            }
        }

        $this->get($this->outreachRoute('script.show', $a['workspace']))->assertSee('unique-script-marker-alpha', false);
        $this->get($this->outreachRoute('prospects.index', $a['workspace']))->assertSee('Alpha Prospect Co');
        $this->get($this->outreachRoute('campaigns.index', $a['workspace']))->assertSee('Alpha Campaign');
    }
}
