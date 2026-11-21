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
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
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

    // ---------------------------------------------------------------- second review round

    public function test_a_contact_moved_out_of_an_ordinary_group_stops_being_its_recipient(): void
    {
        $a = $this->outreachAgency('Move', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $biz = $a['business'];
        $ordinary = ContactGroups::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'name' => 'Ordinary', 'status' => true]);
        $elsewhere = ContactGroups::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'name' => 'Elsewhere', 'status' => true]);
        $contacts = [];
        foreach (['12025550101', '12025550102', '12025550103'] as $phone) {
            $contacts[$phone] = Contacts::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'group_id' => $ordinary->id, 'phone' => $phone, 'status' => 'subscribe']);
        }

        $this->post($this->outreachRoute('campaigns.managed.store', $a['workspace']), [
            'name' => 'Ordinary draft', 'opening_message' => 'Hi', 'group_mode' => 'existing', 'contact_group_uid' => $ordinary->uid,
        ])->assertRedirect();
        $campaign = \App\Models\AgencyProspectCampaign::where('workspace_id', $a['workspace']->id)->where('name', 'Ordinary draft')->firstOrFail();
        $this->assertSame(3, $campaign->members()->count());

        // The CRM moves one contact to another group (as batchContactMove does).
        $contacts['12025550102']->update(['group_id' => $elsewhere->id]);

        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $ordinary->uid)
            ->assertOk()->assertJsonPath('group_total', 2)->assertJsonPath('eligible', 2);
        $this->post($this->outreachRoute('campaigns.recipients.refresh', $a['workspace'], [$campaign->uid]))->assertRedirect();
        $this->assertSame(2, $campaign->members()->count(), 'the moved contact is dropped on refresh');

        // An explicit import REFERENCE keeps working, and population never downgrades it to ordinary membership.
        $a['business']->forceFill(['country_code' => 'US'])->save();
        $this->commit($a, $this->upload($a, $this->csv(2), 'x.csv'), 'Imported');
        $imported = $this->bizGroups($a)->where('name', 'Imported')->firstOrFail();
        Contacts::where('business_id', $biz->id)->where('phone', '12133000000')->update(['group_id' => $elsewhere->id]);   // now only reachable by reference
        AgencyProspectGroupLink::where('contact_group_id', $imported->id)->update(['contact_in_group' => false]);
        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $imported->uid)->assertOk()->assertJsonPath('group_total', 2);
        $this->post($this->outreachRoute('campaigns.managed.store', $a['workspace']), [
            'name' => 'Imported draft', 'opening_message' => 'Hi', 'group_mode' => 'existing', 'contact_group_uid' => $imported->uid,
        ])->assertRedirect();
        $this->assertSame(0, AgencyProspectGroupLink::where('contact_group_id', $imported->id)->where('contact_in_group', true)->whereIn('contact_id',
            Contacts::where('business_id', $biz->id)->where('group_id', '!=', $imported->id)->pluck('id'))->count(), 'a reference is never rewritten as membership');
    }

    public function test_a_reused_national_format_contact_is_still_a_valid_recipient_and_sendable(): void
    {
        $a = $this->outreachAgency('Natl', ['enrolled' => false]);
        $a['business']->forceFill(['country_code' => 'US'])->save();
        $this->authenticateAs($a['customer']);
        $old = ContactGroups::create(['customer_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'name' => 'Old list', 'status' => true]);
        $contact = Contacts::create(['customer_id' => $a['business']->customer_id, 'business_id' => $a['business']->id, 'group_id' => $old->id, 'phone' => '2133000001', 'status' => 'subscribe']);

        $this->commit($a, $this->upload($a, $this->csv(3)), 'Natl list');
        $group = $this->bizGroups($a)->where('name', 'Natl list')->firstOrFail();
        $this->assertSame(1, Contacts::where('business_id', $a['business']->id)->whereIn('phone', ['2133000001', '12133000001'])->count(), 'the historical contact is reused');

        // It is a valid recipient (not "invalid phone"): all three of the imported numbers are eligible.
        $this->getJson($this->outreachRoute('recipient-groups.summary', $a['workspace']) . '?group=' . $group->uid)
            ->assertOk()->assertJsonPath('group_total', 3)->assertJsonPath('eligible', 3)->assertJsonPath('excluded', 0);

        $this->post($this->outreachRoute('campaigns.managed.store', $a['workspace']), [
            'name' => 'Natl draft', 'opening_message' => 'Hi', 'group_mode' => 'existing', 'contact_group_uid' => $group->uid,
        ])->assertRedirect();
        $campaign = \App\Models\AgencyProspectCampaign::where('workspace_id', $a['workspace']->id)->where('name', 'Natl draft')->firstOrFail();
        $member = $campaign->members()->whereHas('prospect', fn ($q) => $q->where('phone', '12133000001'))->firstOrFail();
        $this->assertSame((int) $contact->id, (int) $member->source_contact_id);

        // The send-time recheck uses the same normalisation, so the contact is not refused as "changed".
        $campaign->update(['status' => 'active']);
        $result = app(\App\Library\AgencyOutreach\OutreachEligibility::class)->check($member->fresh(), false);
        $this->assertNotSame('source_contact_unsubscribed_or_changed', $result->reason ?? null);
    }

    public function test_custom_field_comparison_counts_a_logical_prospect_once_even_with_two_contact_copies(): void
    {
        $a = $this->outreachAgency('Dup', ['enrolled' => false]);
        $a['campaign']->update(['status' => 'active']);
        $this->authenticateAs($a['customer']);
        $biz = $a['business'];
        $this->commit($a, $this->upload($a, $this->csv(2)), 'First');
        $first = $this->bizGroups($a)->where('name', 'First')->firstOrFail();

        // The CRM holds a COPY of the same person in another group, with the same custom value; both link to the one prospect.
        $second = ContactGroups::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'name' => 'Copy list', 'status' => true]);
        $original = Contacts::where('group_id', $first->id)->where('phone', '12133000000')->firstOrFail();
        $copy = Contacts::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'group_id' => $second->id, 'phone' => '12133000000', 'status' => 'subscribe']);
        $def = CustomFieldDefinition::where('business_id', $biz->id)->where('label', 'Business type')->firstOrFail();
        app(CustomFieldValueService::class)->set($biz, $copy, $def, 'Photo booth');
        $prospectId = AgencyProspect::where('workspace_id', $a['workspace']->id)->where('phone', '12133000000')->value('id');
        AgencyProspectGroupLink::create(['workspace_id' => $a['workspace']->id, 'contact_group_id' => $second->id, 'prospect_id' => $prospectId, 'contact_id' => $copy->id, 'contact_in_group' => true]);
        $this->contactedWithReply($a, '12133000000', true);

        $an = new \App\Library\AgencyOutreach\Analytics\OutreachAnalytics($a['workspace'], \App\Library\AgencyOutreach\Analytics\AnalyticsFilters::fromRequest(\Illuminate\Http\Request::create('/', 'GET', ['period' => 'all'])));
        $rows = collect($an->segments('cf:business_type'))->keyBy('segment');

        $this->assertSame(1, $rows['Photo booth']['n'], 'one logical prospect, not one per Contact copy');
        $this->assertSame(1, $rows['Photo booth']['replied']);
        $this->assertSame(1, $an->kpis()['contacted']);
    }

    // ---------------------------------------------------------------- third review round

    public function test_an_import_cannot_touch_a_contact_at_a_location_the_actor_cannot_access(): void
    {
        $a = $this->outreachAgency('Loc', ['enrolled' => false]);
        $a['business']->forceFill(['country_code' => 'US'])->save();
        $biz = $a['business'];
        $mine = BusinessLocation::create(['business_id' => $biz->id, 'name' => 'Mine', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $other = BusinessLocation::create(['business_id' => $biz->id, 'name' => 'Other', 'service_mode' => 'storefront', 'country_code' => 'US']);

        // An Admin who reaches the Business but holds a grant for ONE Location only.
        $admin = $this->createCustomer();
        $membership = $this->member($a['workspace'], $admin->user, WorkspaceMembershipRole::Admin);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $mine);

        // The number already belongs to a Contact pinned to the location the Admin cannot see, in a CRM group.
        $old = ContactGroups::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'name' => 'Other site', 'status' => true]);
        $protected = Contacts::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'group_id' => $old->id, 'location_id' => $other->id, 'phone' => '12133000001', 'status' => 'subscribe']);
        $open = Contacts::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'group_id' => $old->id, 'location_id' => $mine->id, 'phone' => '12133000002', 'status' => 'subscribe']);

        $this->authenticateAs($admin);
        $batch = $this->upload($a, $this->csv(4));
        $before = [AgencyProspect::where('workspace_id', $a['workspace']->id)->count(), $this->bizGroups($a)->count(), Contacts::where('business_id', $biz->id)->count(),
            DB::table('custom_field_values')->count(), CustomFieldDefinition::where('business_id', $biz->id)->count(), AgencyProspectGroupLink::count()];
        $this->commit($a, $batch, 'Admin import')->assertRedirect()->assertSessionHas('flash_error');

        // Fail closed and atomic: nothing was imported, including for the rows the Admin COULD have handled.
        $this->assertSame($before, [AgencyProspect::where('workspace_id', $a['workspace']->id)->count(), $this->bizGroups($a)->count(), Contacts::where('business_id', $biz->id)->count(),
            DB::table('custom_field_values')->count(), CustomFieldDefinition::where('business_id', $biz->id)->count(), AgencyProspectGroupLink::count()]);
        $this->assertSame(0, DB::table('custom_field_values')->where('contact_id', $protected->id)->count(), 'no custom field written onto an inaccessible Contact');
        $this->assertSame(0, DB::table('custom_field_values')->where('contact_id', $open->id)->count());
        $this->assertSame(1, Contacts::where('business_id', $biz->id)->whereIn('phone', ['12133000001', '2133000001'])->count(), 'no subscribed twin as a workaround');
        $this->assertSame((int) $old->id, (int) $protected->refresh()->group_id);
        $this->assertSame('analyzed', $batch->refresh()->status);
        $message = (string) session('flash_error');
        $this->assertStringNotContainsString('Other', $message, 'the protected Location is not named');
        $this->assertStringNotContainsString('12133000001', $message, 'nor the Contact or number');

        // The Workspace owner (full access) imports the same file: unchanged behaviour, and the Contact is enriched.
        $this->authenticateAs($a['customer']);
        $this->commit($a, $this->upload($a, $this->csv(2), 'o.csv'), 'Owner import')->assertRedirect();
        $this->assertGreaterThan(0, DB::table('custom_field_values')->where('contact_id', $protected->id)->count(), 'a fully authorised actor may enrich it');
    }

    public function test_a_repeated_submission_of_a_completed_import_writes_nothing(): void
    {
        $a = $this->outreachAgency('Idem', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);
        $biz = $a['business'];
        $batch = $this->upload($a, $this->csv(4));
        $this->commit($a, $batch, 'Done once')->assertRedirect();
        $group = $this->bizGroups($a)->where('name', 'Done once')->firstOrFail();
        $reportBefore = $batch->fresh()->group_report;
        $summaryBefore = $batch->fresh()->summary;

        // The CRM is edited by hand afterwards: delete one contact, clear one value, move one to another group.
        $elsewhere = ContactGroups::create(['customer_id' => $biz->customer_id, 'business_id' => $biz->id, 'name' => 'Elsewhere', 'status' => true]);
        $deleted = Contacts::where('group_id', $group->id)->where('phone', '12133000000')->firstOrFail();
        $deleted->delete();
        $cleared = Contacts::where('group_id', $group->id)->where('phone', '12133000001')->firstOrFail();
        $def = CustomFieldDefinition::where('business_id', $biz->id)->where('label', 'Business type')->firstOrFail();
        app(CustomFieldValueService::class)->clear($biz, $cleared, $def);
        $moved = Contacts::where('group_id', $group->id)->where('phone', '12133000002')->firstOrFail();
        $moved->update(['group_id' => $elsewhere->id]);
        $linksBefore = AgencyProspectGroupLink::where('contact_group_id', $group->id)->orderBy('prospect_id')->get(['prospect_id', 'contact_id', 'contact_in_group'])->toArray();
        $contactCount = Contacts::where('business_id', $biz->id)->count();
        $valueCount = DB::table('custom_field_values')->count();
        $groupCount = $this->bizGroups($a)->count();

        // The same request is submitted again (double click, retry, back button), even with a different name.
        $this->commit($a, $batch, 'Done once')->assertRedirect($this->outreachRoute('prospects.import.done', $a['workspace'], [$batch->uid]));
        $this->commit($a, $batch->fresh(), 'A different name')->assertRedirect();

        $this->assertSame($contactCount, Contacts::where('business_id', $biz->id)->count(), 'a deleted contact is not recreated');
        $this->assertSame(0, Contacts::where('business_id', $biz->id)->where('phone', '12133000000')->count());
        $this->assertSame(0, DB::table('custom_field_values')->where('contact_id', $cleared->id)->where('definition_id', $def->id)->count(), 'a cleared value is not restored');
        $this->assertSame($valueCount, DB::table('custom_field_values')->count());
        $this->assertSame((int) $elsewhere->id, (int) $moved->refresh()->group_id, 'a moved contact stays moved');
        $this->assertSame($linksBefore, AgencyProspectGroupLink::where('contact_group_id', $group->id)->orderBy('prospect_id')->get(['prospect_id', 'contact_id', 'contact_in_group'])->toArray(), 'group references are not rewritten');
        $this->assertSame($groupCount, $this->bizGroups($a)->count());
        $this->assertEquals($reportBefore, $batch->fresh()->group_report, 'the original completion report is kept');
        $this->assertEquals($summaryBefore, $batch->fresh()->summary);

        // The explicit repair workflow is separate and untouched: a batch WITHOUT a group can still be materialised.
        $legacy = $this->upload($a, $this->csv(2, 3000100), 'legacy.csv');
        app(\App\Library\AgencyOutreach\Import\ProspectImporter::class)->commitProspects($a['workspace'], $legacy);
        $this->artisan('outreach:backfill-import-groups', ['--workspace' => $a['workspace']->uid, '--apply' => true])->assertSuccessful();
        $this->assertNotNull($legacy->fresh()->contact_group_id);
        $this->assertSame($contactCount + 2, Contacts::where('business_id', $biz->id)->count());
    }

    public function test_two_csv_columns_mapping_to_the_same_custom_field_preserve_first_value(): void
    {
        $a = $this->outreachAgency('SameField', ['enrolled' => false]);
        $this->authenticateAs($a['customer']);

        $definition = app(CustomFieldDefinitionManager::class)->create($a['business'], 'Business type', 'text');
        $csv = "name,phone,primary category,Alternate category\n"
            . "Acme,+12133000001,Photo booth,Dog trainer\n";
        $batch = $this->upload($a, $csv, 'same-field.csv');

        // The built-in category and the extra column both target the same existing definition.
        $batch->update(['extra_fields' => [
            'Alternate category' => ['action' => 'existing', 'definition_uid' => $definition->uid],
        ]]);
        $plan = collect(app(ProspectGroupMaterializer::class)->plan($a['business'], $batch->fresh()))
            ->where('definition_id', $definition->id)->pluck('key')->all();
        $this->assertContains('business_type', $plan);
        $this->assertContains('extra:Alternate category', $plan);

        $this->commit($a, $batch->fresh(), 'Same field list')->assertRedirect();
        $contact = Contacts::where('business_id', $a['business']->id)->where('phone', '12133000001')->firstOrFail();

        // First successful value wins; later mappings may never overwrite it.
        $this->assertDatabaseHas('custom_field_values', [
            'contact_id' => $contact->id,
            'definition_id' => $definition->id,
            'value_text' => 'Photo booth',
        ]);
        $this->assertSame(1, DB::table('custom_field_values')
            ->where('contact_id', $contact->id)->where('definition_id', $definition->id)->count());
    }

}
