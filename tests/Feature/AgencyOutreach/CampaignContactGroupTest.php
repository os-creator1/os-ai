<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Library\AgencyOutreach\OutreachContactGroupBridge;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\Blacklists;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * A campaign's recipients come from a Business-scoped CRM contact group, bridged
 * into Workspace-scoped prospects and memberships. Creating or refreshing a draft
 * never sends anything.
 */
class CampaignContactGroupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private function group($business, string $name = 'Wedding Photography Leads'): ContactGroups
    {
        return ContactGroups::create([
            'customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => $name, 'status' => true,
        ]);
    }

    private function contact(ContactGroups $group, string $phone, string $status = 'subscribe', ?string $first = null): Contacts
    {
        $contact = Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $group->business_id, 'group_id' => $group->id,
            'phone' => $phone, 'status' => $status,
        ]);

        if ($first !== null) {
            $field = $group->contactGroupFields()->where('tag', 'FIRST_NAME')->first();
            DB::table('contacts_custom_field')->insert(['field_id' => $field->id, 'contact_id' => $contact->id, 'value' => $first, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $contact;
    }

    /** 10 contacts: 3 eligible, plus one of every exclusion reason (7). */
    private function mixedGroup(array $a): ContactGroups
    {
        $group = $this->group($a['business']);
        $this->contact($group, '12025550101', 'subscribe', 'Ann');
        $this->contact($group, '+1 (202) 555-0102');
        $this->contact($group, '12025550103');
        $this->contact($group, '12025550104', 'unsubscribe');
        $this->contact($group, '12025550101');          // duplicate of Ann
        $this->contact($group, '12345');                // invalid
        $this->contact($group, '');                     // missing
        $this->contact($group, '12025550105');          // suppressed below
        Blacklists::create(['user_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'number' => '12025550105', 'reason' => 'STOP']);
        AgencyProspect::create(['workspace_id' => $a['workspace']->id, 'company_name' => 'Stopped Co', 'phone' => '12025550106', 'status' => 'stopped']);
        AgencyProspect::create(['workspace_id' => $a['workspace']->id, 'company_name' => 'Booked Co', 'phone' => '12025550107', 'status' => 'booked']);
        $this->contact($group, '12025550106');
        $this->contact($group, '12025550107');

        return $group;
    }

    private function create(array $a, array $extra = [])
    {
        return $this->post($this->outreachRoute('campaigns.managed.store', $a['workspace']), array_merge([
            'name' => 'Spring push', 'opening_message' => 'Hi there', 'group_mode' => 'existing',
        ], $extra));
    }

    private function agencyActor(array $a): void
    {
        $this->authenticateAs($a['customer']);
    }

    public function test_creating_with_an_existing_group_links_it_and_enrolls_eligible_contacts_without_sending(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $group = $this->group($a['business']);
        $this->contact($group, '12025550101', 'subscribe', 'Ann');
        $this->contact($group, '12025550102');
        $this->agencyActor($a);

        $response = $this->create($a, ['contact_group_uid' => $group->uid]);

        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();
        $response->assertRedirect($this->outreachRoute('campaigns.show', $a['workspace'], [$campaign->uid]));
        $this->assertSame('draft', $campaign->status->value);
        $this->assertSame($group->id, (int) $campaign->contact_group_id);
        $this->assertSame($a['business']->id, (int) $campaign->contact_group_business_id);
        $this->assertSame(2, AgencyProspectCampaignMember::where('campaign_id', $campaign->id)->count());
        $this->assertSame(2, AgencyProspect::where('workspace_id', $a['workspace']->id)->count());

        $ann = AgencyProspect::where('phone', '12025550101')->first();
        $this->assertSame('Ann', $ann->contact_name);
        $this->assertSame('Ann', $ann->company_name, 'No company is invented: the contact\'s own name is used.');
        $this->assertSame('Contact group: Wedding Photography Leads', $ann->source);
        $this->assertSame('+12025550102', '+' . AgencyProspect::where('phone', '12025550102')->value('phone'));
        $this->assertSame('+12025550102', AgencyProspect::where('phone', '12025550102')->value('company_name'));

        Queue::assertNotPushed(OutreachInitialSendJob::class);
    }

    public function test_creating_with_a_new_group_makes_a_real_crm_group_for_the_business_and_a_draft_with_no_recipients(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $this->agencyActor($a);

        $this->create($a, ['group_mode' => 'new', 'new_group_name' => 'Fresh Leads'])->assertSessionHasNoErrors();

        $group = ContactGroups::where('name', 'Fresh Leads')->firstOrFail();
        $this->assertSame($a['business']->id, (int) $group->business_id);
        $this->assertSame($a['business']->customer_id, (int) $group->customer_id);
        $this->assertTrue($group->contactGroupFields()->where('tag', 'PHONE')->exists(), 'Created through the normal group store, with default fields.');

        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();
        $this->assertSame($group->id, (int) $campaign->contact_group_id);
        $this->assertSame(0, $campaign->members()->count());
        $this->assertSame(0, $campaign->recipient_report['eligible']);
        Queue::assertNotPushed(OutreachInitialSendJob::class);

        // A duplicate name is refused instead of creating a second group or a campaign.
        $this->create($a, ['name' => 'Second', 'group_mode' => 'new', 'new_group_name' => 'Fresh Leads'])->assertSessionHasErrors('new_group_name');
        $this->assertSame(1, ContactGroups::where('name', 'Fresh Leads')->count());
        $this->assertNull(AgencyProspectCampaign::where('name', 'Second')->first());
    }

    public function test_group_is_required_and_a_missing_selection_creates_nothing(): void
    {
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $this->agencyActor($a);

        $this->post($this->outreachRoute('campaigns.managed.store', $a['workspace']), ['name' => 'X', 'opening_message' => 'Hi'])->assertSessionHasErrors('group_mode');
        $this->create($a, ['contact_group_uid' => ''])->assertSessionHasErrors('contact_group_uid');
        $this->assertNull(AgencyProspectCampaign::where('name', 'Spring push')->first());
    }

    public function test_counts_and_exclusions_are_calculated_not_hardcoded_and_add_up(): void
    {
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $group = $this->mixedGroup($a);
        $this->agencyActor($a);

        $summary = $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace'], ['group' => $group->uid]))->assertOk()->json();

        $this->assertSame(10, $summary['group_total']);
        $this->assertSame(3, $summary['eligible']);
        $this->assertSame(7, $summary['excluded']);
        $this->assertSame(0, AgencyProspectCampaignMember::count(), 'The summary writes nothing.');

        $this->create($a, ['contact_group_uid' => $group->uid]);
        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();

        $this->assertEquals([
            OutreachContactGroupBridge::REASON_UNSUBSCRIBED => 1,
            OutreachContactGroupBridge::REASON_DUPLICATE => 1,
            OutreachContactGroupBridge::REASON_INVALID_PHONE => 1,
            OutreachContactGroupBridge::REASON_MISSING_PHONE => 1,
            OutreachContactGroupBridge::REASON_SUPPRESSED => 1,
            OutreachContactGroupBridge::REASON_STOPPED => 1,
            OutreachContactGroupBridge::REASON_BOOKED => 1,
        ], $campaign->recipient_report['reasons']);

        $this->get($this->outreachRoute('campaigns.show', $a['workspace'], [$campaign->uid]))
            ->assertOk()
            ->assertSee('Wedding Photography Leads')
            ->assertSee('10 contacts in group')
            ->assertSee('3 enrolled recipients')
            ->assertSee('7 of 10 contacts excluded')
            ->assertSee('Opted out / suppressed')
            ->assertSee('Manage this group in Contacts')
            ->assertSee('Refresh recipients');
    }

    public function test_group_contacts_become_real_members_and_stopped_booked_unsubscribed_invalid_are_never_members_or_messaged(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $group = $this->mixedGroup($a);
        $this->agencyActor($a);
        $this->create($a, ['contact_group_uid' => $group->uid]);
        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();

        $enrolled = $campaign->members()->with('prospect')->get()->pluck('prospect.phone')->sort()->values()->all();
        $this->assertSame(['12025550101', '12025550102', '12025550103'], $enrolled);
        $this->assertNotNull($campaign->members()->first()->source_contact_id);

        // Stopped/booked prospects keep their status and are not enrolled.
        $this->assertSame('stopped', AgencyProspect::where('phone', '12025550106')->first()->status->value);
        $this->assertSame('booked', AgencyProspect::where('phone', '12025550107')->first()->status->value);
        foreach (['12025550104', '12025550105', '12025550106', '12025550107', '12345'] as $never) {
            $prospect = AgencyProspect::where('phone', $never)->first();
            $this->assertTrue($prospect === null || ! $campaign->members()->where('prospect_id', $prospect->id)->exists(), "$never must not be a member");
        }

        // Starting only queues the three enrolled members.
        $this->post($this->outreachRoute('campaigns.start', $a['workspace'], [$campaign->uid]))->assertSessionHas('flash_success');
        Queue::assertPushed(OutreachInitialSendJob::class, 3);
    }

    public function test_refresh_and_repeated_creation_never_duplicate_prospects_or_members(): void
    {
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $group = $this->group($a['business']);
        $this->contact($group, '12025550101');
        $this->contact($group, '12025550102');
        $this->agencyActor($a);

        $this->create($a, ['contact_group_uid' => $group->uid]);
        $first = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();
        $refresh = $this->outreachRoute('campaigns.recipients.refresh', $a['workspace'], [$first->uid]);

        $this->post($refresh)->assertSessionHas('flash_success');
        $this->post($refresh)->assertSessionHas('flash_success');
        $this->assertSame(2, $first->members()->count());

        // A second campaign from the same group reuses the same two prospects.
        $this->create($a, ['name' => 'Second', 'contact_group_uid' => $group->uid]);
        $this->assertSame(2, AgencyProspect::where('workspace_id', $a['workspace']->id)->count());

        // Refresh picks up a new contact and drops a removed one, in the draft only.
        $this->contact($group, '12025550103');
        Contacts::where('group_id', $group->id)->where('phone', '12025550102')->delete();
        $this->post($refresh);
        $this->assertSame(['12025550101', '12025550103'], $first->members()->with('prospect')->get()->pluck('prospect.phone')->sort()->values()->all());
    }

    public function test_foreign_business_and_foreign_workspace_groups_are_rejected_and_never_listed(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $b = $this->outreachAgency('Bravo', ['enrolled' => false]);
        $foreign = $this->group($b['business'], 'Bravo Secret List');
        $this->contact($foreign, '12025550199');
        $this->agencyActor($a);

        $this->create($a, ['contact_group_uid' => $foreign->uid])->assertSessionHasErrors('contact_group_uid');
        $this->assertNull(AgencyProspectCampaign::where('name', 'Spring push')->first());
        $this->assertSame(0, AgencyProspect::where('phone', '12025550199')->count());

        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace'], ['group' => $foreign->uid]))->assertNotFound();
        $this->get($this->outreachRoute('campaigns.index', $a['workspace']))->assertOk()->assertDontSee('Bravo Secret List');

        // Draft change to a foreign group is refused and changes nothing.
        $own = $this->group($a['business'], 'Own');
        $this->create($a, ['contact_group_uid' => $own->uid]);
        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();
        $this->post($this->outreachRoute('campaigns.recipients.group', $a['workspace'], [$campaign->uid]), ['contact_group_uid' => $foreign->uid, 'confirm' => 1])
            ->assertSessionHas('flash_error');
        $this->assertSame($own->id, (int) $campaign->fresh()->contact_group_id);

        // And a stored link that points at another Business is not a valid group to start from.
        $campaign->update(['contact_group_id' => $foreign->id]);
        $this->post($this->outreachRoute('campaigns.start', $a['workspace'], [$campaign->uid]))
            ->assertSessionHas('flash_error', 'Choose a valid contact group for this campaign before starting it.');
        Queue::assertNotPushed(OutreachInitialSendJob::class);
    }

    public function test_group_can_change_only_while_draft_and_keeps_individual_enrollments(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $one = $this->group($a['business'], 'One');
        $two = $this->group($a['business'], 'Two');
        $this->contact($one, '12025550101');
        $this->contact($two, '12025550102');
        $manual = AgencyProspect::create(['workspace_id' => $a['workspace']->id, 'company_name' => 'Manual Co', 'phone' => '12025550150', 'status' => 'active']);
        $this->agencyActor($a);
        $this->create($a, ['contact_group_uid' => $one->uid]);
        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();
        AgencyProspectCampaignMember::create(['workspace_id' => $a['workspace']->id, 'campaign_id' => $campaign->id, 'prospect_id' => $manual->id, 'enrolled_at' => now()]);
        $route = $this->outreachRoute('campaigns.recipients.group', $a['workspace'], [$campaign->uid]);

        $this->post($route, ['contact_group_uid' => $two->uid])->assertSessionHasErrors('confirm');
        $this->assertSame($one->id, (int) $campaign->fresh()->contact_group_id);

        $this->post($route, ['contact_group_uid' => $two->uid, 'confirm' => 1])->assertSessionHas('flash_success');
        $this->assertSame($two->id, (int) $campaign->fresh()->contact_group_id);
        $this->assertSame(['12025550102', '12025550150'], $campaign->members()->with('prospect')->get()->pluck('prospect.phone')->sort()->values()->all());

        // Once started, the group cannot change and refresh is refused.
        $this->post($this->outreachRoute('campaigns.start', $a['workspace'], [$campaign->uid]))->assertSessionHas('flash_success');
        $this->post($route, ['contact_group_uid' => $one->uid, 'confirm' => 1])->assertSessionHas('flash_error');
        $this->assertSame($two->id, (int) $campaign->fresh()->contact_group_id);
    }

    public function test_started_campaign_recipients_are_frozen_against_group_changes(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $group = $this->group($a['business']);
        $this->contact($group, '12025550101');
        $this->agencyActor($a);
        $this->create($a, ['contact_group_uid' => $group->uid]);
        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();
        $this->post($this->outreachRoute('campaigns.start', $a['workspace'], [$campaign->uid]));
        $this->assertSame('active', $campaign->fresh()->status->value);

        $this->contact($group, '12025550102');
        $this->post($this->outreachRoute('campaigns.recipients.refresh', $a['workspace'], [$campaign->uid]))->assertSessionHas('flash_error');
        $this->assertSame(1, $campaign->members()->count());
        $this->get($this->outreachRoute('campaigns.show', $a['workspace'], [$campaign->uid]))->assertOk()->assertSee('frozen')->assertDontSee('Refresh recipients');
    }

    public function test_start_requires_a_valid_group_and_an_eligible_recipient_and_keeps_readiness_checks(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $empty = $this->group($a['business'], 'Empty');
        $this->agencyActor($a);
        $this->create($a, ['contact_group_uid' => $empty->uid]);
        $campaign = AgencyProspectCampaign::where('name', 'Spring push')->firstOrFail();
        $start = $this->outreachRoute('campaigns.start', $a['workspace'], [$campaign->uid]);

        // Empty group: no enrolled recipient.
        $this->post($start)->assertSessionHas('flash_error', 'Add at least one prospect and put them in a campaign.');

        // Group deleted: not a valid group.
        $empty->delete();
        $this->post($start)->assertSessionHas('flash_error', 'Choose a valid contact group for this campaign before starting it.');

        // Readiness still gates a good group.
        $good = $this->group($a['business'], 'Good');
        $this->contact($good, '12025550101');
        $campaign->update(['contact_group_id' => $good->id]);
        DB::table('businesses')->where('id', $a['business']->id)->update(['status' => 'inactive']);
        $this->post($start)->assertSessionHas('flash_error');
        $this->assertSame('draft', $campaign->fresh()->status->value);
        Queue::assertNotPushed(OutreachInitialSendJob::class);
    }

    public function test_legacy_campaigns_without_a_group_still_start_and_enroll_individually(): void
    {
        Queue::fake();
        $a = $this->outreachAgency('Alpha');   // legacy fixture: managed, no recipient_source, one enrolled prospect
        $this->agencyActor($a);

        $this->assertNull($a['campaign']->recipient_source);
        $this->get($this->outreachRoute('campaigns.show', $a['workspace'], [$a['campaign']->uid]))->assertOk()->assertSee('created before contact groups');
        $this->post($this->outreachRoute('campaigns.start', $a['workspace'], [$a['campaign']->uid]))->assertSessionHas('flash_success');
        Queue::assertPushed(OutreachInitialSendJob::class, 1);
    }

    public function test_oversized_groups_are_refused_cleanly(): void
    {
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $group = $this->group($a['business']);
        $rows = [];
        for ($i = 0; $i <= OutreachContactGroupBridge::MAX_CONTACTS; $i++) {
            $rows[] = ['uid' => uniqid('', true), 'customer_id' => $group->customer_id, 'business_id' => $group->business_id, 'group_id' => $group->id, 'phone' => '1202555' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'status' => 'subscribe', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('contacts')->insert($chunk);
        }
        $this->agencyActor($a);

        $this->create($a, ['contact_group_uid' => $group->uid])->assertSessionHasErrors('contact_group_uid');
        $this->assertNull(AgencyProspectCampaign::where('name', 'Spring push')->first());
    }

    public function test_staff_and_other_workspaces_cannot_use_the_new_endpoints(): void
    {
        $a = $this->outreachAgency('Alpha', ['enrolled' => false]);
        $b = $this->outreachAgency('Bravo', ['enrolled' => false]);
        $group = $this->group($a['business']);
        $this->authenticateAs($b['customer']);

        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace'], ['group' => $group->uid]))->assertNotFound();
        $this->post($this->outreachRoute('campaigns.recipients.refresh', $a['workspace'], [$a['campaign']->uid]))->assertNotFound();
        $this->post($this->outreachRoute('campaigns.recipients.group', $a['workspace'], [$a['campaign']->uid]), ['contact_group_uid' => $group->uid, 'confirm' => 1])->assertNotFound();
    }
}
