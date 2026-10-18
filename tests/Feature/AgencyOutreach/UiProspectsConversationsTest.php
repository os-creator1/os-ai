<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\AgencyOutreach\OutreachConversationContextSection;
use App\Library\AgencyOutreach\OutreachProspectStatusPresenter;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\ChatBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\AgencyOutreach\Concerns\CreatesOutreachUiFixtures;
use Tests\TestCase;

/**
 * Prospects status mapping, the Conversations tab (filters, Pause/Resume AI),
 * the Conversations contact-panel section, and query budgets for the lists.
 */
class UiProspectsConversationsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOutreachUiFixtures;

    // ---------------------------------------------------------------
    // Status presenter (pure)
    // ---------------------------------------------------------------

    #[DataProvider('statusCases')]
    public function test_the_status_mapping(string $expected, string $prospectStatus, ?string $stopReason, ?int $stage, ?string $in, ?string $out): void
    {
        $prospect = new AgencyProspect(['status' => $prospectStatus, 'stop_reason' => $stopReason]);
        $member = $stage === null ? null : new AgencyProspectCampaignMember([
            'stage' => $stage,
            'last_inbound_at' => $in,
            'last_outbound_at' => $out,
        ]);

        $this->assertSame($expected, (new OutreachProspectStatusPresenter())->label($prospect, $member));
    }

    public static function statusCases(): array
    {
        return [
            'not enrolled' => ['Active', 'active', null, null, null, null],
            'enrolled nothing sent' => ['Active', 'active', null, 1, null, null],
            'opener sent, no reply' => ['Awaiting reply', 'active', null, 1, null, '2026-01-01 10:00:00'],
            'we replied after theirs' => ['Awaiting reply', 'active', null, 2, '2026-01-01 10:00:00', '2026-01-01 10:05:00'],
            'they replied last' => ['Active', 'active', null, 2, '2026-01-01 10:10:00', '2026-01-01 10:05:00'],
            'call asked' => ['Call proposed', 'active', null, 3, '2026-01-01 10:10:00', '2026-01-01 10:05:00'],
            'link sent' => ['Booking', 'active', null, 4, null, null],
            'scheduling' => ['Booking', 'active', null, 5, null, null],
            'booked stage' => ['Booked', 'active', null, 6, null, null],
            'booked prospect' => ['Booked', 'booked', null, 4, null, null],
            'rejected' => ['Rejected', 'stopped', 'rejected', 99, null, null],
            'manual stop' => ['Rejected', 'stopped', 'manual', 99, null, null],
            'legacy stop without reason' => ['Rejected', 'stopped', null, 99, null, null],
            'opted out' => ['Opted out', 'stopped', 'opt_out', 99, null, null],
        ];
    }

    public function test_the_prospects_tab_shows_the_mapped_status_and_the_requested_columns(): void
    {
        $a = $this->outreachAgency('Alpha');
        $a['member']->update(['stage' => 3, 'last_outbound_at' => now()]);
        AgencyProspectMessage::create([
            'workspace_id' => $a['workspace']->id, 'campaign_member_id' => $a['member']->id, 'direction' => 'outbound',
            'purpose' => 'initial', 'operation_key' => 'k1', 'body' => 'Visible last message text', 'status' => 'sent',
        ]);
        $opted = AgencyProspect::create(['workspace_id' => $a['workspace']->id, 'company_name' => 'Optout Co', 'phone' => '15557770001', 'status' => 'stopped', 'stop_reason' => 'opt_out']);
        $this->authenticateAs($a['customer']);

        $html = $this->get($this->outreachRoute('prospects.index', $a['workspace']))->assertOk()->getContent();

        foreach (['Name', 'Company', 'Phone', 'Email', 'Source', 'Campaign', 'Stage', 'Last message', 'Last activity', 'Status'] as $column) {
            $this->assertStringContainsString("<th>{$column}</th>", $html);
        }

        $this->assertStringContainsString('Call proposed', $html);
        $this->assertStringContainsString('Opted out', $html);
        $this->assertStringContainsString('Visible last message text', $html);
        $this->assertStringContainsString('Alpha Campaign', $html);
        $this->assertStringContainsString($opted->uid, $html);
        $this->assertStringContainsString(route('customer.workspaces.prospecting.prospects.store', $a['workspace']->uid), $html);
    }

    // ---------------------------------------------------------------
    // Conversations tab
    // ---------------------------------------------------------------

    /** @return array{0: array, 1: array<string, AgencyProspectCampaignMember>} */
    private function conversationFixture(): array
    {
        $a = $this->outreachAgency('Alpha');
        $second = AgencyProspectCampaign::create(['workspace_id' => $a['workspace']->id, 'name' => 'Second Campaign', 'status' => 'active', 'sending_mode' => 'managed']);
        $a['campaign']->update(['status' => 'active']);

        $make = function (string $label, array $member, ?AgencyProspectCampaign $campaign = null) use ($a) {
            $prospect = AgencyProspect::create(['workspace_id' => $a['workspace']->id, 'company_name' => $label . ' Co', 'contact_name' => $label, 'phone' => '1556' . random_int(1000000, 9999999), 'status' => 'active']);
            $box = $this->chatBoxFor($a['business'], $prospect->phone);

            return AgencyProspectCampaignMember::create(array_merge([
                'workspace_id' => $a['workspace']->id, 'campaign_id' => ($campaign ?? $a['campaign'])->id, 'prospect_id' => $prospect->id,
                'enrolled_at' => now(), 'chat_box_id' => $box->id, 'last_inbound_at' => now(),
            ], $member));
        };

        return [$a, [
            'ai' => $make('Ann', ['stage' => 2]),
            'manual' => $make('Mia', ['stage' => 3, 'ai_paused_at' => now()]),
            'booked' => $make('Bob', ['stage' => 6], $second),
            'rejected' => $make('Rex', ['stage' => 99], $second),
        ]];
    }

    private function chatBoxFor($business, string $phone): ChatBox
    {
        $box = new ChatBox(['user_id' => $business->customer_id, 'business_id' => $business->id, 'from' => '14155550100', 'to' => $phone, 'reply_by_customer' => true]);
        $box->uid = (string) Str::uuid();
        $box->save();

        return $box->fresh();
    }

    /** @return list<string> member uids listed on the page */
    private function listed(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match_all('/data-member="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_the_conversations_tab_lists_only_linked_members_and_each_row_links_into_the_canonical_inbox(): void
    {
        [$a, $members] = $this->conversationFixture();
        $unlinked = $a['member'];
        $this->authenticateAs($a['customer']);

        $html = $this->get($this->outreachRoute('conversations.index', $a['workspace']))->assertOk()->getContent();
        $listed = $this->listed($this->outreachRoute('conversations.index', $a['workspace']));

        $this->assertCount(4, $listed);
        $this->assertNotContains($unlinked->uid, $listed);
        $inbox = route('customer.workspaces.businesses.conversations.index', [$a['workspace']->uid, $a['business']->uid]);
        $this->assertStringContainsString($inbox . '?open=', $html);
        $this->assertStringContainsString(ChatBox::find($members['ai']->chat_box_id)->uid, $html);
    }

    public function test_the_conversations_filters(): void
    {
        [$a, $m] = $this->conversationFixture();
        $this->authenticateAs($a['customer']);
        $url = fn (array $q) => $this->outreachRoute('conversations.index', $a['workspace']) . '?' . http_build_query($q);

        $this->assertEqualsCanonicalizing([$m['booked']->uid, $m['rejected']->uid], $this->listed($url(['campaign' => AgencyProspectCampaign::where('name', 'Second Campaign')->value('uid')])));
        $this->assertSame([$m['manual']->uid], $this->listed($url(['stage' => 3])));
        $this->assertSame([$m['manual']->uid], $this->listed($url(['mode' => 'manual'])));
        $this->assertEqualsCanonicalizing([$m['ai']->uid, $m['booked']->uid, $m['rejected']->uid], $this->listed($url(['mode' => 'ai'])));
        $this->assertSame([$m['booked']->uid], $this->listed($url(['outcome' => 'booked'])));
        $this->assertSame([$m['rejected']->uid], $this->listed($url(['outcome' => 'rejected'])));
        $this->assertCount(4, $this->listed($url(['stage' => 'garbage'])), 'An unknown stage value is ignored, not an error.');
    }

    public function test_pause_and_resume_ai_change_only_that_member_and_are_audited(): void
    {
        [$a, $m] = $this->conversationFixture();
        $this->authenticateAs($a['customer']);

        $this->post($this->outreachRoute('conversations.pause', $a['workspace'], [$m['ai']->uid]))->assertRedirect()->assertSessionHas('flash_success');
        $this->assertNotNull($m['ai']->fresh()->ai_paused_at);
        $this->assertSame($a['customer']->user_id, (int) $m['ai']->fresh()->ai_paused_by_user_id);
        $this->assertNull($m['booked']->fresh()->ai_paused_at);
        $this->assertSame(1, AgencyProspectMessage::where('campaign_member_id', $m['ai']->id)->where('actor_user_id', $a['customer']->user_id)->count());

        $this->post($this->outreachRoute('conversations.resume', $a['workspace'], [$m['manual']->uid]))->assertRedirect();
        $this->assertNull($m['manual']->fresh()->ai_paused_at);
    }

    public function test_an_active_workspace_admin_may_pause_but_staff_may_not(): void
    {
        [$a, $m] = $this->conversationFixture();
        $admin = $this->createCustomer();
        $staff = $this->createCustomer();
        $this->member($a['workspace'], $admin->user, WorkspaceMembershipRole::Admin);
        $this->member($a['workspace'], $staff->user, WorkspaceMembershipRole::Staff);

        $this->authenticateAs($staff);
        $this->post($this->outreachRoute('conversations.pause', $a['workspace'], [$m['ai']->uid]))->assertNotFound();
        $this->assertNull($m['ai']->fresh()->ai_paused_at);

        $this->authenticateAs($admin);
        $this->post($this->outreachRoute('conversations.pause', $a['workspace'], [$m['ai']->uid]))->assertRedirect();
        $this->assertNotNull($m['ai']->fresh()->ai_paused_at);
    }

    // ---------------------------------------------------------------
    // Conversations contact-panel section
    // ---------------------------------------------------------------

    public function test_the_context_section_appears_only_for_a_linked_outreach_conversation_of_the_agencys_own_business(): void
    {
        [$a, $m] = $this->conversationFixture();
        $b = $this->outreachAgency('Bravo');
        $this->authenticateAs($a['customer']);
        $section = app(OutreachConversationContextSection::class);

        $linked = ChatBox::find($m['manual']->chat_box_id);
        $described = $section->describe($a['business'], $linked, null);

        $this->assertSame('Outreach', $described['title']);
        $rows = collect($described['rows'])->pluck('value', 'label');
        $this->assertSame('Mia', $rows['Prospect']);
        $this->assertSame('Mia Co', $rows['Company']);
        $this->assertSame('Alpha Campaign', $rows['Campaign']);
        $this->assertSame('Call asked', $rows['Stage']);
        $this->assertStringContainsString('Manual', $rows['Replies']);
        $this->assertSame('In progress', $rows['Outcome']);
        $this->assertSame('Resume AI', $described['actions'][0]['label']);
        $this->assertSame($this->outreachRoute('conversations.resume', $a['workspace'], [$m['manual']->uid]), $described['actions'][0]['url']);

        $this->assertSame('Pause AI', $section->describe($a['business'], ChatBox::find($m['ai']->chat_box_id), null)['actions'][0]['label']);
        $this->assertSame('Booked', collect($section->describe($a['business'], ChatBox::find($m['booked']->chat_box_id), null)['rows'])->pluck('value', 'label')['Outcome']);
        $this->assertSame([], $section->describe($a['business'], ChatBox::find($m['booked']->chat_box_id), null)['actions'], 'A finished conversation has no pause control.');

        // A conversation that is not linked to any member shows nothing.
        $plain = $this->chatBoxFor($a['business'], '15559990000');
        $this->assertNull($section->describe($a['business'], $plain, null));

        // Another Agency's linked conversation, viewed from this Agency's Business, shows nothing.
        $foreign = $this->chatBoxFor($b['business'], $b['prospect']->phone);
        $b['member']->update(['chat_box_id' => $foreign->id]);
        $this->assertNull($section->describe($a['business'], $foreign, null));
        $this->assertNull($section->describe($b['business'], $foreign, null), 'And the other Agency owner is not the viewer.');
    }

    public function test_the_context_section_is_hidden_from_workspace_staff(): void
    {
        [$a, $m] = $this->conversationFixture();
        $staff = $this->createCustomer();
        $this->member($a['workspace'], $staff->user, WorkspaceMembershipRole::Staff);
        $this->authenticateAs($staff);

        $this->assertNull(app(OutreachConversationContextSection::class)->describe($a['business'], ChatBox::find($m['ai']->chat_box_id), null));
    }

    public function test_the_section_is_registered_on_the_canonical_context_tag(): void
    {
        $tagged = iterator_to_array(app()->tagged(\App\Library\Conversations\ConversationContextReader::SECTIONS_TAG), false);

        $this->assertNotEmpty(array_filter($tagged, fn ($s) => $s instanceof OutreachConversationContextSection));
    }

    public function test_the_contact_panel_renders_the_outreach_block_with_its_pause_control(): void
    {
        [$a, $m] = $this->conversationFixture();
        $customer = $a['customer'];
        $customer->permissions = \App\Models\Customer::customerPermissions();
        $customer->save();
        $this->authenticateAs($customer);
        $box = ChatBox::find($m['ai']->chat_box_id);

        $panel = $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$a['workspace']->uid, $a['business']->uid, $box->uid]))
            ->assertOk()->json('context');

        $this->assertStringContainsString('Outreach', $panel);
        $this->assertStringContainsString('Pause AI', $panel);
        $this->assertStringContainsString($this->outreachRoute('conversations.pause', $a['workspace'], [$m['ai']->uid]), $panel);
    }

    // ---------------------------------------------------------------
    // Query budgets (no per-row queries)
    // ---------------------------------------------------------------

    private function queryCount(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    private function grow(array $a, int $extra): void
    {
        for ($i = 0; $i < $extra; $i++) {
            $prospect = AgencyProspect::create(['workspace_id' => $a['workspace']->id, 'company_name' => "Bulk {$i} Co", 'phone' => '1557' . random_int(1000000, 9999999), 'status' => 'active']);
            $box = $this->chatBoxFor($a['business'], $prospect->phone);
            $member = AgencyProspectCampaignMember::create([
                'workspace_id' => $a['workspace']->id, 'campaign_id' => $a['campaign']->id, 'prospect_id' => $prospect->id,
                'enrolled_at' => now(), 'chat_box_id' => $box->id, 'last_inbound_at' => now(), 'last_outbound_at' => now(), 'stage' => 2,
            ]);
            AgencyProspectMessage::create(['workspace_id' => $a['workspace']->id, 'campaign_member_id' => $member->id, 'direction' => 'outbound', 'purpose' => 'initial', 'operation_key' => 'bulk' . uniqid() . $i, 'body' => 'hi', 'status' => 'sent']);
            AgencyProspectCampaign::create(['workspace_id' => $a['workspace']->id, 'name' => "Extra {$i}", 'status' => 'draft', 'sending_mode' => 'managed']);
        }
    }

    public function test_the_list_tabs_cost_the_same_number_of_queries_for_a_few_rows_as_for_many(): void
    {
        $a = $this->outreachAgency('Alpha');
        $this->authenticateAs($a['customer']);
        $tabs = ['prospects.index', 'conversations.index', 'campaigns.index', 'overview'];

        $this->grow($a, 2);
        $small = [];
        foreach ($tabs as $tab) {
            $this->get($this->outreachRoute($tab, $a['workspace']))->assertOk(); // warm per-request caches/config
            $small[$tab] = $this->queryCount($this->outreachRoute($tab, $a['workspace']));
        }

        $this->grow($a, 12);

        foreach ($tabs as $tab) {
            $this->assertSame($small[$tab], $this->queryCount($this->outreachRoute($tab, $a['workspace'])), "{$tab} issues queries per row.");
        }
    }
}
