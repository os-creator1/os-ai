<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Library\AgencyOutreach\OutreachEligibility;
use App\Library\AgencyOutreach\OutreachExistingClients;
use App\Library\Workspace\WorkspaceManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectExclusion;
use App\Models\AgencyProspectMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

class OutreachExistingClientsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private function clients(): OutreachExistingClients
    {
        return app(OutreachExistingClients::class);
    }

    /** The fixture's random digits are not a valid international number; use a real-shaped one. */
    private function realPhone(array $a, string $phone = '13035550177'): array
    {
        $a['prospect']->update(['phone' => $phone]);
        $a['prospect']->refresh();

        return $a;
    }

    private function active(array $a): AgencyProspectCampaign
    {
        $a['campaign']->update(['status' => 'active']);

        return $a['campaign']->refresh();
    }

    public function test_adding_a_client_canonicalises_the_number_and_is_idempotent(): void
    {
        $a = $this->outreachAgency('Canon');
        $this->authenticateAs($a['customer']);

        $this->post($this->outreachRoute('prospects.clients.store', $a['workspace']), [
            'business_name' => 'Acme Photo', 'phone' => '(303) 555-0177', 'country_code' => '+1',
        ])->assertRedirect($this->outreachRoute('prospects.clients', $a['workspace']));

        $this->post($this->outreachRoute('prospects.clients.store', $a['workspace']), [
            'business_name' => 'Acme Photo again', 'phone' => '+1 303 555 0177',
        ])->assertRedirect();

        $this->assertSame(1, AgencyProspectExclusion::where('workspace_id', $a['workspace']->id)->count());
        $this->assertDatabaseHas('agency_prospect_exclusions', ['workspace_id' => $a['workspace']->id, 'phone' => '13035550177', 'active_phone' => '13035550177']);

        $this->get($this->outreachRoute('prospects.clients', $a['workspace']))
            ->assertOk()->assertSee('Acme Photo')->assertSee('(303) 555-0177');

        $this->post($this->outreachRoute('prospects.clients.store', $a['workspace']), ['business_name' => 'Bad', 'phone' => '12'])
            ->assertSessionHasErrors('phone');
    }

    public function test_exclusion_blocks_import_enrollment_start_and_every_automatic_send(): void
    {
        Queue::fake();
        $a = $this->realPhone($this->outreachAgency('Block'));
        $this->authenticateAs($a['customer']);
        $ws = $a['workspace'];

        $this->clients()->add($ws, '+' . $a['prospect']->phone, 'Block Co', 'manual', 'test', null);

        // Send-time check (opener, reply, follow-up and deferred / resumed sends all go through this).
        $campaign = $this->active($a);
        $result = app(OutreachEligibility::class)->check($a['member']->refresh(), false);
        $this->assertFalse($result->ok());
        $this->assertSame('existing_client', $result->reason);

        // Manual add of the same number is refused.
        $this->post($this->outreachRoute('prospects.store', $ws), ['company_name' => 'Dup', 'phone' => '+' . $a['prospect']->phone])
            ->assertSessionHasErrors('phone');

        // Enrolling an existing prospect that is a client is refused.
        $draft = AgencyProspectCampaign::create(['workspace_id' => $ws->id, 'name' => 'Second', 'status' => 'draft', 'sending_mode' => 'managed', 'opening_message' => 'Hi']);
        $this->post($this->outreachRoute('campaigns.members.store', $ws, [$draft->uid]), ['prospect_uid' => $a['prospect']->uid])
            ->assertSessionHas('flash_error');
        $this->assertSame(0, AgencyProspectCampaignMember::where('campaign_id', $draft->id)->count());
    }

    public function test_removing_an_exclusion_makes_the_number_prospectable_again(): void
    {
        $a = $this->realPhone($this->outreachAgency('Lift'));
        $this->authenticateAs($a['customer']);
        $added = $this->clients()->add($a['workspace'], '+' . $a['prospect']->phone, 'Lift Co', 'manual', 't', (int) auth()->id());

        $this->post($this->outreachRoute('prospects.clients.remove', $a['workspace'], [$added['exclusion']->uid]))->assertRedirect();

        $row = $added['exclusion']->refresh();
        $this->assertNotNull($row->removed_at);
        $this->assertNull($row->active_phone);
        $this->assertSame((int) auth()->id(), (int) $row->removed_by_user_id);
        $this->assertFalse($this->clients()->isExcluded($a['workspace']->id, $a['prospect']->phone));
    }

    public function test_stop_cannot_be_reversed_through_client_exclusion_management(): void
    {
        $a = $this->realPhone($this->outreachAgency('Stop'));
        $this->authenticateAs($a['customer']);
        $a['prospect']->update(['status' => AgencyProspectStatus::Stopped->value, 'stop_reason' => AgencyProspect::STOP_OPT_OUT, 'stopped_at' => now()]);
        $this->active($a);

        $added = $this->clients()->add($a['workspace'], '+' . $a['prospect']->phone, 'Stop Co', 'manual', 't', (int) auth()->id());
        $this->post($this->outreachRoute('prospects.clients.remove', $a['workspace'], [$added['exclusion']->uid]))->assertRedirect();

        $prospect = $a['prospect']->refresh();
        $this->assertSame(AgencyProspectStatus::Stopped, $prospect->status);
        $this->assertSame(AgencyProspect::STOP_OPT_OUT, $prospect->stop_reason);

        $result = app(OutreachEligibility::class)->check($a['member']->refresh(), false);
        $this->assertFalse($result->ok(), 'A STOP stays a STOP after the client exclusion is removed.');

        // The Excluded tab is read-only: it lists them and offers no way to reverse the opt-out.
        $this->get($this->outreachRoute('prospects.excluded', $a['workspace']))->assertOk()->assertSee($prospect->company_name)->assertDontSee('prospects.clients.remove');
    }

    public function test_client_won_is_counted_once_across_campaigns_and_suppresses_unsent_messages(): void
    {
        $a = $this->realPhone($this->outreachAgency('Won'));
        $this->authenticateAs($a['customer']);
        $ws = $a['workspace'];
        $this->active($a);

        $second = AgencyProspectCampaign::create(['workspace_id' => $ws->id, 'name' => 'Won second', 'status' => 'active', 'sending_mode' => 'managed', 'opening_message' => 'Hi']);
        $m2 = AgencyProspectCampaignMember::create(['workspace_id' => $ws->id, 'campaign_id' => $second->id, 'prospect_id' => $a['prospect']->id, 'enrolled_at' => now()]);

        $sent = AgencyProspectMessage::create(['workspace_id' => $ws->id, 'campaign_member_id' => $a['member']->id, 'direction' => 'outbound', 'status' => 'sent', 'body' => 'Hello', 'purpose' => 'initial', 'sent_at' => now()->subDay()]);
        $held = AgencyProspectMessage::create(['workspace_id' => $ws->id, 'campaign_member_id' => $a['member']->id, 'direction' => 'outbound', 'status' => 'paused', 'body' => 'Follow up', 'purpose' => 'followup', 'failure_reason' => 'rate_limited']);

        $this->post($this->outreachRoute('prospects.client-won', $ws, [$a['prospect']->uid]))->assertRedirect();
        $this->post($this->outreachRoute('prospects.client-won', $ws, [$a['prospect']->uid]))->assertRedirect();

        $prospect = $a['prospect']->refresh();
        $this->assertNotNull($prospect->client_won_at);
        $this->assertSame((int) $a['campaign']->id, (int) $prospect->acquisition_campaign_id, 'attribution points at the campaign with the conversation');
        $this->assertSame(1, AgencyProspect::where('workspace_id', $ws->id)->whereNotNull('client_won_at')->count());
        $this->assertSame(1, AgencyProspectExclusion::where('workspace_id', $ws->id)->whereNull('removed_at')->count());

        // Unsent message is suppressed; history (the sent one) is untouched.
        $this->assertSame('failed', $held->refresh()->status);
        $this->assertSame('existing_client', $held->failure_reason);
        $this->assertSame('sent', $sent->refresh()->status);
        $this->assertSame(2, AgencyProspectCampaignMember::where('prospect_id', $prospect->id)->count());

        // Future sends from ANY campaign are refused.
        $this->assertSame('existing_client', app(OutreachEligibility::class)->check($m2->refresh(), false)->reason ?? null);
    }

    public function test_cross_workspace_exclusions_cannot_be_read_or_removed(): void
    {
        $a = $this->outreachAgency('Alpha2');
        $b = $this->outreachAgency('Bravo2');
        $added = $this->clients()->add($a['workspace'], '+15035550999', 'A only', 'manual', 't', null);

        $this->authenticateAs($b['customer']);
        $this->post($this->outreachRoute('prospects.clients.remove', $b['workspace'], [$added['exclusion']->uid]))->assertNotFound();
        $this->get($this->outreachRoute('prospects.clients', $b['workspace']))->assertOk()->assertDontSee('A only');
        // A's workspace uid is a 404 for B altogether.
        $this->get($this->outreachRoute('prospects.clients', $a['workspace']))->assertNotFound();
        $this->assertFalse($this->clients()->isExcluded($b['workspace']->id, '15035550999'));
    }

    public function test_only_the_owner_or_the_adder_may_remove_an_exclusion(): void
    {
        $a = $this->outreachAgency('Perm');
        $added = $this->clients()->add($a['workspace'], '+15035550123', 'P', 'manual', 't', 999999);

        $this->assertTrue($this->clients()->mayRemove($a['workspace'], $added['exclusion'], (int) $a['workspace']->owner_user_id));
        $this->assertTrue($this->clients()->mayRemove($a['workspace'], $added['exclusion'], 999999));
        $this->assertFalse($this->clients()->mayRemove($a['workspace'], $added['exclusion'], 123456));
    }
}
