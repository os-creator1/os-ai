<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Library\AgencyOutreach\OutreachCompanyName;
use App\Library\AgencyOutreach\OutreachContactGroupBridge;
use App\Library\AgencyOutreach\OutreachScriptRenderer;
use App\Library\AgencyOutreach\OutreachScriptTokens;
use App\Library\AgencyProspecting\AgencyProspectMessageTemplate;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectGroupLink;
use App\Models\AgencyProspectingSetting;
use App\Models\ContactGroupFields;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * `{{company_name}}` and `{{contact.company}}` are aliases of the PROSPECT's company: recognised in the
 * editor, resolved from the prospect or its CRM Contact, identical in preview and in every outbound
 * message, and never the sender's own Business name.
 */
class OutreachCompanyTokenTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    private function companyField(ContactGroups $group): ContactGroupFields
    {
        return ContactGroupFields::query()->firstOrCreate(
            ['contact_group_id' => $group->id, 'tag' => 'COMPANY'],
            ['label' => 'Company', 'type' => 'text', 'visible' => true, 'required' => false],
        );
    }

    /** A CRM contact in a group of the agency's Business, optionally with a COMPANY value. */
    private function crmContact($business, ContactGroups $group, string $phone, ?string $company): Contacts
    {
        $contact = Contacts::create([
            'customer_id' => $group->customer_id, 'business_id' => $business->id, 'group_id' => $group->id,
            'phone' => $phone, 'status' => 'subscribe',
        ]);

        if ($company !== null) {
            DB::table('contacts_custom_field')->insert([
                'field_id' => $this->companyField($group)->id, 'contact_id' => $contact->id, 'value' => $company,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $contact;
    }

    public function test_both_aliases_are_recognised_in_the_editor_and_never_flagged_unknown(): void
    {
        [, , $workspace] = $this->agency('Alpha');

        $text = 'Hi {{company_name}} / {{ contact.company }} / {{Company_Name}}';

        $this->assertSame([], OutreachScriptRenderer::unknownTokens($text, $workspace));
        $this->assertSame('Hi {{prospect.company}} / {{prospect.company}} / {{prospect.company}}', OutreachScriptTokens::canonicalise($text));
    }

    public function test_the_company_is_the_prospects_and_never_the_senders_business_name(): void
    {
        [, , $workspace] = $this->agency('Sender Agency');
        $prospect = $this->prospect($workspace, '12025551001', ['company_name' => 'Acme Plumbing']);

        $out = OutreachScriptRenderer::render('{{company_name}} | {{contact.company}} | {{agency.name}}', $workspace, $prospect);

        $this->assertSame('Acme Plumbing | Acme Plumbing | Sender Agency', $out);
        $this->assertSame('Acme Plumbing', OutreachScriptRenderer::render('{{prospect.company}}', $workspace, $prospect), 'The existing token is unchanged.');
    }

    public function test_a_csv_imported_contact_materialised_into_a_group_resolves_its_company_from_the_crm_contact(): void
    {
        [, $business, $workspace] = $this->agency();
        $group = ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'CSV leads', 'status' => true]);
        $contact = $this->crmContact($business, $group, '12025551002', 'Birch Dental');

        // The importer stores the phone as the required company_name when the CSV had no business name.
        $prospect = $this->prospect($workspace, '12025551002', ['company_name' => '12025551002', 'contact_name' => null]);
        AgencyProspectGroupLink::create(['workspace_id' => $workspace->id, 'contact_group_id' => $group->id, 'prospect_id' => $prospect->id, 'contact_id' => $contact->id, 'contact_in_group' => true]);

        $this->assertSame('Birch Dental', OutreachCompanyName::for($prospect));
        $this->assertSame('Hi Birch Dental', OutreachScriptRenderer::render('Hi {{company_name}}', $workspace, $prospect));
    }

    public function test_populating_a_campaign_from_a_group_carries_the_company_into_the_prospect(): void
    {
        [, $business, $workspace] = $this->agency();
        $group = ContactGroups::create(['customer_id' => $business->customer_id, 'business_id' => $business->id, 'name' => 'Leads', 'status' => true]);
        $this->crmContact($business, $group, '12025551003', 'Cedar Cafe');
        $this->crmContact($business, $group, '12025551004', null);

        $campaign = $this->managedCampaign($workspace, ['status' => 'draft']);
        app(OutreachContactGroupBridge::class)->populate($workspace, $campaign, $group);

        $withCompany = AgencyProspect::where('workspace_id', $workspace->id)->where('phone', '12025551003')->firstOrFail();
        $without = AgencyProspect::where('workspace_id', $workspace->id)->where('phone', '12025551004')->firstOrFail();

        $this->assertSame('Hi Cedar Cafe', OutreachScriptRenderer::render('Hi {{company_name}}', $workspace, $withCompany));
        // The bridge stored the phone as a stand-in; it must not be texted as a company name.
        $this->assertSame('Hi there', OutreachScriptRenderer::render('Hi {{company_name}}', $workspace, $without));
    }

    public function test_a_contact_of_another_business_is_never_used(): void
    {
        [, $business, $workspace] = $this->agency('Alpha');
        [, $otherBusiness] = $this->agency('Bravo');
        $group = ContactGroups::create(['customer_id' => $otherBusiness->customer_id, 'business_id' => $otherBusiness->id, 'name' => 'Foreign', 'status' => true]);
        $foreign = $this->crmContact($otherBusiness, $group, '12025551005', 'Foreign Co');

        $prospect = $this->prospect($workspace, '12025551005', ['company_name' => '12025551005', 'contact_name' => null]);
        AgencyProspectGroupLink::create(['workspace_id' => $workspace->id, 'contact_group_id' => $group->id, 'prospect_id' => $prospect->id, 'contact_id' => $foreign->id, 'contact_in_group' => true]);

        $this->assertNull(OutreachCompanyName::for($prospect));
        $this->assertStringNotContainsString('Foreign', OutreachScriptRenderer::render('Hi {{company_name}}', $workspace, $prospect));
    }

    public function test_a_missing_company_uses_a_safe_fallback_with_no_raw_placeholder_or_awkward_greeting(): void
    {
        [, , $workspace] = $this->agency();
        $prospect = $this->prospect($workspace, '12025551006', ['company_name' => '+12025551006', 'contact_name' => null]);

        $cases = [
            'Hi {{company_name}}, quick question.' => 'Hi there, quick question.',
            'Hello {{contact.company}} team, hello.' => 'Hello there, hello.',
            'Saw {{company_name}}\'s reviews.' => 'Saw your reviews.',
            'Do you run {{company_name}}?' => 'Do you run your business?',
        ];

        foreach ($cases as $template => $expected) {
            $out = OutreachScriptRenderer::render($template, $workspace, $prospect);

            $this->assertSame($expected, $out);
            $this->assertStringNotContainsString('{{', $out);
            $this->assertStringNotContainsString('1202555', $out, 'A phone number is never presented as a company name.');
        }

        $this->assertSame('Hi there', OutreachScriptRenderer::render('Hi {{company_name}}', $workspace, null), 'No prospect at all falls back too.');
    }

    public function test_a_company_that_is_only_the_persons_own_name_is_treated_as_missing(): void
    {
        [, , $workspace] = $this->agency();
        $prospect = $this->prospect($workspace, '12025551007', ['company_name' => 'Dana Maple', 'contact_name' => 'dana maple']);

        $this->assertNull(OutreachCompanyName::for($prospect));
        $this->assertSame('Hi there', OutreachScriptRenderer::render('Hi {{company_name}}', $workspace, $prospect));
    }

    public function test_a_company_name_can_never_inject_a_merge_token(): void
    {
        [, , $workspace] = $this->agency('Sender Agency');
        $prospect = $this->prospect($workspace, '12025551008', ['company_name' => 'Evil {{agency.name}} Co']);

        $out = OutreachScriptRenderer::render('Hi {{company_name}}', $workspace, $prospect);

        $this->assertStringNotContainsString('{{', $out);
        $this->assertStringNotContainsString('Sender Agency', $out);
    }

    public function test_the_editor_preview_equals_the_actual_opener_and_follow_up_sms(): void
    {
        [, $business, $workspace] = $this->agency();
        $opening = 'Hi {{company_name}}, {{agency_name}} here.';
        $followUp = 'Hi {{contact.company}}, following up: {{calendar_link}}';
        $this->saveScript($workspace, ['followup_message' => $followUp]);

        $campaign = $this->managedCampaign($workspace, ['opening_message' => $opening]);
        $prospect = $this->prospect($workspace, '12025551009', ['company_name' => 'Dune Bakery', 'contact_name' => 'Dana Dune']);
        $member = $this->enroll($workspace, $campaign, $prospect);

        $previewOpening = OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise($opening), $workspace, $prospect);
        OutreachInitialSendJob::dispatch($member->id);

        $member->update(['stage' => 4, 'followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);
        $previewFollowUp = OutreachScriptRenderer::render($followUp, $workspace, $prospect);
        OutreachFollowUpJob::dispatch($member->id);

        $this->assertSame([$previewOpening, $previewFollowUp], $this->sentBodies());
        $this->assertSame('Hi Dune Bakery, Snap Booth Co here.', $previewOpening);
        $this->assertSame('Hi Dune Bakery, following up: https://cal.example.test/snap', $previewFollowUp);
    }

    public function test_initial_and_follow_up_messages_use_the_fallback_when_the_company_is_missing(): void
    {
        [, , $workspace] = $this->agency();
        $this->saveScript($workspace, ['followup_message' => 'Hi {{company_name}}, following up: {{calendar_link}}']);

        $campaign = $this->managedCampaign($workspace, ['opening_message' => 'Hi {{company_name}}, {{agency_name}} here.']);
        $prospect = $this->prospect($workspace, '12025551010', ['company_name' => '12025551010', 'contact_name' => null]);
        $member = $this->enroll($workspace, $campaign, $prospect);

        OutreachInitialSendJob::dispatch($member->id);
        $member->update(['stage' => 4, 'followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);
        OutreachFollowUpJob::dispatch($member->id);

        $this->assertSame(
            ['Hi there, Snap Booth Co here.', 'Hi there, following up: https://cal.example.test/snap'],
            $this->sentBodies(),
        );
    }

    public function test_the_legacy_channel_template_resolves_the_same_aliases_and_fallback(): void
    {
        [, , $workspace] = $this->agency();
        $settings = new AgencyProspectingSetting(['workspace_id' => $workspace->id, 'agency_name' => 'Snap Booth Co']);
        $named = $this->prospect($workspace, '12025551011', ['company_name' => 'Elm Florist', 'contact_name' => 'Eve Elm']);
        $unnamed = $this->prospect($workspace, '12025551012', ['company_name' => '12025551012', 'contact_name' => null]);

        $template = 'Hi {{company_name}} / {{contact.company}}, {{contact_name}} from {{agency_name}}';

        $this->assertSame('Hi Elm Florist / Elm Florist, Eve Elm from Snap Booth Co', AgencyProspectMessageTemplate::render($template, $named, $settings));
        $this->assertSame('Hi there / your business,  from Snap Booth Co', AgencyProspectMessageTemplate::render($template, $unnamed, $settings));
    }
}
