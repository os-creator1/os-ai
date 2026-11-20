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
class OutreachImportGroupReviewTest extends TestCase
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

    public function test_equivalent_phone_formats_resolve_to_the_existing_contact_and_never_bypass_unsubscribe(): void
    {
        $a = $this->outreachAgency('Fmt', ['enrolled' => false]);
        $a['business']->forceFill(['country_code' => 'US'])->save();
        $this->authenticateAs($a['customer']);

        // The CRM stored this number in its NATIONAL form and the person unsubscribed.
        $old = ContactGroups::create(['customer_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'name' => 'Old list', 'status' => true]);
        $unsub = Contacts::create(['customer_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'group_id' => $old->id, 'phone' => '2133000001', 'status' => 'unsubscribe']);

        $batch = $this->upload($a, $this->csv(3));             // row 1 is +1 213 300 0001 (canonical 12133000001)
        $this->commit($a, $batch, 'Fmt list')->assertRedirect();

        $forms = \App\Library\Contacts\ContactPhone::candidates('12133000001', 'US');
        $this->assertContains('2133000001', $forms);
        $this->assertSame(1, Contacts::where('business_id', $a['business']->id)->whereIn('phone', $forms)->count(), 'no duplicate Contact for an equivalent format');
        $this->assertSame(0, Contacts::where('business_id', $a['business']->id)->whereIn('phone', $forms)->where('status', 'subscribe')->count(), 'no subscribed twin');
        $this->assertSame('unsubscribe', $unsub->refresh()->status);
        $this->assertSame((int) $old->id, (int) $unsub->group_id);

        $group = $this->bizGroups($a)->where('name', 'Fmt list')->firstOrFail();
        $this->assertSame(2, Contacts::where('group_id', $group->id)->count(), 'the other two rows become contacts');
        $link = AgencyProspectGroupLink::where('contact_group_id', $group->id)->where('prospect_id', AgencyProspect::where('phone', '12133000001')->value('id'))->firstOrFail();
        $this->assertSame((int) $unsub->id, (int) $link->contact_id);
        $this->assertFalse($link->contact_in_group);

        // And the campaign recipient list still excludes them (consent is not bypassed).
        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $group->uid)
            ->assertOk()->assertJsonPath('group_total', 3)->assertJsonPath('eligible', 2);
    }

    public function test_campaigns_for_an_imported_group_include_its_referenced_contacts_with_accurate_counts(): void
    {
        $a = $this->outreachAgency('Ref', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $this->commit($a, $this->upload($a, $this->csv(5), 'a.csv'), 'List A');                 // 0..4
        $this->commit($a, $this->upload($a, $this->csv(5, 3000003), 'b.csv'), 'List B');        // 3..7: 3,4 referenced, 5,6,7 own
        $this->commit($a, $this->upload($a, $this->csv(2, 3000003), 'c.csv'), 'List C');        // 3,4 only: ALL referenced
        $b = $this->bizGroups($a)->where('name', 'List B')->firstOrFail();
        $c = $this->bizGroups($a)->where('name', 'List C')->firstOrFail();

        $this->assertSame(0, Contacts::where('group_id', $c->id)->count(), 'full overlap: the group owns no contacts');
        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $b->uid)
            ->assertOk()->assertJsonPath('group_total', 5)->assertJsonPath('eligible', 5)->assertJsonPath('excluded', 0);
        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $c->uid)
            ->assertOk()->assertJsonPath('group_total', 2)->assertJsonPath('eligible', 2);

        // The picker's own count includes references too.
        $this->get($this->outreachRoute('campaigns.index', $a['workspace']))->assertOk()->assertSee('List C')->assertSee('2 contacts');

        // A draft from the fully-overlapping group enrols the real, shared prospects once.
        $this->post($this->outreachRoute('campaigns.managed.store', $a['workspace']), [
            'name' => 'From C', 'opening_message' => 'Hi', 'group_mode' => 'existing', 'contact_group_uid' => $c->uid,
        ])->assertRedirect();
        $campaign = \App\Models\AgencyProspectCampaign::where('workspace_id', $a['workspace']->id)->where('name', 'From C')->firstOrFail();
        $this->assertSame(2, $campaign->members()->count());
        $this->assertSame(8, AgencyProspect::where('workspace_id', $a['workspace']->id)->count(), 'still one prospect per phone');

        // Consent and exclusions still apply to a referenced contact (unsubscribe one, exclude another).
        Contacts::where('business_id', $a['business']->id)->where('phone', '12133000003')->update(['status' => 'unsubscribe']);
        app(\App\Library\AgencyOutreach\OutreachExistingClients::class)->add($a['workspace'], '+12133000004', 'Client', 'manual', 't', null);
        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $c->uid)
            ->assertOk()->assertJsonPath('group_total', 2)->assertJsonPath('eligible', 0)->assertJsonPath('excluded', 2);
        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $b->uid)
            ->assertOk()->assertJsonPath('group_total', 5)->assertJsonPath('eligible', 3);
    }

    public function test_the_import_enforces_crm_contact_permissions_before_writing_anything(): void
    {
        $a = $this->outreachAgency('Perm', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $batch = $this->upload($a, $this->csv(4));
        $ws = $a['workspace']->id;
        $snapshot = fn () => [AgencyProspect::where('workspace_id', $ws)->count(), $this->bizGroups($a)->count(), Contacts::where('business_id', $a['business']->id)->count(),
            CustomFieldDefinition::where('business_id', $a['business']->id)->count(), DB::table('custom_field_values')->count(), AgencyProspectGroupLink::count(), $batch->fresh()->status];
        $before = $snapshot();

        foreach ([['create_contact_group'], ['create_contact_group', 'update_contact'], ['create_contact_group', 'create_contact']] as $granted) {
            $this->authenticateAs($a['customer'], $granted);
            $this->commit($a, $batch, 'No permission')->assertSessionHas('flash_error');
            $this->assertSame($before, $snapshot(), 'nothing written with only ' . implode(', ', $granted));
        }

        $this->authenticateAs($a['customer'], ['create_contact_group', 'create_contact', 'update_contact']);
        $this->commit($a, $batch, 'With permission')->assertRedirect();
        $this->assertSame(4, Contacts::where('business_id', $a['business']->id)->count());
    }

    public function test_select_custom_fields_filter_and_compare_by_option_label_not_id(): void
    {
        $a = $this->outreachAgency('Sel', ['enrolled' => false]);
        $a['campaign']->update(['status' => 'active']);
        $this->authenticateAs($a['customer']);
        $defs = app(CustomFieldDefinitionManager::class);
        $tier = $defs->create($a['business'], 'Tier', 'select', [['label' => 'Gold'], ['label' => 'Silver']]);
        [$gold, $silver] = array_column($tier->optionList(), 'id');
        $this->commit($a, $this->upload($a, $this->csv(4)), 'Tiered');
        $values = app(CustomFieldValueService::class);

        foreach (['12133000000' => $gold, '12133000001' => $gold, '12133000002' => $silver] as $phone => $optionId) {
            $values->set($a['business'], Contacts::where('business_id', $a['business']->id)->where('phone', $phone)->firstOrFail(), $tier, $optionId);
            $this->contactedWithReply($a, $phone, false);
        }

        $an = fn (array $q) => new \App\Library\AgencyOutreach\Analytics\OutreachAnalytics($a['workspace'], \App\Library\AgencyOutreach\Analytics\AnalyticsFilters::fromRequest(\Illuminate\Http\Request::create('/', 'GET', $q + ['period' => 'all'])));

        // The stored value is the stable ID; both the ID (picker) and the label resolve to it.
        $this->assertSame(2, $an(['cf' => 'tier', 'cfv' => $gold])->kpis()['contacted']);
        $this->assertSame(1, $an(['cf' => 'tier', 'cfv' => $silver])->kpis()['contacted']);
        $this->assertSame(0, $an(['cf' => 'tier', 'cfv' => 'Bronze'])->kpis()['contacted']);

        // Comparison dimension shows LABELS.
        $rows = collect($an([])->segments('cf:tier'))->keyBy('segment');
        $this->assertSame(2, $rows['Gold']['n']);
        $this->assertSame(1, $rows['Silver']['n']);
        $this->assertArrayNotHasKey($gold, $rows->all());

        // The filter UI is a label-aware picker that submits the option ID.
        $page = $this->get($this->outreachRoute('analytics.index', $a['workspace']) . '?cf=tier')->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<select[^>]+name="cfv"/', $html);
        $this->assertMatchesRegularExpression('/<option value="' . preg_quote($gold, '/') . '"[^>]*>\s*Gold\s*<\/option>/', $html);
        $types = $this->get($this->outreachRoute('analytics.index', $a['workspace']) . '?tab=types')->assertOk()->getContent();
        $this->assertStringContainsString('data-segment="Gold"', $types, 'comparison rows are labelled with the option label');
        $this->assertStringNotContainsString('data-segment="' . $gold . '"', $types, 'never the raw option id');
        $this->get($this->outreachRoute('analytics.index', $a['workspace']) . '?cf=tier&cfv=' . $gold)->assertOk();
    }

    public function test_a_group_name_conflict_found_during_commit_rolls_the_whole_import_back(): void
    {
        $a = $this->outreachAgency('Race', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $batch = $this->upload($a, $this->csv(4));
        $ws = $a['workspace']->id;
        $customerId = $a['business']->customer_id;

        // The name is free when the request starts (controller check passes) and taken before the group is written.
        $retrievals = 0;
        AgencyProspectImportBatch::retrieved(function () use (&$retrievals, $a, $customerId) {
            if (++$retrievals === 3) {
                DB::table('contact_groups')->insert(['uid' => 'rival-grp', 'customer_id' => $customerId, 'business_id' => $a['business']->id, 'name' => 'Raced name', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->commit($a, $batch, 'Raced name')->assertSessionHasErrors('group_name');

        $this->assertSame('analyzed', $batch->refresh()->status, 'the import did not complete');
        $this->assertSame(0, AgencyProspect::where('workspace_id', $ws)->count(), 'no prospects committed without a group');
        // (The simulated rival is written inside the same test transaction, so it rolls back with the import.)
        $this->assertSame(0, $this->bizGroups($a)->where('name', '!=', 'Raced name')->count(), 'no group of ours was created');
        $this->assertSame(0, Contacts::where('business_id', $a['business']->id)->count());
        $this->assertNull($batch->contact_group_id);

        // Choosing another name afterwards works and is complete.
        $this->commit($a, $batch->fresh(), 'A free name')->assertRedirect();
        $this->assertSame(4, Contacts::where('group_id', $this->bizGroups($a)->where('name', 'A free name')->value('id'))->count());
        $this->assertSame(4, AgencyProspect::where('workspace_id', $ws)->count());
    }
}
