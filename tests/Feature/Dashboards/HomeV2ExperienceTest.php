<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Library\Dashboard\DashboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * The final Home, end to end through the production presenter and view: the two-column experience, the new-business "Get set up"
 * Home, the three growth cards and what each may honestly say (a dash is not a zero, "First month" is not "Same"), the one short
 * chart, the Needs-you destinations, and the filtered lists the cards open.
 */
class HomeV2ExperienceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function home(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->get(route('user.home', $query));
    }

    private function minutesAgo(int $minutes): string
    {
        return \Carbon\CarbonImmutable::now()->subMinutes($minutes)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /** The one card for a KPI key, as markup. */
    private function kpi(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('#<(a|div)\b[^>]*data-role="kpi" data-kpi="' . $key . '".*?</\1>#s', $html, $m), "The {$key} card renders once.");

        return $m[0];
    }

    private function youngBusiness(\App\Models\Business $business, string $createdAt): void
    {
        DB::table('businesses')->where('id', $business->id)->update(['created_at' => $createdAt]);
    }

    // ── Layout ───────────────────────────────────────────────────────────

    public function test_an_established_business_gets_the_two_column_home_in_the_approved_order(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Layout Venue', 'Layout Account');
        $this->contactsAdded($business, 2, '2026-09-02');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-home-layout="home"', $html);
        $this->assertStringContainsString('Good ', $html);
        $this->assertStringContainsString("Here's what's happening with your business.", html_entity_decode($html, ENT_QUOTES));

        $main = strpos($html, 'class="hv2-main"');
        $side = strpos($html, 'class="hv2-side"');
        $this->assertNotFalse($main);
        $this->assertGreaterThan($main, $side, 'The side column follows the main column, so it stacks beneath it on tablet and mobile.');

        // Left: Needs you, then Ask, then the growth section. Right: Done for you, then Suggested next.
        $order = array_map(fn (string $marker) => strpos($html, $marker), [
            'data-role="needs-you"', 'data-band="ask"', 'data-role="home-growth"', 'data-role="home-handling"',
        ]);
        $this->assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertSame(3, substr_count($html, 'data-role="kpi" data-kpi='), 'Exactly three growth cards.');
    }

    public function test_the_chat_surface_is_still_the_existing_ask_card_with_at_most_two_chips(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Ask Venue', 'Ask Account');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-band="ask"'));
        $this->assertStringContainsString('data-role="ask-form"', $html);
        $this->assertStringContainsString('data-role="ask-history"', $html);
        $this->assertLessThanOrEqual(2, substr_count($html, 'data-role="ask-suggestion"'));
    }

    // ── The new-business Home ───────────────────────────────────────────

    public function test_a_new_business_sees_get_set_up_with_progress_from_saved_state_and_exactly_one_highlighted_step(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Newbie Venue', 'Newbie Account');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-home-layout="setup"', $html);
        $this->assertStringContainsString('data-role="home-setup"', $html);
        preg_match_all('/data-role="setup-step" data-step="([^"]+)"\s+data-done="(\w+)" data-next="(\w+)"/', $html, $steps, PREG_SET_ORDER);
        $this->assertSame(1, count(array_filter($steps, fn (array $step) => $step[3] === 'true')), 'One step is highlighted.');
        $this->assertSame('location', collect($steps)->firstWhere(3, 'true')[1], 'The highlighted step is the first one still to do.');
        $this->assertStringContainsString('data-role="results-later"', $html, 'A quiet note says results will appear once there is activity.');
        $this->assertStringNotContainsString('data-role="home-growth"', $html, 'No results, so no growth section.');
        $this->assertStringNotContainsString("You're all caught up", html_entity_decode($html, ENT_QUOTES));
        $this->assertSame(0, count(array_filter($steps, fn (array $step) => $step[2] === 'true')), 'Nothing is done until saved state says so.');
        $this->assertMatchesRegularExpression('#data-role="setup-progress".*?0 of \d+#s', $html);

        // A published website is a persisted fact: the step is done, and the count moves with it.
        $this->website($business, 'published');
        $after = $this->home()->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-step="website"\s+data-done="true"#', $after);
        $this->assertMatchesRegularExpression('#data-role="setup-progress".*?1 of \d+#s', $after);
    }

    public function test_a_finished_business_leaves_the_setup_home(): void
    {
        [$customer, $business] = $this->bareTenant(WorkspacePlanTier::Growth, 'Graduate Venue', 'Graduate Account');
        $this->authenticateAs($customer);
        $this->assertStringContainsString('data-home-layout="setup"', $this->home()->assertOk()->getContent());

        $this->completeCalendarSetup($business);

        $this->assertStringContainsString('data-home-layout="home"', $this->home()->assertOk()->getContent());
    }

    // ── The three growth cards: what each may honestly say ──────────────

    public function test_an_observed_zero_in_both_periods_says_same_and_never_a_dash(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Zero Venue', 'Zero Account');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $contacts = $this->kpi($html, 'contacts');

        $this->assertStringContainsString('data-role="headline-figure">0<', $contacts);
        $this->assertStringContainsString('data-tone="same"', $contacts);
        $this->assertStringContainsString('>Same<', $contacts);
    }

    public function test_a_business_with_no_earlier_period_says_first_month_and_shows_no_arrow(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Young Venue', 'Young Account');
        $this->youngBusiness($business, '2026-09-05 12:00:00');
        $this->contactsAdded($business, 2, '2026-09-06');
        $this->authenticateAs($customer);

        $contacts = $this->kpi($this->home(['range' => 'this_month'])->assertOk()->getContent(), 'contacts');

        $this->assertStringContainsString('First month', $contacts);
        $this->assertStringContainsString('data-tone="neutral"', $contacts);
        $this->assertStringNotContainsString('▲', $contacts);
        $this->assertStringNotContainsString('▼', $contacts);
    }

    public function test_growth_is_a_green_arrow_with_the_difference_and_a_decline_is_amber_information(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Move Venue', 'Move Account');
        // Sep 1–10 against the ten days before it.
        $this->contactsAdded($business, 4, '2026-09-02');
        $this->contactsAdded($business, 1, '2026-08-25');
        $this->conversationsStarted($business, 1, '2026-09-02');
        $this->conversationsStarted($business, 3, '2026-08-25');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $up = $this->kpi($html, 'contacts');
        $this->assertStringContainsString('▲ 3', $up);
        $this->assertStringContainsString('data-tone="up"', $up);

        $down = $this->kpi($html, 'conversations');
        $this->assertStringContainsString('▼ 2', $down);
        $this->assertStringContainsString('data-tone="down"', $down);
        $this->assertDoesNotMatchRegularExpression('/\b(error|failed|alert)\b/i', strip_tags($down), 'A decline is information, not an error.');
    }

    public function test_every_card_opens_a_filtered_list_for_exactly_its_period(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Link Venue', 'Link Account');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        foreach (['contacts', 'conversations'] as $key) {
            $card = $this->kpi($html, $key);
            $this->assertStringContainsString('created_from=2026-09-01', html_entity_decode($card), "{$key} carries the period start.");
            $this->assertStringContainsString('created_to=2026-09-10', html_entity_decode($card), "{$key} carries the period end.");
            $this->assertStringContainsString('<a ', $card, "{$key} is a real link, not a dead card.");
        }
    }

    public function test_a_card_the_actor_cannot_open_is_not_a_link(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Perm Venue', 'Perm Account');
        $this->authenticateAs($customer, ['view_reports']);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('<a class="hv2-kpi" href', $html, 'No link to a list this actor may not open.');
        $this->assertStringContainsString('data-kpi="contacts"', $html, 'The figure itself still shows: it is theirs to see.');
    }

    public function test_revenue_is_never_a_metric_and_the_quiet_prompt_leads_to_payments(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Money Venue', 'Money Account');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('data-kpi="revenue"', $html);
        $this->assertStringContainsString('data-role="revenue-prompt"', $html);
        $this->assertMatchesRegularExpression('#data-role="revenue-prompt".*?Track revenue.*?data-role="revenue-connect"#s', $html);
    }

    public function test_a_source_that_cannot_be_read_is_unavailable_never_zero_and_never_all_clear(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Broken Reads Venue', 'Broken Reads Account');
        $this->contactsAdded($business, 3, '2026-09-02');
        $this->authenticateAs($customer);

        $this->partialMock(BusinessAnalyticsQueries::class, function ($mock) {
            $mock->shouldReceive('messageKpis')->andThrow(new RuntimeException('Results store unavailable'));
        });

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-role="growth-unavailable"', $html);
        $this->assertStringNotContainsString('data-kpi="contacts"', $html, 'No figure is better than a wrong figure.');
        $this->assertStringNotContainsString("You're all caught up", html_entity_decode($html, ENT_QUOTES), 'A failed read is never an all-clear.');
        $this->assertStringNotContainsString('Results store unavailable', $html, 'No internal error text reaches the page.');
    }

    // ── The one chart ───────────────────────────────────────────────────

    public function test_the_chart_shows_real_data_for_a_young_business_and_only_explains_an_empty_series(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Chart Venue', 'Chart Account');
        $this->contactsAdded($business, 3, '2026-09-02');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-too-early="0"', $html);
        $this->assertStringContainsString('New contacts per day', $html);
        $this->assertStringNotContainsString('Leads', $html, 'No Leads/Bookings switch exists: there is no bookings series.');
        $this->assertStringContainsString(e(route('customer.workspaces.businesses.analytics.series', [$workspace->uid, $business->uid, 'range' => 'this_month'])), $html);

        $this->youngBusiness($business, '2026-09-08 09:00:00');
        $young = $this->home()->assertOk()->getContent();
        // The business is under a week old but already has contacts: the chart is requested and drawn, never hidden for presentation.
        $this->assertStringContainsString('data-too-early="1"', $young, 'Only a hint for the empty-series wording.');
        $this->assertStringContainsString('data-role="chart-plot"', $young);
        $this->assertStringContainsString('data-series-url=', $young);
        $this->assertStringNotContainsString('data-role="chart-too-early"', $young, 'Real data is never replaced by the first-week line.');
    }

    // ── Needs you ───────────────────────────────────────────────────────

    public function test_waiting_customers_lead_to_exactly_the_filtered_conversations(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Reply Venue', 'Reply Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(40)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(50)]]);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-kind="waiting"', $html);
        $this->assertStringContainsString('2 customers need a reply', $html);
        $this->assertMatchesRegularExpression('#href="([^"]*filter=waiting[^"]*)"[^>]*data-role="next-best-move-action">Reply now#', $html);

        preg_match('#href="([^"]*filter=waiting[^"]*)"[^>]*data-role="next-best-move-action"#', $html, $m);
        $this->get(html_entity_decode($m[1]))->assertOk();
    }

    public function test_the_all_clear_is_honest_only_when_nothing_needs_the_customer_and_every_source_read(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Calm Venue', 'Calm Account');
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-role="caught-up"', $html);
        $this->assertStringContainsString("You're all caught up", html_entity_decode($html, ENT_QUOTES));
        $this->assertStringNotContainsString('data-role="next-best-move"', $html);
    }

    // ── Done for you vs Suggested next ──────────────────────────────────

    public function test_done_for_you_and_suggested_next_are_separate_and_suggestions_are_capped_at_three(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Sections Venue', 'Sections Account');
        $this->website($business, 'published');
        $this->websiteRevision($business, 1, $this->minutesAgo(120));
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $done = $this->bandHtml($html, 'handling');
        $suggested = $this->bandHtml($html, 'suggested');

        $this->assertNotSame('', $done);
        $this->assertLessThanOrEqual(3, substr_count($suggested, 'data-role="suggested-item"'));
        $this->assertStringNotContainsString('data-role="suggested-item"', $done, 'A suggestion is never listed as done.');
        $this->assertStringNotContainsString('Publish your website', $suggested, 'A completed step is never suggested again.');
    }

    private function bandHtml(string $html, string $band): string
    {
        $start = strpos($html, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    // ── The filtered lists the cards open ───────────────────────────────

    public function test_the_contact_list_shows_only_contacts_added_in_the_linked_period(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'List Venue', 'List Account');
        $this->contactsAdded($business, 1, '2026-09-03');
        $this->contactsAdded($business, 1, '2026-08-15');
        $this->authenticateAs($customer);

        $route = fn (array $query) => route('customer.workspaces.businesses.people.index', array_merge([$workspace->uid, $business->uid], $query));
        $inside = DB::table('contacts')->where('business_id', $business->id)->orderBy('id')->value('phone');
        $outside = DB::table('contacts')->where('business_id', $business->id)->orderByDesc('id')->value('phone');

        $filtered = $this->get($route(['created_from' => '2026-09-01', 'created_to' => '2026-09-10']))->assertOk()->getContent();
        $this->assertStringContainsString(substr($inside, -4), $filtered);
        $this->assertStringNotContainsString(substr($outside, -4), $filtered);

        // Anything that is not a valid range is ignored: the list is simply unfiltered, never an error.
        foreach ([['created_from' => 'nope', 'created_to' => '2026-09-10'], ['created_from' => '2026-09-10', 'created_to' => '2026-09-01'], ['created_from' => '2026-09-01']] as $bad) {
            $content = $this->get($route($bad))->assertOk()->getContent();
            $this->assertStringContainsString(substr($inside, -4), $content);
            $this->assertStringContainsString(substr($outside, -4), $content);
        }
    }

    public function test_the_home_snapshot_still_carries_the_command_band_the_presenter_reads(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth, 'Band Venue', 'Band Account');
        $this->authenticateAs($customer);

        $home = $this->dashboardFor($customer->user)->band(DashboardSnapshot::BAND_COMMAND);

        $this->assertSame('home', $home['v2']['layout']);
        $this->assertCount(3, array_filter($home['v2']['growth']['kpis'], fn (array $kpi) => in_array($kpi['key'], ['bookings', 'contacts', 'conversations'], true)));
    }
}
