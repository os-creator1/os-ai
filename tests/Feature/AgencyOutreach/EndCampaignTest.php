<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Listeners\Outreach\HandleOutreachInboundMessage;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/** A real campaign can be ended without falsifying STOP/booked or deleting SMS history. */
class EndCampaignTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private function anotherDraft(array $agency): AgencyProspectCampaign
    {
        $campaign = AgencyProspectCampaign::create([
            'workspace_id' => $agency['workspace']->id,
            'name' => 'Second test',
            'status' => 'draft',
            'sending_mode' => AgencyProspectCampaign::MODE_MANAGED,
            'opening_message' => 'New introduction',
        ]);

        AgencyProspectCampaignMember::create([
            'workspace_id' => $agency['workspace']->id,
            'campaign_id' => $campaign->id,
            'prospect_id' => $agency['prospect']->id,
            'enrolled_at' => now(),
        ]);

        return $campaign;
    }

    public function test_end_active_campaign_frees_contact_for_a_new_campaign_and_preserves_history(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);

        $old = $a['campaign'];
        $old->update(['status' => 'active']);
        $member = $a['member'];

        $message = AgencyProspectMessage::create([
            'workspace_id' => $a['workspace']->id,
            'campaign_member_id' => $member->id,
            'direction' => 'outbound',
            'operation_key' => 'outreach:opener:'.$member->id,
            'body' => 'Previous conversation',
            'purpose' => AgencyProspectMessage::PURPOSE_INITIAL,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $next = $this->anotherDraft($a);
        $start = $this->outreachRoute('campaigns.start', $a['workspace'], [$next->uid]);
        $end = $this->outreachRoute('campaigns.end', $a['workspace'], [$old->uid]);

        $this->get($this->outreachRoute('campaigns.show', $a['workspace'], [$old->uid]))
            ->assertOk()->assertSee('End campaign');

        $this->post($start)->assertSessionHas('flash_error', 'One or more prospects already have an open conversation in another active campaign.');

        $this->post($end)->assertSessionHas('flash_success');
        $this->assertSame('ended', $old->fresh()->status->value);
        $this->assertSame('active', $a['prospect']->fresh()->status->value);
        $this->assertNotNull($member->fresh());
        $this->assertNotNull($message->fresh());
        $this->assertSame('Previous conversation', $message->fresh()->body);

        $this->get($this->outreachRoute('campaigns.show', $a['workspace'], [$old->uid]))
            ->assertOk()->assertSee('has ended permanently')->assertDontSee('Resume campaign');

        $this->post($start)->assertSessionHas('flash_success');
        $this->assertSame('active', $next->fresh()->status->value);
        Queue::assertPushed(OutreachInitialSendJob::class, 1);
    }

    public function test_end_is_irreversible_idempotent_and_refuses_drafts(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);
        $old = $a['campaign'];
        $end = $this->outreachRoute('campaigns.end', $a['workspace'], [$old->uid]);

        $this->post($end)->assertSessionHas('flash_error');
        $this->assertSame('draft', $old->fresh()->status->value);

        $old->update(['status' => 'paused']);
        $this->post($end)->assertSessionHas('flash_success');
        $this->post($end)->assertSessionHas('flash_success', 'Campaign already ended.');

        $this->assertSame('ended', $old->fresh()->status->value);
        $this->post($this->outreachRoute('campaigns.status', $a['workspace'], [$old->uid]), ['status' => 'active'])
            ->assertSessionHas('flash_error');
        $this->assertSame('ended', $old->fresh()->status->value);
        Queue::assertNotPushed(OutreachInitialSendJob::class);
    }

    public function test_ended_campaign_is_excluded_from_inbound_member_selection(): void
    {
        $a = $this->outreachAgency('Alpha');
        $old = $a['campaign'];
        $old->update(['status' => 'ended']);

        // Assert the exact recipient selection method, independent of the
        // canonical ChatBox fixture's sender-number matching.
        $listener = app(HandleOutreachInboundMessage::class);
        $select = new \ReflectionMethod(HandleOutreachInboundMessage::class, 'liveManagedMember');

        $this->assertNull($select->invoke($listener, $a['prospect']));

        $new = $this->anotherDraft($a);
        $new->update(['status' => 'active']);
        $newMember = $new->members()->sole();

        $selected = $select->invoke($listener, $a['prospect']);
        $this->assertNotNull($selected);
        $this->assertSame($newMember->id, $selected->id);
        $this->assertNotSame($a['member']->id, $selected->id);
    }

    public function test_another_workspace_cannot_end_this_campaign(): void
    {
        $a = $this->outreachAgency('Alpha');
        $other = $this->outreachAgency('Other');
        $a['campaign']->update(['status' => 'active']);
        $this->authenticateAs($other['customer']);

        $this->post($this->outreachRoute('campaigns.end', $a['workspace'], [$a['campaign']->uid]))
            ->assertStatus(404);
        $this->assertSame('active', $a['campaign']->fresh()->status->value);
    }
}
