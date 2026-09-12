<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unified Home §3.1 (A-1) — the Agency Account-level portfolio.
 *
 * The portfolio answers three questions with persisted facts only: which
 * client needs the owner (unchanged), how each client did over the selected
 * period, and what outreach actually did. Capacity and account billing take
 * Home space only when they need an action.
 *
 * T-AGY-1 outreach truth table, and Positive replies absent until A-2
 * T-AGY-2 grouped aggregates, flat query count as clients multiply
 * T-AGY-3 an Agency-opened Business is the ordinary Business Home
 */
class AgencyAccountHomePortfolioTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    private int $prospectSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    // =================================================================
    // T-AGY-1 — the outreach truth table
    // =================================================================

    /**
     * Every figure is the persisted `agency_prospect_*` fact for the period,
     * and each one is fenced by a row that must NOT be counted: an outbound
     * sent before the period, a reply received before it, a booking made
     * before it, and a failure recorded before it.
     */
    public function test_the_outreach_band_reports_exactly_the_persisted_prospecting_facts(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $campaign = $this->campaign($workspace);

        $inPeriod = '2026-09-04 10:00:00';
        $beforePeriod = '2026-08-20 10:00:00';

        // Contacted: two members reached inside the period. The first was
        // messaged twice and still counts once; the third member's only send
        // was before the period.
        $first = $this->member($workspace, $campaign, $this->prospect($workspace, bookedAt: $inPeriod));
        $second = $this->member($workspace, $campaign, $this->prospect($workspace));
        $third = $this->member($workspace, $campaign, $this->prospect($workspace, bookedAt: $beforePeriod));

        $this->outbound($workspace, $first, sentAt: $inPeriod);
        $this->outbound($workspace, $first, sentAt: '2026-09-06 09:00:00');
        $this->outbound($workspace, $second, sentAt: $inPeriod);
        $this->outbound($workspace, $third, sentAt: $beforePeriod);

        // Replies: two inside the period, one before it.
        $this->inbound($workspace, $first, receivedAt: $inPeriod);
        $this->inbound($workspace, $first, receivedAt: '2026-09-07 08:00:00');
        $this->inbound($workspace, $second, receivedAt: $beforePeriod);

        // Failures: a failed send never gets a sent_at, so its own row
        // timestamp places it. One inside the period, one before it.
        $this->failedOutbound($workspace, $second, createdAt: $inPeriod);
        $this->failedOutbound($workspace, $third, createdAt: $beforePeriod);

        $this->authenticateAs($agency);
        $html = $this->home()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-role="prospecting-contacted">2</', $html, 'Distinct members reached in the period.');
        $this->assertMatchesRegularExpression('/data-role="prospecting-replies">2</', $html, 'Inbound messages received in the period.');
        $this->assertMatchesRegularExpression('/data-role="prospecting-booked">1</', $html, 'Prospects booked in the period.');
        $this->assertMatchesRegularExpression('/data-role="prospecting-failures">1</', $html, 'Outbound sends that failed in the period.');

        // Read back what the figures claim to be, straight from the rows.
        $this->assertSame(2, (int) DB::table('agency_prospect_messages')
            ->where('workspace_id', $workspace->id)->where('direction', 'outbound')
            ->whereBetween('sent_at', ['2026-09-01 00:00:00', '2026-09-30 23:59:59'])
            ->distinct()->count('campaign_member_id'));
        $this->assertSame(1, (int) DB::table('agency_prospects')
            ->where('workspace_id', $workspace->id)
            ->whereBetween('booked_at', ['2026-09-01 00:00:00', '2026-09-30 23:59:59'])->count());
    }

    /**
     * A-2 — the intent is now persisted, so a truthful Positive replies
     * count can appear. A reply with no persisted intent (never
     * classified, or classification failed) never counts.
     */
    public function test_positive_replies_counts_only_persisted_positive_intent(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $campaign = $this->campaign($workspace);
        $member = $this->member($workspace, $campaign, $this->prospect($workspace));
        $this->outbound($workspace, $member, sentAt: '2026-09-04 10:00:00');
        $this->inbound($workspace, $member, receivedAt: '2026-09-05 10:00:00');

        $this->authenticateAs($agency);
        $html = $this->home()->assertOk()->getContent();
        $main = $this->mainText($html);

        $this->assertStringContainsString('Positive replies', $main);
        $this->assertMatchesRegularExpression('/data-role="prospecting-positive">0</', $html, 'A reply with no persisted intent never counts.');
        // Still no rate derived from the figures it shows.
        $this->assertDoesNotMatchRegularExpression('/\b(reply rate|response rate|conversion)\b/i', $main);
    }

    /**
     * Every non-positive canonical intent, plus a never-classified reply,
     * must be excluded — the metric is never "all replies", never
     * "booking or scheduling implies positive", and never a guess.
     */
    public function test_only_the_positive_intent_counts_every_other_intent_is_excluded(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $campaign = $this->campaign($workspace);
        $member = $this->member($workspace, $campaign, $this->prospect($workspace));

        $nonPositiveIntents = ['question', 'qualification', 'booking', 'scheduling', 'soft_negative', 'hard_negative', 'other'];

        foreach ($nonPositiveIntents as $intent) {
            $this->inboundWithIntent($workspace, $member, receivedAt: '2026-09-05 10:00:00', intent: $intent);
        }
        $this->inbound($workspace, $member, receivedAt: '2026-09-05 10:00:00'); // null intent, never classified
        $this->inboundWithIntent($workspace, $member, receivedAt: '2026-09-06 10:00:00', intent: 'positive');
        $this->inboundWithIntent($workspace, $member, receivedAt: '2026-09-07 10:00:00', intent: 'positive');

        $this->authenticateAs($agency);
        $html = $this->home()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-role="prospecting-positive">2</', $html);
        $this->assertMatchesRegularExpression('/data-role="prospecting-replies">10</', $html, 'Every inbound message still counts toward Replies, whatever its intent.');
    }

    /**
     * The positive count follows the same selected period as every other
     * outreach figure, with the same half-open boundaries A-1 already
     * proved for the rest of the band.
     */
    public function test_positive_replies_follows_the_selected_period_with_half_open_boundaries(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $campaign = $this->campaign($workspace);
        $member = $this->member($workspace, $campaign, $this->prospect($workspace));

        $this->inboundWithIntent($workspace, $member, receivedAt: '2026-09-01 00:00:00', intent: 'positive'); // first instant of the month: in
        $this->inboundWithIntent($workspace, $member, receivedAt: '2026-08-31 23:59:59', intent: 'positive'); // last instant of August: out

        $this->authenticateAs($agency);
        $thisMonth = $this->home()->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="prospecting-positive">1</', $thisMonth, 'The first instant of the month is inside it.');

        $lastMonth = $this->get(route('user.home', ['range' => 'last_month']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="prospecting-positive">1</', $lastMonth, 'The last instant of August belongs to August.');
    }

    /** A rival Agency's positive replies must never leak into this count. */
    public function test_a_rival_agencys_positive_replies_are_never_counted(): void
    {
        [$mine, , $myWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($mine, $myWorkspace, 'Zulu Zoo');
        $myCampaign = $this->campaign($myWorkspace);
        $myMember = $this->member($myWorkspace, $myCampaign, $this->prospect($myWorkspace));
        $this->inboundWithIntent($myWorkspace, $myMember, receivedAt: '2026-09-05 10:00:00', intent: 'positive');

        [$theirs, , $theirWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Rival Clinic', 'Rival Agency');
        $theirCampaign = $this->campaign($theirWorkspace);
        $theirMember = $this->member($theirWorkspace, $theirCampaign, $this->prospect($theirWorkspace));
        $this->inboundWithIntent($theirWorkspace, $theirMember, receivedAt: '2026-09-05 10:00:00', intent: 'positive');
        $this->inboundWithIntent($theirWorkspace, $theirMember, receivedAt: '2026-09-05 11:00:00', intent: 'positive');

        $this->authenticateAs($mine);
        $html = $this->home()->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-role="prospecting-positive">1</', $html, "The rival's two positive replies are not in this total.");
    }

    /**
     * The outreach statement stays exactly one query regardless of how many
     * messages exist — the new filter is an extra column on the same
     * statement, never an extra round trip.
     */
    public function test_positive_replies_adds_no_query_and_stays_flat_as_messages_multiply(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $campaign = $this->campaign($workspace);
        $member = $this->member($workspace, $campaign, $this->prospect($workspace));

        for ($i = 0; $i < 5; $i++) {
            $this->inboundWithIntent($workspace, $member, receivedAt: '2026-09-05 10:00:00', intent: 'positive');
        }

        $this->authenticateAs($agency);
        $few = $this->portfolioSql($agency->user);

        for ($i = 0; $i < 50; $i++) {
            $this->inboundWithIntent($workspace, $member, receivedAt: '2026-09-05 10:00:00', intent: 'positive');
        }

        $many = $this->portfolioSql($agency->user);

        $this->assertSame(1, $this->countMatching($few, '/agency_prospect_messages/'), 'The outreach truth table is one statement.');
        $this->assertSame(1, $this->countMatching($many, '/agency_prospect_messages/'), 'Fifty times the messages: still one statement.');
        $this->assertSame(count($few), count($many), 'The whole Agency Home must cost the same regardless of message volume.');

        $html = $this->home()->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="prospecting-positive">55</', $html);
    }

    /** No AI call is ever made just to render the Positive replies figure. */
    public function test_positive_replies_never_triggers_an_ai_call(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $campaign = $this->campaign($workspace);
        $member = $this->member($workspace, $campaign, $this->prospect($workspace));
        $this->inboundWithIntent($workspace, $member, receivedAt: '2026-09-05 10:00:00', intent: 'positive');

        Http::fake();
        $this->app->bind(AgencyProspectingAiClient::class, function () {
            $this->fail('Rendering Positive replies must never resolve an AI client.');
        });

        $this->authenticateAs($agency);
        $this->home()->assertOk();

        Http::assertNothingSent();
    }

    // =================================================================
    // T-AGY-2 — grouped aggregates, flat cost
    // =================================================================

    /**
     * Doubling the clients must not add a statement. The band's whole reason
     * to exist is that the portfolio cannot afford a per-client query.
     */
    public function test_cross_client_performance_is_grouped_and_its_query_count_does_not_grow(): void
    {
        [$agency, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $bravo = $this->addBusiness($agency, $workspace, 'Bravo Bistro');

        foreach ([$alpha, $bravo] as $client) {
            $this->contactsAdded($client, 2, '2026-09-02');
            $this->conversationsStarted($client, 1, '2026-09-02');
        }

        $this->authenticateAs($agency);
        $two = $this->portfolioSql($agency->user);

        foreach (['Charlie Cafe', 'Delta Deli'] as $name) {
            $client = $this->addBusiness($agency, $workspace, $name);
            $this->contactsAdded($client, 3, '2026-09-03');
            $this->conversationsStarted($client, 2, '2026-09-03');
        }

        $four = $this->portfolioSql($agency->user);

        $this->assertSame(1, $this->countMatching($two, '/\bcontacts\b/'), 'Two clients: one contacts statement.');
        $this->assertSame(1, $this->countMatching($four, '/\bcontacts\b/'), 'Four clients: still one contacts statement.');
        $this->assertSame(1, $this->countMatching($two, '/chat_boxes/'), 'Two clients: one conversations statement.');
        $this->assertSame(1, $this->countMatching($four, '/chat_boxes/'), 'Four clients: still one conversations statement.');
        $this->assertSame(count($two), count($four), 'The whole Agency Home must cost the same for twice the clients: ' . implode(' | ', $four));

        // And they really are grouped aggregates over an id list.
        $contacts = $this->firstMatching($four, '/\bcontacts\b/');
        $conversations = $this->firstMatching($four, '/chat_boxes/');

        foreach (['contacts' => $contacts, 'chat_boxes' => $conversations] as $label => $statement) {
            $this->assertMatchesRegularExpression('/group by/i', $statement, "{$label} must be a grouped aggregate: {$statement}");
            $this->assertMatchesRegularExpression('/in \(/i', $statement, "{$label} must cover every client id at once: {$statement}");
            $this->assertMatchesRegularExpression('/count\(/i', $statement, "{$label}: {$statement}");
        }
    }

    /** Each client keeps its own figures; they are never pooled or swapped. */
    public function test_each_client_row_carries_its_own_period_figures(): void
    {
        [$agency, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $bravo = $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->addBusiness($agency, $workspace, 'Charlie Cafe');

        $this->contactsAdded($alpha, 5, '2026-09-02');
        $this->conversationsStarted($alpha, 2, '2026-09-02');
        $this->contactsAdded($bravo, 1, '2026-09-03');

        $this->authenticateAs($agency);
        $rows = $this->crossClientRows($this->home()->assertOk()->getContent());

        $this->assertSame(['Alpha Dental', 'Bravo Bistro', 'Charlie Cafe', 'All client accounts'], array_column($rows, 'name'));
        $this->assertSame(['5', '2'], [$rows[0]['contacts'], $rows[0]['conversations']]);
        $this->assertSame(['1', '0'], [$rows[1]['contacts'], $rows[1]['conversations']]);
        $this->assertSame(['0', '0'], [$rows[2]['contacts'], $rows[2]['conversations']], 'A client with nothing shows 0, not a guess.');
        $this->assertSame(['6', '2'], [$rows[3]['contacts'], $rows[3]['conversations']]);
    }

    // =================================================================
    // T-AGY-3 — an Agency-opened Business is the ordinary Business Home
    // =================================================================

    public function test_a_client_opened_by_its_agency_gets_the_ordinary_business_home_plus_the_frame(): void
    {
        [$agency, $client, $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->populateBusiness($client);

        [$owner, $solo] = $this->tenant(WorkspacePlanTier::Growth, 'Solo Venue', 'Solo Account');
        $this->populateBusiness($solo);

        $this->authenticateAs($owner);
        $ordinary = $this->dashboardFor($owner->user);

        $this->authenticateAs($agency);
        $this->switchTo($agencyWorkspace, $client)->assertRedirect(route('user.home'));
        $viaAgency = $this->dashboardFor($agency->user);

        $this->assertSame(DashboardSnapshot::KIND_BUSINESS, $viaAgency->kind);
        $this->assertSame(
            array_keys($ordinary->bands),
            array_keys($viaAgency->bands),
            'An Agency-opened client gets the same Business Home bands as its owner would.'
        );

        // The only difference is the Agency framing.
        $this->assertSame('Business home', $ordinary->frameLabel);
        $this->assertSame('Client account home', $viaAgency->frameLabel);

        // And no portfolio figure follows the actor in.
        foreach ([DashboardSnapshot::BAND_CROSS_CLIENT, DashboardSnapshot::BAND_CLIENTS, DashboardSnapshot::BAND_PROSPECTING] as $agencyBand) {
            $this->assertFalse($viaAgency->has($agencyBand), "{$agencyBand} must never appear on a Business Home.");
        }
    }

    public function test_no_agency_portfolio_figure_is_rendered_on_a_client_business_home(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $campaign = $this->campaign($workspace);
        $this->outbound($workspace, $this->member($workspace, $campaign, $this->prospect($workspace)), sentAt: '2026-09-04 10:00:00');

        $this->authenticateAs($agency);
        $this->switchTo($workspace, $client);
        $html = $this->home()->assertOk()->getContent();
        $main = $this->mainText($html);

        $this->assertStringContainsString('data-kind="business"', $html);
        $this->assertStringNotContainsString('data-band="cross_client"', $html);
        $this->assertStringNotContainsString('data-band="prospecting"', $html);
        $this->assertStringNotContainsString('data-band="clients"', $html);
        $this->assertStringNotContainsString('Client performance', $main);
        $this->assertStringNotContainsString('Prospects contacted', $main);
        $this->assertStringNotContainsString('Bravo Bistro', $main, 'One client never sees another.');
    }

    // =================================================================
    // Tenancy
    // =================================================================

    public function test_one_agency_never_reads_another_agencys_clients_or_outreach(): void
    {
        [$mine, $alpha, $myWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $mineSecond = $this->addBusiness($mine, $myWorkspace, 'Zulu Zoo');
        $this->contactsAdded($alpha, 2, '2026-09-02');
        $myCampaign = $this->campaign($myWorkspace);
        $this->outbound($myWorkspace, $this->member($myWorkspace, $myCampaign, $this->prospect($myWorkspace)), sentAt: '2026-09-04 10:00:00');

        [$theirs, $theirClient, $theirWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Rival Clinic', 'Rival Agency');
        $this->contactsAdded($theirClient, 9, '2026-09-02');
        $this->conversationsStarted($theirClient, 9, '2026-09-02');
        $theirCampaign = $this->campaign($theirWorkspace);
        $theirMember = $this->member($theirWorkspace, $theirCampaign, $this->prospect($theirWorkspace, bookedAt: '2026-09-05 10:00:00'));
        $this->outbound($theirWorkspace, $theirMember, sentAt: '2026-09-04 10:00:00');
        $this->inbound($theirWorkspace, $theirMember, receivedAt: '2026-09-05 10:00:00');
        $this->failedOutbound($theirWorkspace, $theirMember, createdAt: '2026-09-05 10:00:00');

        $this->authenticateAs($mine);
        $html = $this->home()->assertOk()->getContent();
        $rows = $this->crossClientRows($html);

        $this->assertSame(['Alpha Dental', 'Zulu Zoo', 'All client accounts'], array_column($rows, 'name'));
        $this->assertSame(['2', '0'], [$rows[0]['contacts'], $rows[0]['conversations']]);
        $this->assertSame(['0', '0'], [$rows[1]['contacts'], $rows[1]['conversations']]);
        $this->assertSame(['2', '0'], [$rows[2]['contacts'], $rows[2]['conversations']], "The rival's 9 and 9 are not in this total.");
        $this->assertStringNotContainsString('Rival Clinic', $this->mainText($html));

        $this->assertMatchesRegularExpression('/data-role="prospecting-contacted">1</', $html);
        $this->assertMatchesRegularExpression('/data-role="prospecting-replies">0</', $html, "The rival's reply is not counted here.");
        $this->assertMatchesRegularExpression('/data-role="prospecting-booked">0</', $html);
        $this->assertMatchesRegularExpression('/data-role="prospecting-failures">0</', $html);
    }

    // =================================================================
    // Period
    // =================================================================

    /**
     * The window is half-open in the Account's own calendar: the first
     * instant of the month counts, the last instant of the month before it
     * does not, and choosing "Last month" moves the window rather than
     * widening it.
     */
    public function test_the_period_boundaries_are_half_open_and_the_selection_moves_the_window(): void
    {
        [$agency, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');

        $this->contactsAt($alpha, 1, '2026-09-01 00:00:00');
        $this->contactsAt($alpha, 1, '2026-08-31 23:59:59');
        $this->conversationsAt($alpha, 1, '2026-09-01 00:00:00');
        $this->conversationsAt($alpha, 1, '2026-08-31 23:59:59');

        $this->authenticateAs($agency);

        $thisMonth = $this->crossClientRows($this->home()->assertOk()->getContent());
        $this->assertSame(['1', '1'], [$thisMonth[0]['contacts'], $thisMonth[0]['conversations']], 'The first instant of the month is inside it.');

        $lastMonth = $this->crossClientRows($this->get(route('user.home', ['range' => 'last_month']))->assertOk()->getContent());
        $this->assertSame(['1', '1'], [$lastMonth[0]['contacts'], $lastMonth[0]['conversations']], 'The last instant of August belongs to August.');
    }

    public function test_the_period_control_offers_the_canonical_presets_and_ignores_anything_else(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->authenticateAs($agency);

        $html = $this->home()->assertOk()->getContent();

        foreach (['this_month', 'last_month', 'last_7_days', 'last_30_days', 'last_90_days'] as $preset) {
            $this->assertStringContainsString('data-period="' . $preset . '"', $html);
        }
        $this->assertStringNotContainsString('data-period="custom"', $html);
        $this->assertMatchesRegularExpression('/data-role="cross-client-span"[^>]*>\s*This month/', $html, 'This month is the default.');

        // A hand-edited range simply leaves the default selected; Home never
        // renders a window nobody asked for.
        $nonsense = $this->get(route('user.home', ['range' => 'last_1000_years']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="cross-client-span"[^>]*>\s*This month/', $nonsense);

        $chosen = $this->get(route('user.home', ['range' => 'last_7_days']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="cross-client-span"[^>]*>\s*Last 7 days/', $chosen);
        $this->assertMatchesRegularExpression('/data-role="prospecting-range"[^>]*>\s*Last 7 days/', $chosen, 'Outreach follows the same selection.');
    }

    // =================================================================
    // Capacity and billing appear only when they need an action
    // =================================================================

    public function test_capacity_appears_only_when_it_needs_an_action(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->authenticateAs($agency);

        $this->assertStringNotContainsString('data-band="capacity"', $this->home()->assertOk()->getContent(), 'An Agency with room to grow needs no capacity band.');

        // Every slot in use: a bounded catalog this Agency has filled.
        DB::table('workspace_plan_catalog')->where('tier', 'agency')->update([
            'unlimited_business_slots' => false,
            'business_slot_included' => 1,
            'business_slot_max' => 1,
        ]);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-band="capacity"', $html);
        $this->assertStringContainsString('client account slot', $this->mainText($html));
    }

    /**
     * Capacity asks the canonical decision, so it covers the plan states too
     * — but those cannot be reached from this frame, and that is worth
     * pinning rather than leaving as an untested branch.
     *
     * The Agency frame exists only while an ACTIVE plan assignment names the
     * Agency tier (CustomerContextSnapshot joins assignments on
     * status = active). Suspend or deactivate the plan and the actor stops
     * being an Agency frame at all: they land on the account chooser, where
     * no Home band claims anything about capacity. Changing who sees the
     * Agency frame is navigation's decision, not this band's.
     */
    public function test_a_plan_that_is_no_longer_active_takes_the_actor_off_the_agency_frame_entirely(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->authenticateAs($agency);

        $this->assertStringContainsString('data-kind="agency"', $this->home()->assertOk()->getContent());

        DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->update(['status' => 'suspended']);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('data-kind="agency"', $html);
        $this->assertStringNotContainsString('data-band="capacity"', $html);
        $this->assertStringNotContainsString('data-band="cross_client"', $html);
    }

    public function test_account_billing_appears_only_for_an_actionable_problem(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->authenticateAs($agency);

        $routine = $this->home()->assertOk()->getContent();
        $this->assertStringNotContainsString('data-band="account"', $routine);
        $this->assertStringNotContainsString('Spent this month', $this->mainText($routine));
        $this->assertStringNotContainsString('Agency-wide monthly limit', $this->mainText($routine));

        DB::table('workspace_usage_controls')->insert([
            'workspace_id' => $workspace->id,
            'paid_activity_paused_at' => '2026-09-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-band="account"', $html);
        $this->assertStringContainsString('Paid activity is paused', $this->mainText($html));
    }

    // =================================================================
    // No AI
    // =================================================================

    /**
     * The Agency Account Home makes no AI call and resolves no provider
     * client: a cross-client summary would put several clients' facts in one
     * prompt, which §15 forbids.
     */
    public function test_the_agency_account_home_resolves_no_ai_client_and_calls_no_provider(): void
    {
        [$agency, $alpha, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->contactsAdded($alpha, 3, '2026-09-02');
        $campaign = $this->campaign($workspace);
        $this->outbound($workspace, $this->member($workspace, $campaign, $this->prospect($workspace)), sentAt: '2026-09-04 10:00:00');

        Http::fake();

        foreach ([AgencyProspectingAiClient::class, WebsiteAiGenerationClient::class] as $aiClient) {
            $this->app->bind($aiClient, function () use ($aiClient) {
                $this->fail('The Agency Account Home resolved ' . $aiClient . '.');
            });
        }

        $this->authenticateAs($agency);
        $this->home()->assertOk();

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------

    /**
     * Every statement the Agency Account Home itself runs. The shell around
     * it does its own per-Business work, which this band neither owns nor
     * can pin, so the portfolio's cost is measured where it is incurred.
     *
     * @return array<int, string>
     */
    private function portfolioSql(\App\Models\User $user): array
    {
        $context = $this->resolvedContext($user);
        $this->warmShellEntitlements($context);
        $snapshot = null;

        $sql = $this->sqlDuring(function () use ($context, $user, &$snapshot) {
            $snapshot = app(\App\Library\Dashboard\AccountHomePresenter::class)->present($context, $user);
        });

        $this->assertSame(DashboardSnapshot::KIND_AGENCY, $snapshot->kind);
        $this->assertSame([], $snapshot->failedBands);

        return $sql;
    }

    /**
     * @param  array<int, string>  $sql
     */
    private function countMatching(array $sql, string $pattern): int
    {
        return count(array_filter($sql, fn (string $statement) => preg_match($pattern, $statement) === 1));
    }

    /**
     * @param  array<int, string>  $sql
     */
    private function firstMatching(array $sql, string $pattern): string
    {
        foreach ($sql as $statement) {
            if (preg_match($pattern, $statement) === 1) {
                return $statement;
            }
        }

        $this->fail('No statement matched ' . $pattern);
    }

    /**
     * The cross-client table as rows, the totals row last.
     *
     * @return array<int, array{name: string, contacts: string, conversations: string}>
     */
    private function crossClientRows(string $html): array
    {
        $start = strpos($html, 'data-role="cross-client-table"');
        $this->assertNotFalse($start, 'The cross-client band must render.');
        $end = strpos($html, '</table>', $start);
        $table = substr($html, $start, ($end === false ? strlen($html) : $end) - $start);

        preg_match_all('#<tr[^>]*>\s*<td[^>]*>([^<]*)</td>\s*<td[^>]*>([^<]*)</td>\s*<td[^>]*>([^<]*)</td>#s', $table, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => [
            'name' => trim(html_entity_decode($m[1])),
            'contacts' => trim($m[2]),
            'conversations' => trim($m[3]),
        ], $matches);
    }

    /** Enough Business Home data for the band set to be worth comparing. */
    private function populateBusiness(Business $business): void
    {
        $this->wallet($business);
        $this->contactsAdded($business, 2, '2026-09-02');
        $this->conversationsStarted($business, 1, '2026-09-02');
        $this->sent($business, 3, '2026-09-01');
        $this->website($business, 'published');
    }

    private function campaign(Workspace $workspace): int
    {
        return (int) DB::table('agency_prospect_campaigns')->insertGetId([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Campaign ' . Str::random(4),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function prospect(Workspace $workspace, ?string $bookedAt = null): int
    {
        return (int) DB::table('agency_prospects')->insertGetId([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'company_name' => 'Prospect ' . Str::random(4),
            'phone' => '1707555' . str_pad((string) (++$this->prospectSequence), 4, '0', STR_PAD_LEFT),
            'status' => $bookedAt === null ? 'active' : 'booked',
            'booked_at' => $bookedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function member(Workspace $workspace, int $campaignId, int $prospectId): int
    {
        return (int) DB::table('agency_prospect_campaign_members')->insertGetId([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'campaign_id' => $campaignId,
            'prospect_id' => $prospectId,
            'stage' => 1,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function outbound(Workspace $workspace, int $memberId, string $sentAt): void
    {
        $this->message($workspace, $memberId, 'outbound', 'sent', ['sent_at' => $sentAt, 'created_at' => $sentAt]);
    }

    private function failedOutbound(Workspace $workspace, int $memberId, string $createdAt): void
    {
        $this->message($workspace, $memberId, 'outbound', 'failed', ['sent_at' => null, 'created_at' => $createdAt]);
    }

    private function inbound(Workspace $workspace, int $memberId, string $receivedAt): void
    {
        $this->message($workspace, $memberId, 'inbound', 'received', ['received_at' => $receivedAt, 'created_at' => $receivedAt]);
    }

    /** A-2 — an inbound message with a persisted reply intent. */
    private function inboundWithIntent(Workspace $workspace, int $memberId, string $receivedAt, string $intent): void
    {
        $this->message($workspace, $memberId, 'inbound', 'received', ['received_at' => $receivedAt, 'created_at' => $receivedAt, 'intent' => $intent]);
    }

    /** @param  array<string, mixed>  $columns */
    private function message(Workspace $workspace, int $memberId, string $direction, string $status, array $columns): void
    {
        DB::table('agency_prospect_messages')->insert(array_merge([
            'uid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'campaign_member_id' => $memberId,
            'direction' => $direction,
            'body' => 'Fixture message',
            'status' => $status,
            'sent_at' => null,
            'received_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $columns));
    }
}
