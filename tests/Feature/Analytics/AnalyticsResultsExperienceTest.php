<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * Results — the customer-facing redesign of the B5 overview.
 *
 * What it proves: a local Business's Results lead with the outcomes this
 * product can actually measure (new contacts, new conversations, messages
 * received); outgoing volume is secondary operational health; every figure
 * is still exactly the number its source computes; the chart axis is
 * readable; and nothing is invented — no lead, source, booking, revenue or
 * email metric appears, because no canonical source for one exists yet.
 */
class AnalyticsResultsExperienceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    private const B5_SOURCE_TABLES = '/\b(reports|tracking_logs|campaigns|contacts|contact_groups|opportunities|opportunity_runs|automation_executions)\b/';

    private const TENANCY_TABLES = '/\b(workspaces|businesses|workspace_memberships|workspace_membership_businesses)\b/';

    /** A conversation row owned by $business, created at a UTC instant. */
    private function conversation(Business $business, ?string $createdAtUtc = null): int
    {
        $at = $createdAtUtc ?? now()->utc()->format('Y-m-d H:i:s');

        return DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'to' => '12025550199',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** The markup between two data-role markers, so a claim is scoped to one section. */
    private function section(string $html, string $role): string
    {
        $start = strpos($html, 'data-role="' . $role . '"');
        $this->assertNotFalse($start, "Section {$role} is rendered.");
        $end = strpos($html, '</section>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    /** The Results content area only — never the shell, navigation, footer or scripts. */
    private function resultsContent(string $html): string
    {
        $start = strpos($html, 'id="business-results"');
        $this->assertNotFalse($start);
        $end = strpos($html, '</main>', $start);
        $this->assertNotFalse($end, 'The page content closes inside <main>.');

        return substr($html, $start, $end - $start);
    }

    /** The rendered figure behind a data-role value hook. */
    private function figure(string $html, string $role): string
    {
        $this->assertMatchesRegularExpression('/data-role="' . preg_quote($role, '/') . '"[^>]*>\s*([^<]*?)\s*</', $html, "Figure {$role} is rendered.");
        preg_match('/data-role="' . preg_quote($role, '/') . '"[^>]*>\s*([^<]*?)\s*</', $html, $match);

        return $match[1];
    }

    private function seedActivity(Business $business): void
    {
        $u = $business->customer_id;
        $group = $this->group($business);
        $this->contact($business, $group);
        $this->contact($business, $group);
        $this->report($business, $u, ['customer_status' => 'Delivered']);
        $this->report($business, $u, ['customer_status' => 'Delivered|abc123']);
        $this->report($business, $u, ['customer_status' => 'Undelivered']);
        $this->report($business, $u, ['customer_status' => 'Queued']);
        $this->report($business, $u, ['direction' => 'incoming']);
        $this->report($business, $u, ['direction' => 'incoming']);
        $this->report($business, $u, ['direction' => 'incoming']);
        $this->conversation($business);
        $this->conversation($business);
    }

    // ---------------------------------------------------------------
    // Page structure and priority
    // ---------------------------------------------------------------

    public function test_results_page_owns_one_h1_and_no_title_bar_heading_precedes_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $html = $this->overview($workspace, $business)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'Exactly one h1.');
        $this->assertStringContainsString('<h1 id="results-heading" class="h3 mb-0">Results</h1>', $html);
        $this->assertStringNotContainsString('content-header-title', $html, 'The shared title bar h2 is switched off on this page.');
    }

    public function test_overview_leads_with_local_business_outcomes_and_never_outgoing_volume(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->authenticateAsCustomer($customer);

        $html = $this->overview($workspace, $business)->assertOk()->getContent();
        $overview = $this->section($html, 'results-overview');

        $this->assertStringContainsString('New contacts', $overview);
        $this->assertStringContainsString('Contacts added during this period.', $overview);
        $this->assertStringContainsString('New conversations', $overview);
        $this->assertStringContainsString('Messages received', $overview);

        // Outgoing volume is operational health, not a local-Business win.
        foreach (['Messages sent', 'Outbound', 'Outgoing', '>Sent<', 'Failed'] as $outgoing) {
            $this->assertStringNotContainsString($outgoing, $overview, "The overview must not headline {$outgoing}.");
        }

        // It lives in the secondary Messages section, after the overview.
        $this->assertLessThan(strpos($html, 'data-role="results-messages"'), strpos($html, 'data-role="results-overview"'));
    }

    public function test_the_retired_telemetry_headings_are_gone(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->campaign($business);
        $this->authenticateAsCustomer($customer);

        $content = $this->resultsContent($this->overview($workspace, $business)->assertOk()->getContent());

        foreach (['Provider-accepted rate', 'Provider accepted', 'Message volume by direction', 'Contact growth', 'Campaigns created', 'Outbound message outcomes', 'Unresolved / in flight', 'handset'] as $retired) {
            $this->assertStringNotContainsString($retired, $content, "Retired heading or term: {$retired}");
        }
    }

    // ---------------------------------------------------------------
    // Figures are exactly their sources
    // ---------------------------------------------------------------

    public function test_overview_figures_equal_the_b5_and_conversation_sources_exactly(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->authenticateAsCustomer($customer);

        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);
        $b5 = app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range);
        $conversations = app(BusinessConversationReadModel::class)->startedCount($business, $range->startUtc, $range->endUtc);

        $this->assertSame(2, $b5->contacts->newInRange);
        $this->assertSame(3, $b5->messages->inbound);
        $this->assertSame(2, $conversations);

        $html = $this->overview($workspace, $business)->assertOk()->getContent();

        $this->assertSame((string) $b5->contacts->newInRange, $this->figure($html, 'kpi-new-contacts-value'));
        $this->assertSame((string) $conversations, $this->figure($html, 'kpi-new-conversations-value'));
        $this->assertSame((string) $b5->messages->inbound, $this->figure($html, 'kpi-messages-received-value'));
    }

    public function test_message_outcomes_are_plain_words_over_the_unchanged_b5_figures(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->authenticateAsCustomer($customer);

        $m = app(BusinessAnalyticsPresenter::class)->buildOverview($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone))->messages;

        // The B5 figures themselves are unchanged: M4 exact-or-pipe, M5 the
        // terminal vocabulary, M6 everything else, and the identity holds.
        $this->assertSame(4, $m->outbound);
        $this->assertSame(2, $m->accepted);
        $this->assertSame(1, $m->confirmedFailed);
        $this->assertSame(1, $m->unresolved());
        $this->assertSame($m->outbound, $m->accepted + $m->confirmedFailed + $m->unresolved());

        $html = $this->overview($workspace, $business)->assertOk()->getContent();

        $this->assertSame((string) $m->accepted, $this->figure($html, 'outcome-sent'), '"Sent" is M4.');
        $this->assertSame((string) $m->confirmedFailed, $this->figure($html, 'outcome-failed'), '"Failed" is M5.');
        $this->assertSame((string) $m->unresolved(), $this->figure($html, 'outcome-processing'), '"Processing" is M6.');
        $this->assertStringContainsString('Out of 4 outgoing messages.', $html);
    }

    public function test_sent_states_its_meaning_and_never_claims_the_phone_received_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();
        $messages = $this->section($response->getContent(), 'results-messages');

        $this->assertStringContainsString('Accepted by the messaging provider.', $messages, 'The helper beside "Sent".');
        $this->assertStringContainsString('data-role="sent-note"', $messages);
        $this->assertStringContainsString("It doesn't confirm the message reached the phone.", $messages);
        $this->assertStringNotContainsString('Delivered', $messages, '"Sent" is never presented as delivery.');
    }

    public function test_api_messages_appear_only_as_a_separate_note_and_never_in_the_headline_chart(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->report($business, $business->customer_id, ['direction' => 'api']);
        $this->report($business, $business->customer_id, ['direction' => 'api']);
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()
            ->assertSee('data-role="api-note"', false)
            ->assertSee('Another 2 messages were sent through your API and are counted separately.');

        $charts = $this->series($workspace, $business)->assertOk()->json('charts.messages.series');
        $this->assertSame(['incoming', 'accepted'], array_keys($charts), 'The Messages chart carries Received and Sent only.');
    }

    // ---------------------------------------------------------------
    // Charts: readable axis, exact tooltip, unchanged totals
    // ---------------------------------------------------------------

    public function test_series_payload_keeps_raw_series_and_adds_a_readable_chart_form(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->authenticateAsCustomer($customer);

        $payload = $this->series($workspace, $business)->assertOk()->json();

        // The existing contract is intact...
        $this->assertCount(30, $payload['message_volume']['dates']);
        $this->assertSame(4, array_sum($payload['message_volume']['series']['outgoing']));
        $this->assertSame(3, array_sum($payload['message_volume']['series']['incoming']));
        $this->assertSame(0, array_sum($payload['message_volume']['series']['api']));
        $this->assertSame(2, array_sum($payload['contact_growth']['series']['new_contacts']));

        // ...and the chart form charts exactly the page's own figures.
        $this->assertSame(2, array_sum($payload['charts']['messages']['series']['accepted']), 'The "Sent" line totals M4.');
        $this->assertSame(3, array_sum($payload['charts']['messages']['series']['incoming']));
        $this->assertSame(2, array_sum($payload['charts']['new_contacts']['series']['new_contacts']));

        foreach (['new_contacts', 'messages'] as $chart) {
            foreach ($payload['charts'][$chart]['labels'] as $label) {
                $this->assertDoesNotMatchRegularExpression(self::ISO_DATE, $label, 'No dense YYYY-MM-DD axis.');
            }
            $this->assertCount(count($payload['charts'][$chart]['labels']), $payload['charts'][$chart]['tooltips']);
        }
    }

    public function test_labels_adapt_to_the_length_of_the_range(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $week = $this->series($workspace, $business, ['range' => 'last_7_days'])->assertOk()->json('charts.messages');
        $this->assertSame('day', $week['granularity']);
        $this->assertCount(7, $week['labels']);
        $this->assertMatchesRegularExpression('/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun) \d{1,2}$/', $week['labels'][0], 'Seven days read "Mon 7".');

        $month = $this->series($workspace, $business, ['range' => 'last_30_days'])->assertOk()->json('charts.messages');
        $this->assertSame('day', $month['granularity']);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{1,2}$/', $month['labels'][0], 'Thirty days read "Sep 5".');

        $quarter = $this->series($workspace, $business, ['range' => 'last_90_days'])->assertOk()->json('charts.messages');
        $this->assertSame('week', $quarter['granularity']);
        $this->assertCount(13, $quarter['labels']);
    }

    public function test_business_timezone_bucketing_survives_into_the_chart(): void
    {
        [$customer, $business, $workspace] = $this->tenant('America/New_York');
        $tz = 'America/New_York';

        // Local 23:30 on 10 June is 03:30 UTC on the 11th: it belongs to the 10th.
        $this->report($business, $business->customer_id, ['customer_status' => 'Delivered', 'created_at' => $this->utcFromLocal('2026-06-10 23:30:00', $tz)]);
        $this->report($business, $business->customer_id, ['direction' => 'incoming', 'created_at' => $this->utcFromLocal('2026-06-11 00:10:00', $tz)]);
        $this->authenticateAsCustomer($customer);

        $chart = $this->series($workspace, $business, ['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-11'])->assertOk()->json('charts.messages');

        $this->assertSame(['Wed 10', 'Thu 11'], $chart['labels']);
        $this->assertSame([1, 0], $chart['series']['accepted']);
        $this->assertSame([0, 1], $chart['series']['incoming']);
        $this->assertSame('Wed, Jun 10, 2026', $chart['tooltips'][0]);
    }

    // ---------------------------------------------------------------
    // Range control
    // ---------------------------------------------------------------

    public function test_the_range_control_offers_human_presets_and_calendar_months(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()
            ->assertSeeInOrder(['Last 7 days', 'Last 30 days', 'Last 90 days', 'This month', 'Last month', 'Custom range']);

        foreach (['this_month' => 'This month', 'last_month' => 'Last month'] as $preset => $label) {
            $this->overview($workspace, $business, ['range' => $preset])->assertOk()->assertSee('Showing <strong>' . $label . '</strong>', false);
            $this->series($workspace, $business, ['range' => $preset])->assertOk()->assertJsonStructure(['charts' => ['messages', 'new_contacts']]);
        }
    }

    public function test_the_timezone_is_a_tooltip_not_the_main_caption(): void
    {
        [$customer, $business, $workspace] = $this->tenant('America/New_York', 'Snap Booth Co');
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-role="timezone-note"', $html);
        // assertSee escapes the needle exactly as Blade escapes the apostrophe.
        $response->assertSee("Days follow Snap Booth Co's local time (America/New_York).");

        // The caption's VISIBLE text: tags (and so the tooltip's title and
        // aria-label attributes) stripped. The timezone lives only there.
        $this->assertSame(1, preg_match('/<p[^>]*data-role="range-caption"[^>]*>(.*?)<\/p>/s', $html, $match));
        $visible = trim(preg_replace('/\s+/', ' ', strip_tags($match[1])));

        $this->assertStringStartsWith('Showing Last 30 days', $visible);
        $this->assertStringNotContainsString('America/New_York', $visible, 'The visible line carries no timezone name.');
        $this->assertStringNotContainsString('timezone', $visible);
        $this->assertStringNotContainsString('local days', $html);
    }

    // ---------------------------------------------------------------
    // Secondary sections: only when useful
    // ---------------------------------------------------------------

    public function test_campaigns_are_supporting_detail_only_and_absent_without_campaigns(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()->assertDontSee('data-role="campaigns-panel"', false);

        $this->campaign($business);
        Cache::forget(BusinessAnalyticsPresenter::cacheKey($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone)));

        $html = $this->overview($workspace, $business)->assertOk()->getContent();
        $this->assertStringContainsString('data-role="campaigns-panel"', $html);
        $this->assertStringNotContainsString('Campaigns created', $html, 'Never a headline figure.');
        $this->assertLessThan(strpos($html, 'data-role="campaigns-panel"'), strpos($html, 'data-role="results-overview"'));
    }

    public function test_automations_render_human_trigger_labels_never_stored_values(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $contact = $this->contact($business, $this->group($business));
        $automation = $this->automation($business);
        $this->execution($business, $automation, $contact, 'succeeded', 'contact_created');
        $this->execution($business, $automation, $contact, 'failed', 'contact_date_reached');
        $this->authenticateAsCustomer($customer);

        $panel = $this->section($this->overview($workspace, $business)->assertOk()->getContent(), 'automations-panel');

        $this->assertStringContainsString('Contact created (1)', $panel);
        $this->assertStringContainsString('Contact date reached (1)', $panel);
        $this->assertStringNotContainsString('contact_created', $panel);
        $this->assertStringNotContainsString('contact_date_reached', $panel);
        $this->assertSame('2', $this->figure($panel, 'automation-runs'));
        $this->assertSame('1', $this->figure($panel, 'automation-completed'));
        $this->assertSame('1', $this->figure($panel, 'automation-failed'));
    }

    public function test_an_unknown_trigger_is_named_generically_never_by_its_stored_value(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $contact = $this->contact($business, $this->group($business));
        $this->execution($business, $this->automation($business), $contact, 'succeeded', 'some_future_trigger');
        $this->authenticateAsCustomer($customer);

        $panel = $this->section($this->overview($workspace, $business)->assertOk()->getContent(), 'automations-panel');

        $this->assertStringContainsString('Other trigger (1)', $panel);
        $this->assertStringNotContainsString('some_future_trigger', $panel);
    }

    public function test_automations_are_absent_when_nothing_ran(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()->assertDontSee('data-role="automations-panel"', false);
    }

    // ---------------------------------------------------------------
    // Nothing invented
    // ---------------------------------------------------------------

    public function test_no_lead_source_booking_revenue_visibility_or_email_metric_is_rendered(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->campaign($business);
        $this->authenticateAsCustomer($customer);

        $content = $this->resultsContent($this->overview($workspace, $business)->assertOk()->getContent());

        // No canonical lifecycle, source, booking, revenue, visibility or
        // business email domain exists, so none of these may appear.
        foreach (['New leads', 'Lead source', 'Lead conversion', 'New customers', 'Bookings', 'Revenue', 'ROI', 'Reply rate', 'Response rate', 'Conversion', 'Pipeline', 'Website visitors', 'Profile views', 'First response', 'Unanswered', 'Opened', 'Clicked', 'Email', 'Prospects', 'Calls booked'] as $invented) {
            $this->assertStringNotContainsString($invented, $content, "Invented metric: {$invented}");
        }

        $this->assertStringNotContainsString('data-role="results-email"', $content);
    }

    // ---------------------------------------------------------------
    // Tenancy
    // ---------------------------------------------------------------

    public function test_another_business_never_reaches_any_figure_or_chart(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        [, $other, $otherWorkspace] = $this->tenant();
        $this->seedActivity($other);
        $this->conversation($other);
        $this->authenticateAsCustomer($customer);

        $html = $this->overview($workspace, $business)->assertOk()->getContent();

        $this->assertSame('0', $this->figure($html, 'kpi-new-contacts-value'));
        $this->assertSame('0', $this->figure($html, 'kpi-new-conversations-value'));
        $this->assertSame('0', $this->figure($html, 'kpi-messages-received-value'));

        $charts = $this->series($workspace, $business)->assertOk()->json('charts');
        $this->assertSame(0, array_sum($charts['messages']['series']['accepted']));
        $this->assertSame(0, array_sum($charts['messages']['series']['incoming']));
        $this->assertSame(0, array_sum($charts['new_contacts']['series']['new_contacts']));

        // And a foreign Business is still a 404, never a figure.
        $this->overview($otherWorkspace, $other)->assertNotFound();
        $this->series($otherWorkspace, $other)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Query cost
    // ---------------------------------------------------------------

    public function test_results_cost_is_the_unchanged_b5_reads_plus_exactly_one_conversation_read(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        config(['opportunity.enabled' => true]);
        $this->seedActivity($business);
        $this->campaign($business);
        $this->authenticateAsCustomer($customer);

        $sql = $this->analyticsOwnedSql(fn () => $this->overview($workspace, $business)->assertOk());

        $b5 = array_values(array_filter($sql, fn (string $s) => preg_match(self::B5_SOURCE_TABLES, $s) === 1));
        $tenancy = array_values(array_filter($sql, fn (string $s) => preg_match(self::TENANCY_TABLES, $s) === 1));
        $conversations = array_values(array_filter($sql, fn (string $s) => str_contains($s, 'chat_boxes')));

        $this->assertLessThanOrEqual(7, count($b5), 'B5 reads are unchanged: ' . implode(' | ', $b5));
        $this->assertLessThanOrEqual(12, count($b5) + count($tenancy), 'The B5 overview budget is unchanged.');
        $this->assertCount(1, $conversations, 'New conversations costs exactly one Slice 2B read.');
        $this->assertLessThanOrEqual(13, count($b5) + count($tenancy) + count($conversations));
    }

    public function test_results_cost_does_not_grow_with_the_amount_of_data(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->authenticateAsCustomer($customer);
        $overviewKey = BusinessAnalyticsPresenter::cacheKey($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone));

        // Results' own reads only: B5 sources, the tenancy chain and the
        // conversation count. Each run starts from a cold overview entry.
        $count = function () use ($workspace, $business, $overviewKey): int {
            Cache::forget($overviewKey);
            $sql = $this->analyticsOwnedSql(fn () => $this->overview($workspace, $business)->assertOk());

            return count(array_filter($sql, fn (string $s) => preg_match(self::B5_SOURCE_TABLES, $s) === 1 || preg_match(self::TENANCY_TABLES, $s) === 1 || str_contains($s, 'chat_boxes')));
        };

        $small = $count();

        // Three times the contacts, messages and conversations, plus
        // campaigns — the same statements, not one more.
        $this->seedActivity($business);
        $this->seedActivity($business);
        $this->campaign($business);
        $this->campaign($business);

        $this->assertSame($small, $count(), 'No query per contact, message, conversation or campaign.');
    }

    public function test_only_the_shell_entitlement_snapshot_is_left_out_of_the_analytics_budget(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        // One request, every statement tagged by whether the 2A snapshot issued it.
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $fromSnapshot = false;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (($frame['class'] ?? null) === EntitlementManager::class && ($frame['function'] ?? null) === 'snapshotBusinessFeatureDecisions') {
                    $fromSnapshot = true;
                    break;
                }
            }
            $statements[] = ['sql' => $query->sql, 'snapshot' => $fromSnapshot];
        });

        $this->overview($workspace, $business)->assertOk();

        $excluded = array_values(array_filter($statements, fn (array $s) => $s['snapshot']));
        $this->assertNotEmpty($excluded, 'The shell snapshot runs on this Business-frame page.');
        $this->assertLessThanOrEqual(6, count($excluded), 'Within its own six-query budget.');

        foreach ($excluded as $statement) {
            $this->assertMatchesRegularExpression('/\b(businesses|workspace_plan_assignments|workspace_plan_catalog|workspace_entitlement_overrides|workspace_plan_features|business_feature_toggles)\b/', $statement['sql'], 'Only entitlement-snapshot reads are excluded: ' . $statement['sql']);
            $this->assertDoesNotMatchRegularExpression(self::B5_SOURCE_TABLES, $statement['sql'], 'No B5 read is ever excluded.');
        }

        $excludedTenancy = array_filter($excluded, fn (array $s) => preg_match(self::TENANCY_TABLES, $s['sql']) === 1);
        $this->assertCount(1, $excludedTenancy, 'Exactly one excluded statement is a tenancy-table read: the Business re-read.');
    }

    // ---------------------------------------------------------------
    // Cache
    // ---------------------------------------------------------------

    public function test_a_payload_cached_before_this_release_is_recomputed_not_shown_as_zero(): void
    {
        [, $business] = $this->tenant();
        $this->report($business, $business->customer_id, ['customer_status' => 'Delivered']);
        $range = AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);
        $presenter = app(BusinessAnalyticsPresenter::class);

        // An entry in the previous release's shape: no version, no `accepted`.
        $stale = $presenter->buildOverview($business, $range)->toArray();
        unset($stale['message_volume']['series']['accepted']);
        Cache::put(BusinessAnalyticsPresenter::cacheKey($business, $range), $stale, BusinessAnalyticsPresenter::CACHE_TTL_SECONDS);

        $series = $presenter->buildSeries($business, $range);

        $this->assertSame(1, array_sum($series['charts']['messages']['series']['accepted']), 'Recomputed, so "Sent" is the real figure.');
        $this->assertSame(BusinessAnalyticsPresenter::PAYLOAD_VERSION, Cache::get(BusinessAnalyticsPresenter::cacheKey($business, $range))['payload_version']);
    }

    // ---------------------------------------------------------------
    // Accessibility and banners
    // ---------------------------------------------------------------

    public function test_sections_are_labelled_and_helpers_are_reachable_by_keyboard(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->seedActivity($business);
        $this->authenticateAsCustomer($customer);

        $html = $this->overview($workspace, $business)->assertOk()->getContent();

        foreach (['results-overview-heading', 'results-messages-heading'] as $heading) {
            $this->assertStringContainsString('aria-labelledby="' . $heading . '"', $html);
            $this->assertStringContainsString('<h2 id="' . $heading . '"', $html);
        }

        $this->assertMatchesRegularExpression('/data-role="sent-note"[^>]*tabindex="0"|tabindex="0"[^>]*data-role="sent-note"/', $html, 'The "Sent" helper is focusable.');
        $this->assertMatchesRegularExpression('/data-role="timezone-note"[^>]*tabindex="0"|tabindex="0"[^>]*data-role="timezone-note"/', $html, 'The timezone helper is focusable.');
    }

    public function test_the_impersonation_notice_the_title_bar_used_to_carry_still_renders(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->withSession(['admin_user_id' => 1, 'temp_user_id' => $customer->user_id, 'admin_user_name' => 'Ops Reviewer'])
            ->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Ops Reviewer');

        $source = file_get_contents(resource_path('views/customer/business/analytics/overview.blade.php'));
        $this->assertStringContainsString("@include('auth.loggedAs')", $source);
        $this->assertStringContainsString('<x-view-as-banner />', $source);
    }
}
