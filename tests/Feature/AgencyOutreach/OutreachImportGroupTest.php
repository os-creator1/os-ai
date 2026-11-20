<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Library\AgencyOutreach\Import\ProspectGroupMaterializer;
use App\Library\CustomFields\CustomFieldDefinitionManager;
use App\Library\CustomFields\CustomFieldValueService;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectGroupLink;
use App\Models\AgencyProspectImportBatch;
use App\Models\AgencyProspectMessage;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\CustomFieldDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/** One import → one Contact Group with real Custom Field values, reusable by campaigns and analytics. */
class OutreachImportGroupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    private const HEAD = 'name,phone,primary category,rating,review amount,address,link,lcr4fd href,website or no website,"hours, opening hours"';

    private function csv(int $rows, int $startPhone = 3000000, string $type = 'Photo booth'): string
    {
        $out = [self::HEAD];

        for ($i = 0; $i < $rows; $i++) {
            $n = $startPhone + $i;
            $out[] = "Business {$n},+1213{$n},{$type}," . (3 + ($i % 20) / 10) . ',' . (10 + $i) . ',"1 Main St, Dallas, TX 75201",https://www.google.com/maps/place/' . $n . ',' . ($i % 2 ? 'https://site' . $n . '.example.com' : '') . ',' . ($i % 2 ? 'Yes' : 'No') . ',Open 9am-5pm';
        }

        return implode("\n", $out) . "\n";
    }

    private function upload(array $a, string $csv, string $name = 'Dallas Photographers.csv')
    {
        $this->post($this->outreachRoute('prospects.import.upload', $a['workspace']), ['kind' => 'prospects', 'file' => UploadedFile::fake()->createWithContent($name, $csv)])->assertRedirect();

        return AgencyProspectImportBatch::where('workspace_id', $a['workspace']->id)->latest('id')->firstOrFail();
    }

    private function commit(array $a, AgencyProspectImportBatch $batch, string $group)
    {
        return $this->post($this->outreachRoute('prospects.import.commit', $a['workspace'], [$batch->uid]), ['group_name' => $group]);
    }

    private function bizGroups(array $a)
    {
        return ContactGroups::where('business_id', $a['business']->id);
    }

    public function test_one_300_row_csv_creates_one_group_with_real_custom_field_values_and_never_sends(): void
    {
        $a = $this->outreachAgency('Dallas', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $groupsBefore = $this->bizGroups($a)->count();
        $batch = $this->upload($a, $this->csv(300));
        Queue::fake();
        Bus::fake();
        $messages = AgencyProspectMessage::count();

        // Review shows the group name defaulting to the filename and the Custom Fields that will be created.
        $this->get($this->outreachRoute('prospects.import.review', $a['workspace'], [$batch->uid]))
            ->assertOk()->assertSee('value="Dallas Photographers"', false)->assertSee('data-role="field-plan"', false)->assertSee('Google rating')->assertSee('Will create');

        $this->commit($a, $batch, 'Dallas Photographers')->assertRedirect($this->outreachRoute('prospects.import.done', $a['workspace'], [$batch->uid]));

        $this->assertSame($groupsBefore + 1, $this->bizGroups($a)->count(), 'one group, not 300');
        $group = $this->bizGroups($a)->where('name', 'Dallas Photographers')->firstOrFail();
        $this->assertTrue((bool) $group->status);
        $this->assertSame(300, Contacts::where('group_id', $group->id)->count());
        $this->assertSame(300, AgencyProspect::where('workspace_id', $a['workspace']->id)->count(), 'canonical prospects stay');
        $this->assertSame(300, AgencyProspectGroupLink::where('contact_group_id', $group->id)->count());
        $this->assertSame($group->id, (int) $batch->refresh()->contact_group_id);
        $this->assertSame($messages, AgencyProspectMessage::count());
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();

        // Enrichment is stored as REAL Business Custom Fields with their types.
        $defs = CustomFieldDefinition::where('business_id', $a['business']->id)->get()->keyBy('label');
        $this->assertSame('number', $defs['Google rating']->type);
        $this->assertSame('number', $defs['Review count']->type);
        $this->assertSame('boolean', $defs['Has website']->type);
        $this->assertSame('text', $defs['Business type']->type);
        $this->assertSame('long_text', $defs['Opening hours']->type);

        $contact = Contacts::where('group_id', $group->id)->where('phone', '12133000001')->firstOrFail();
        $values = collect(app(CustomFieldValueService::class)->valuesFor($a['business'], $contact))->mapWithKeys(fn ($v) => [$v['definition']->label => $v['value']]);
        $this->assertSame('Photo booth', $values['Business type']);
        $this->assertSame('3.1', (string) $values['Google rating']);
        $this->assertSame(11, (int) $values['Review count']);
        $this->assertTrue($values['Has website']);
        $this->assertSame('1 Main St, Dallas, TX 75201', $values['Address']);
        $this->assertSame('Dallas', $values['City']);
        $this->assertSame('TX', $values['State']);

        // Built-in identity stays in its canonical storage.
        $company = DB::table('contacts_custom_field as c')->join('contact_group_fields as f', 'f.id', '=', 'c.field_id')
            ->where('c.contact_id', $contact->id)->where('f.tag', 'COMPANY')->value('c.value');
        $this->assertSame('Business 3000001', $company);

        // Visible and editable in the Contact profile under Custom Fields.
        $this->get(route('customer.workspaces.businesses.people.show', [$a['workspace']->uid, $a['business']->uid, $contact->uid]))
            ->assertOk()->assertSee('Photo booth')->assertSee('Google rating');

        // The done view links to the group and to a campaign with the group preselected.
        $this->get($this->outreachRoute('prospects.import.done', $a['workspace'], [$batch->uid]))
            ->assertOk()->assertSee('Dallas Photographers')->assertSee('data-role="new-campaign-from-group"', false)->assertSee('group=' . $group->uid, false);
    }

    public function test_the_group_is_in_contacts_and_the_campaign_picker_with_a_real_eligible_count(): void
    {
        $a = $this->outreachAgency('Pick', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $batch = $this->upload($a, $this->csv(12));
        $this->commit($a, $batch, 'Dallas Photographers');
        $group = $this->bizGroups($a)->where('name', 'Dallas Photographers')->firstOrFail();

        // Contacts → Groups lists it.
        $this->get(route('customer.workspaces.businesses.contacts.index', [$a['workspace']->uid, $a['business']->uid]))->assertOk()->assertSee('Dallas Photographers');

        // New campaign: the group is selectable (and preselected from the import's link).
        $page = $this->get($this->outreachRoute('campaigns.index', $a['workspace']) . '?new=1&group=' . $group->uid)->assertOk();
        $page->assertSee('Dallas Photographers');
        $this->assertMatchesRegularExpression('/value="' . $group->uid . '"\s+checked/', $page->getContent());

        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $group->uid)
            ->assertOk()->assertJsonPath('group_total', 12)->assertJsonPath('eligible', 12)->assertJsonPath('excluded', 0);

        // Create a DRAFT campaign from it and review recipients before starting.
        $this->post($this->outreachRoute('campaigns.managed.store', $a['workspace']), [
            'name' => 'Dallas outreach', 'opening_message' => 'Hi {{prospect.first_name}}', 'group_mode' => 'existing', 'contact_group_uid' => $group->uid,
        ])->assertRedirect();
        $campaign = \App\Models\AgencyProspectCampaign::where('workspace_id', $a['workspace']->id)->where('name', 'Dallas outreach')->firstOrFail();
        $this->assertSame('draft', $campaign->status->value);
        $this->assertSame(12, $campaign->members()->count());
        $this->assertSame(0, AgencyProspectMessage::where('workspace_id', $a['workspace']->id)->count(), 'a draft sends nothing');
    }

    public function test_the_same_phone_in_two_imports_stays_one_prospect_and_both_groups_reference_it(): void
    {
        $a = $this->outreachAgency('Two', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $this->commit($a, $this->upload($a, $this->csv(5)), 'List A');
        $this->commit($a, $this->upload($a, $this->csv(5, 3000003), 'b.csv'), 'List B');   // 2 numbers overlap (…003, …004)

        $ws = $a['workspace']->id;
        $this->assertSame(8, AgencyProspect::where('workspace_id', $ws)->count(), 'one prospect per canonical phone');
        $groupB = $this->bizGroups($a)->where('name', 'List B')->firstOrFail();
        $groupA = $this->bizGroups($a)->where('name', 'List A')->firstOrFail();
        $this->assertSame(3, Contacts::where('group_id', $groupB->id)->count(), 'contacts are never moved out of List A');
        $this->assertSame(5, Contacts::where('group_id', $groupA->id)->count());
        $this->assertSame(5, AgencyProspectGroupLink::where('contact_group_id', $groupB->id)->count(), 'but List B references all five logical prospects');
        $this->assertSame(2, AgencyProspectGroupLink::where('contact_group_id', $groupB->id)->where('contact_in_group', false)->count());
        $this->assertSame(1, AgencyProspectGroupLink::where('contact_group_id', $groupA->id)->where('prospect_id', AgencyProspect::where('phone', '12133000003')->value('id'))->count());
    }

    public function test_retry_and_repeat_are_idempotent(): void
    {
        $a = $this->outreachAgency('Retry', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $batch = $this->upload($a, $this->csv(20));
        $this->commit($a, $batch, 'Once');
        $this->commit($a, $batch, 'Once');
        $this->commit($a, $batch, 'Different name now');

        $this->assertSame(1, $this->bizGroups($a)->where('name', 'Once')->count());
        $this->assertSame(0, $this->bizGroups($a)->where('name', 'Different name now')->count(), 'a repeat never creates a second group');
        $group = $this->bizGroups($a)->where('name', 'Once')->firstOrFail();
        $this->assertSame(20, Contacts::where('group_id', $group->id)->count());
        $this->assertSame(20, AgencyProspectGroupLink::where('contact_group_id', $group->id)->count());

        // The materialiser itself (a crashed-and-retried commit, or two workers) is repeatable too.
        app(ProspectGroupMaterializer::class)->materialize($a['workspace'], $a['business'], $batch->fresh(), 'Once');
        app(ProspectGroupMaterializer::class)->materialize($a['workspace'], $a['business'], $batch->fresh(), 'Once');
        $this->assertSame(20, Contacts::where('group_id', $group->id)->count());
        $this->assertSame(1, $this->bizGroups($a)->where('name', 'Once')->count());
    }

    public function test_stop_and_existing_clients_never_get_a_contact_or_are_reactivated(): void
    {
        $a = $this->outreachAgency('Safe', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $ws = $a['workspace'];
        $stopped = AgencyProspect::create(['workspace_id' => $ws->id, 'company_name' => 'Optout', 'phone' => '12133000001', 'status' => 'stopped', 'stop_reason' => 'opt_out', 'stopped_at' => now()]);
        app(\App\Library\AgencyOutreach\OutreachExistingClients::class)->add($ws, '+12133000002', 'Client Co', 'manual', 't', null);

        $batch = $this->upload($a, $this->csv(4));
        $this->commit($a, $batch, 'Safe list');
        $group = $this->bizGroups($a)->where('name', 'Safe list')->firstOrFail();

        $this->assertSame(2, Contacts::where('group_id', $group->id)->count(), 'only the two ordinary prospects');
        $this->assertDatabaseMissing('contacts', ['business_id' => $a['business']->id, 'phone' => '12133000001']);
        $this->assertDatabaseMissing('contacts', ['business_id' => $a['business']->id, 'phone' => '12133000002']);
        $this->assertSame(AgencyProspectStatus::Stopped, $stopped->refresh()->status);

        // Direct (backfill-style) materialisation of a batch that contains them also skips them.
        $report = app(ProspectGroupMaterializer::class)->materialize($ws, $a['business'], $batch->fresh(), 'Safe list');
        $this->assertSame(0, $report['created']);
        $this->assertSame(2, Contacts::where('group_id', $group->id)->count());
    }

    public function test_existing_nonblank_values_are_never_overwritten_and_archived_or_conflicting_fields_are_skipped(): void
    {
        $a = $this->outreachAgency('Fields', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $defs = app(CustomFieldDefinitionManager::class);
        $values = app(CustomFieldValueService::class);

        $type = $defs->create($a['business'], 'Business type', 'text');
        $rating = $defs->create($a['business'], 'Google rating', 'number');
        $city = $defs->create($a['business'], 'City', 'text');
        $defs->archive($a['business'], $city);
        $defs->create($a['business'], 'Opening hours', 'boolean');      // same name, incompatible type

        // A contact of this Business already owns the number, in ANOTHER group, with a hand-entered value.
        $other = ContactGroups::create(['customer_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'name' => 'Old list', 'status' => true]);
        $existing = Contacts::create(['customer_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'group_id' => $other->id, 'phone' => '12133000001', 'status' => 'subscribe']);
        $values->set($a['business'], $existing, $type, 'Hand typed');

        $batch = $this->upload($a, $this->csv(3));
        $plan = collect(app(ProspectGroupMaterializer::class)->plan($a['business'], $batch))->keyBy('label');
        $this->assertSame('reuse', $plan['Business type']['action']);
        $this->assertSame('archived', $plan['City']['action']);
        $this->assertSame('conflict', $plan['Opening hours']['action']);
        $this->assertSame('create', $plan['Address']['action']);

        $this->commit($a, $batch, 'Fields list');

        $this->assertSame(1, CustomFieldDefinition::where('business_id', $a['business']->id)->where('label', 'Business type')->count(), 'matching definition reused, no duplicate');
        $this->assertSame(1, Contacts::where('business_id', $a['business']->id)->where('phone', '12133000001')->count(), 'no second contact for that number');
        $this->assertSame((int) $other->id, (int) $existing->refresh()->group_id, 'not moved out of its group');
        $got = collect($values->valuesFor($a['business'], $existing))->mapWithKeys(fn ($v) => [$v['definition']->label => $v['value']]);
        $this->assertSame('Hand typed', $got['Business type'], 'non-blank value kept');
        $this->assertSame('3.1', (string) $got['Google rating'], 'blank value filled');
        $this->assertArrayNotHasKey('City', $got->all(), 'archived field skipped');
        $this->assertArrayNotHasKey('Opening hours', $got->all());
    }

    public function test_extra_columns_can_become_custom_fields_or_be_ignored_and_the_plan_says_so(): void
    {
        $a = $this->outreachAgency('Extra', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $csv = "name,phone,Lead score,Internal note\nAcme,+1 213 300 0001,87,secret\nBeta,+1 213 300 0002,42,hidden\n";
        $batch = $this->upload($a, $csv);
        $headers = (array) $batch->headers;

        $this->post($this->outreachRoute('prospects.import.map', $a['workspace'], [$batch->uid]), ['mapping' => [
            array_search('name', $headers) => 'business_name', array_search('phone', $headers) => 'phone',
            array_search('Lead score', $headers) => 'cf_new', array_search('Internal note', $headers) => '',
        ]])->assertRedirect();

        $this->get($this->outreachRoute('prospects.import.review', $a['workspace'], [$batch->uid]))->assertOk()->assertSee('Lead score')->assertSee('Will create');
        $this->commit($a, $batch->fresh(), 'Extras');

        $def = CustomFieldDefinition::where('business_id', $a['business']->id)->where('label', 'Lead score')->firstOrFail();
        $this->assertNull(CustomFieldDefinition::where('business_id', $a['business']->id)->where('label', 'Internal note')->first(), 'ignored column creates nothing');
        $contact = Contacts::where('business_id', $a['business']->id)->where('phone', '12133000001')->firstOrFail();
        $this->assertDatabaseHas('custom_field_values', ['definition_id' => $def->id, 'contact_id' => $contact->id, 'value_text' => '87']);
    }

    public function test_a_blank_or_duplicate_group_name_is_rejected_and_nothing_is_written(): void
    {
        $a = $this->outreachAgency('Name', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        ContactGroups::create(['customer_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'name' => 'Taken', 'status' => true]);
        $batch = $this->upload($a, $this->csv(3));

        $this->post($this->outreachRoute('prospects.import.commit', $a['workspace'], [$batch->uid]), ['group_name' => ''])->assertSessionHasErrors('group_name');
        $this->commit($a, $batch, 'Taken')->assertSessionHasErrors('group_name');
        $this->assertSame('analyzed', $batch->refresh()->status);
        $this->assertSame(0, AgencyProspect::where('workspace_id', $a['workspace']->id)->count());
    }

    public function test_another_workspace_cannot_commit_or_read_the_import(): void
    {
        $a = $this->outreachAgency('IsoA', ['enrolled' => false]);
        $b = $this->outreachAgency('IsoB', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $batch = $this->upload($a, $this->csv(3));
        $this->commit($a, $batch, 'A list');
        $groupA = $this->bizGroups($a)->where('name', 'A list')->firstOrFail();

        $this->authenticateAs($b['customer']);
        $this->post($this->outreachRoute('prospects.import.commit', $b['workspace'], [$batch->uid]), ['group_name' => 'Stolen'])->assertNotFound();
        $this->get($this->outreachRoute('prospects.import.done', $b['workspace'], [$batch->uid]))->assertNotFound();
        $this->assertSame(0, $this->bizGroups($b)->where('name', 'Stolen')->count());
        // B's picker never lists A's group; A's group uid in analytics filters matches nothing for B.
        $this->get($this->outreachRoute('campaigns.index', $b['workspace']))->assertOk()->assertDontSee('A list');
        $this->getJson($this->outreachRoute('recipient-groups.summary', $b['workspace']) . '?group=' . $groupA->uid)->assertNotFound();
    }

    public function test_backfill_creates_groups_for_earlier_imports_safely_and_repeatably(): void
    {
        $a = $this->outreachAgency('Back', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $ws = $a['workspace'];
        $batch = $this->upload($a, $this->csv(6));

        // The pre-change flow: canonical prospects only, no group.
        app(\App\Library\AgencyOutreach\Import\ProspectImporter::class)->commitProspects($ws, $batch);
        $this->assertNull($batch->refresh()->contact_group_id);
        $stopped = AgencyProspect::where('phone', '12133000001')->first();
        $stopped->update(['status' => 'stopped', 'stop_reason' => 'opt_out', 'stopped_at' => now()]);
        AgencyProspectMessage::create(['workspace_id' => $ws->id, 'campaign_member_id' => \App\Models\AgencyProspectCampaignMember::create(['workspace_id' => $ws->id, 'campaign_id' => $a['campaign']->id, 'prospect_id' => AgencyProspect::where('phone', '12133000002')->value('id'), 'enrolled_at' => now()])->id, 'direction' => 'outbound', 'status' => 'sent', 'body' => 'old', 'sent_at' => now()]);
        $history = [AgencyProspectMessage::count(), \App\Models\AgencyProspectCampaignMember::count(), AgencyProspect::pluck('status', 'id')->all()];

        // Dry run: reports, changes nothing.
        $this->artisan('outreach:backfill-import-groups', ['--workspace' => $ws->uid])->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame(0, ContactGroups::where('business_id', $a['business']->id)->count());
        $this->assertSame(0, Contacts::where('business_id', $a['business']->id)->count());

        // Apply.
        $this->artisan('outreach:backfill-import-groups', ['--workspace' => $ws->uid, '--apply' => true])->expectsOutputToContain('APPLYING')->assertSuccessful();
        $group = ContactGroups::where('business_id', $a['business']->id)->firstOrFail();
        $this->assertSame('Dallas Photographers', $group->name);
        $this->assertSame(5, Contacts::where('group_id', $group->id)->count(), 'the STOP prospect gets no contact');
        $this->assertSame($group->id, (int) $batch->refresh()->contact_group_id);

        // Repeat: nothing more to do, nothing duplicated.
        $this->artisan('outreach:backfill-import-groups', ['--workspace' => $ws->uid, '--apply' => true])->assertSuccessful();
        $this->assertSame(1, ContactGroups::where('business_id', $a['business']->id)->count());
        $this->assertSame(5, Contacts::where('business_id', $a['business']->id)->count());

        // Conversations, campaigns and consent are untouched.
        $this->assertSame($history[0], AgencyProspectMessage::count());
        $this->assertSame($history[1], \App\Models\AgencyProspectCampaignMember::count());
        $this->assertSame($history[2], AgencyProspect::pluck('status', 'id')->all());
        $this->assertSame(0, Contacts::where('business_id', $a['business']->id)->where('status', '!=', 'subscribe')->count());

        // An unknown workspace is refused.
        $this->artisan('outreach:backfill-import-groups', ['--workspace' => 'nope'])->assertFailed();
    }

    private function contactedWithReply(array $a, string $phone, bool $reply, ?\DateTimeInterface $bookedAt = null): void
    {
        $prospect = AgencyProspect::where('workspace_id', $a['workspace']->id)->where('phone', $phone)->firstOrFail();
        $member = \App\Models\AgencyProspectCampaignMember::firstOrCreate(
            ['campaign_id' => $a['campaign']->id, 'prospect_id' => $prospect->id], ['workspace_id' => $a['workspace']->id, 'enrolled_at' => now()]);
        $t = now()->subDay();
        AgencyProspectMessage::create(['workspace_id' => $a['workspace']->id, 'campaign_member_id' => $member->id, 'direction' => 'outbound', 'status' => 'sent', 'purpose' => 'initial', 'body' => 'hi', 'sent_at' => $t]);

        if ($reply) {
            AgencyProspectMessage::create(['workspace_id' => $a['workspace']->id, 'campaign_member_id' => $member->id, 'direction' => 'inbound', 'status' => 'received', 'body' => 'yes', 'received_at' => $t->copy()->addMinutes(20)]);
        }

        if ($bookedAt !== null) {
            $prospect->update(['status' => 'booked', 'booked_at' => $bookedAt]);
            $member->update(['stage' => 6]);
        }
    }

    public function test_analytics_filters_and_compares_by_group_and_custom_field_without_double_counting(): void
    {
        $a = $this->outreachAgency('GA', ['enrolled' => false]);
        $a['campaign']->update(['status' => 'active']);
        $this->authenticateAs($a['customer']);
        $this->commit($a, $this->upload($a, $this->csv(5, 3000000, 'Photo booth'), 'a.csv'), 'List A');
        $this->commit($a, $this->upload($a, $this->csv(5, 3000003, 'Party equipment'), 'b.csv'), 'List B');   // 003 + 004 are in both lists

        $this->contactedWithReply($a, '12133000000', false);
        $this->contactedWithReply($a, '12133000003', true, now()->subHours(20));     // in BOTH groups, replied and booked
        $this->contactedWithReply($a, '12133000006', true);

        $groupA = $this->bizGroups($a)->where('name', 'List A')->firstOrFail();
        $groupB = $this->bizGroups($a)->where('name', 'List B')->firstOrFail();
        $an = fn (array $q = []) => new \App\Library\AgencyOutreach\Analytics\OutreachAnalytics($a['workspace'], \App\Library\AgencyOutreach\Analytics\AnalyticsFilters::fromRequest(\Illuminate\Http\Request::create('/', 'GET', $q + ['period' => 'all'])));

        $global = $an()->kpis();
        $this->assertSame(3, $global['contacted'], 'three distinct prospects, however many groups reference them');
        $this->assertSame(2, $global['replied']);
        $this->assertSame(1, $global['booked'], 'one booking, not one per group');

        $kA = $an(['group' => $groupA->uid])->kpis();
        $kB = $an(['group' => $groupB->uid])->kpis();
        $this->assertSame([2, 1, 1], [$kA['contacted'], $kA['replied'], $kA['booked']]);
        $this->assertSame([2, 2, 1], [$kB['contacted'], $kB['replied'], $kB['booked']]);
        $this->assertSame(4, $kA['contacted'] + $kB['contacted'], 'per-group views overlap by the shared prospect…');
        $this->assertSame(3, $global['contacted'], '…while the workspace total counts it once');

        // Compare across groups.
        $seg = collect($an()->segments('group'))->keyBy('segment');
        $this->assertSame(2, $seg['List A']['n']);
        $this->assertSame(2, $seg['List B']['n']);

        // Real custom-field values as filter and dimension (003's contact lives in List A: "Photo booth").
        $this->assertSame(2, $an(['cf' => 'business_type', 'cfv' => 'Photo booth'])->kpis()['contacted']);
        $this->assertSame(1, $an(['cf' => 'business_type', 'cfv' => 'Party equipment'])->kpis()['contacted']);
        $this->assertSame(0, $an(['cf' => 'business_type', 'cfv' => 'Nope'])->kpis()['contacted']);
        $this->assertSame(0, $an(['cf' => 'does_not_exist'])->kpis()['contacted'], 'an unknown field matches nothing');
        $dim = collect($an()->segments('cf:business_type'))->keyBy('segment');
        $this->assertSame(2, $dim['Photo booth']['n']);
        $this->assertSame(1, $dim['Party equipment']['n']);

        // Same semantics in KPIs, Explorer and drill-down, and the export honours them.
        $filtered = $an(['group' => $groupB->uid]);
        $this->assertSame($kB['contacted'], $filtered->explorer(1, 25, 'contacted')['total']);
        $this->assertSame($kB['booked'], $filtered->explorer(1, 25, 'booked')['total']);
        $opener = collect($filtered->steps())->firstWhere('step_key', 'opener');
        $this->assertSame($kB['contacted'], $opener['prospects']);

        $csv = $this->get($this->outreachRoute('analytics.export', $a['workspace']) . '?period=all&group=' . $groupB->uid)->streamedContent();
        $this->assertStringContainsString('Business 3000006', $csv);
        $this->assertStringContainsString('List A; List B', $csv, 'the export shows every group referencing the prospect');
        $this->assertStringNotContainsString('Business 3000000', $csv);

        // Pages render with the group filter and the new dimensions.
        $this->get($this->outreachRoute('analytics.index', $a['workspace']) . '?group=' . $groupA->uid)->assertOk()->assertSee('List A');
        $this->get($this->outreachRoute('analytics.index', $a['workspace']) . '?tab=types')->assertOk()->assertSee('Contact group')->assertSee('Business type (custom field)');

        // Another workspace's analytics never see this group.
        $b = $this->outreachAgency('GB', ['enrolled' => false]);
        $foreign = new \App\Library\AgencyOutreach\Analytics\OutreachAnalytics($b['workspace'], \App\Library\AgencyOutreach\Analytics\AnalyticsFilters::fromRequest(\Illuminate\Http\Request::create('/', 'GET', ['group' => $groupA->uid, 'period' => 'all'])));
        $this->assertSame(0, $foreign->kpis()['imported']);
    }
}
