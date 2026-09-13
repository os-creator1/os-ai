<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Dashboard\DashboardSnapshot;
use App\Library\Dashboard\RecentWorkReader;
use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unified Business Home §14 (Slice H-5) — Recent work.
 *
 * A factual timeline, not an activity-event layer: every line is a row this
 * application already persisted for its own reasons, read where it lives. If
 * something leaves no row it leaves no item — a website rollback writes no
 * revision, so no rollback ever appears, and that gap is stated rather than
 * papered over.
 *
 * Five sources, five bounded Business-scoped reads, merged newest-first in
 * PHP. Nothing here queries the automation ledger directly: that belongs to
 * the analytics seam, and the failure item comes through it.
 */
class BusinessHomeRecentWorkTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // 11:00 in New York on 10 Sep 2026.
        $this->freezeClock();
    }

    // =================================================================
    // The five sources
    // =================================================================

    public function test_a_published_website_version_is_listed_and_a_rollback_is_not(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Site Venue', 'Site Account');
        $this->website($business, 'published');
        $this->websiteRevision($business, 1, $this->hoursAgo(30));
        $this->websiteRevision($business, 2, $this->hoursAgo(3));

        $items = $this->itemsFor($business);

        $this->assertSame(
            ['Website version 2 published', 'Website version 1 published'],
            array_map(fn ($item) => $item->text, $items),
            'Newest first, and the version number is the persisted one.',
        );

        // A rollback repoints the website and writes NO revision row. There is
        // therefore nothing to report, and nothing is invented (§14).
        DB::table('websites')->where('business_id', $business->id)->update(['published_revision_id' => null]);

        $this->assertCount(2, $this->itemsFor($business), 'A rollback adds no item, because it adds no row.');
    }

    public function test_a_completed_recommendation_uses_the_registry_title(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Advisor Venue', 'Advisor Account');

        $completed = $this->recommendation($business, ['type' => 'missing_phone', 'title' => 'Stored title nobody should see']);
        $this->opportunityTransition($completed, 'completed', $this->hoursAgo(5));

        // Other transitions of the same opportunity are not completions.
        $this->opportunityTransition($completed, 'in_progress', $this->hoursAgo(9));
        $dismissed = $this->recommendation($business, ['type' => 'missing_website']);
        $this->opportunityTransition($dismissed, 'dismissed', $this->hoursAgo(2));

        $items = $this->itemsFor($business);

        $this->assertCount(1, $items, 'Only a transition INTO completed is a completion.');
        $this->assertSame('Completed: Add your business phone number', $items[0]->text, "The registry's own title, never the raw type name.");
    }

    public function test_a_recommendation_the_registry_does_not_define_falls_back_to_its_stored_title(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Unknown Venue', 'Unknown Account');

        $known = $this->recommendation($business, ['type' => 'unregistered_type', 'title' => 'Ask happy customers for a review']);
        $this->opportunityTransition($known, 'completed', $this->hoursAgo(4));

        $untitled = $this->recommendation($business, ['type' => 'unregistered_type', 'title' => '']);
        $this->opportunityTransition($untitled, 'completed', $this->hoursAgo(3));

        $items = $this->itemsFor($business);

        $this->assertSame(['Completed: Ask happy customers for a review'], array_map(fn ($item) => $item->text, $items));
        $this->assertStringNotContainsString('unregistered_type', json_encode($items), 'A raw type name is never shown.');
    }

    public function test_automation_failures_are_grouped_per_automation_per_local_day(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Runs Venue', 'Runs Account');

        // Three failures of one automation on one Business-local day, two of
        // another on the day before, and successes that are not failures.
        $this->automationRuns($business, 3, '2026-09-09', 'failed');
        $this->automationRuns($business, 2, '2026-09-08', 'failed');
        $this->automationRuns($business, 7, '2026-09-09', 'succeeded');

        $items = $this->itemsFor($business);
        $texts = array_map(fn ($item) => $item->text, $items);

        $this->assertCount(2, $items, 'One item per automation per day, never one per run.');
        $this->assertMatchesRegularExpression('/^Fixture automation failed 3 times$/', $texts[0]);
        $this->assertMatchesRegularExpression('/^Fixture automation failed 2 times$/', $texts[1]);
    }

    public function test_a_single_failure_is_said_in_the_singular(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'One Run Venue', 'One Run Account');
        $this->automationRuns($business, 1, '2026-09-09', 'failed');

        $this->assertSame('Fixture automation failed 1 time', $this->itemsFor($business)[0]->text);
    }

    public function test_google_work_is_listed_and_machine_noise_is_not(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Google Venue', 'Google Account');

        $this->googleOperation($business, 'connect_completed', $this->hoursAgo(20));
        $this->googleOperation($business, 'location_bound', $this->hoursAgo(15));
        $this->googleOperation($business, 'location_unbound', $this->hoursAgo(10));
        $this->googleOperation($business, 'disconnected', $this->hoursAgo(5));
        // Machinery, not work.
        $this->googleOperation($business, 'token_refreshed', $this->hoursAgo(1));
        $this->googleOperation($business, 'mirror_refreshed', $this->hoursAgo(1));
        $this->googleOperation($business, 'accounts_enumerated', $this->hoursAgo(1));
        $this->googleOperation($business, 'connect_initiated', $this->hoursAgo(1));
        // An attempt that did not succeed is not a thing that happened.
        $this->googleOperation($business, 'connect_completed', $this->hoursAgo(2), 'failed');
        $this->googleOperation($business, 'location_bound', $this->hoursAgo(2), 'pending');

        $texts = array_map(fn ($item) => $item->text, $this->itemsFor($business));

        $this->assertSame(
            ['Google disconnected', 'Google listing unlinked', 'Google listing linked', 'Google connected'],
            $texts,
        );
        $this->assertDoesNotMatchRegularExpression('/token|mirror|oauth|refresh|enumerat/i', implode(' ', $texts));
    }

    public function test_business_detail_changes_are_grouped_per_actor_per_day_and_expose_no_payload(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Details Venue', 'Details Account');
        $other = $this->createCustomer();

        foreach (['name', 'phone', 'email', 'description'] as $field) {
            $this->businessDetailChange($business, $field, $this->hoursAgo(6));
        }

        $this->businessDetailChange($business, 'name', $this->hoursAgo(40));
        $this->businessDetailChange($business, 'phone', $this->hoursAgo(3), (int) $other->user_id);

        $items = $this->itemsFor($business);

        $this->assertCount(3, $items, 'Four field edits by one person on one day are one item.');
        $this->assertSame(['Business details updated', 'Business details updated', 'Business details updated'], array_map(fn ($item) => $item->text, $items));
        $this->assertStringNotContainsString('before', json_encode($items), 'No raw change payload reaches the customer.');
        $this->assertStringNotContainsString('after', json_encode($items));
    }

    // =================================================================
    // Merge, limit, tenancy
    // =================================================================

    public function test_the_sources_merge_into_one_list_newest_first(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Merge Venue', 'Merge Account');

        $this->website($business, 'published');
        $this->websiteRevision($business, 4, $this->hoursAgo(50));
        $completed = $this->recommendation($business, ['type' => 'missing_phone']);
        $this->opportunityTransition($completed, 'completed', $this->hoursAgo(40));
        $this->automationRunsAt($business, 2, $this->hoursAgo(30), 'failed');
        $this->googleOperation($business, 'connect_completed', $this->hoursAgo(20));
        $this->businessDetailChange($business, 'name', $this->hoursAgo(2));

        $items = $this->itemsFor($business);
        $timestamps = array_map(fn ($item) => $item->at->getTimestamp(), $items);
        $sorted = $timestamps;
        rsort($sorted);

        $this->assertCount(5, $items, 'Every source contributed.');
        $this->assertSame($sorted, $timestamps, 'Newest first, across sources.');
        $this->assertSame(
            ['business_details_updated', 'google_connect_completed', 'automation_failed', 'recommendation_completed', 'website_version_published'],
            array_map(fn ($item) => $item->key, $items),
        );
    }

    public function test_the_list_stops_at_ten_across_all_sources(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Many Venue', 'Many Account');
        $this->website($business, 'published');

        foreach (range(1, 12) as $version) {
            $this->websiteRevision($business, $version, $this->hoursAgo(200 - $version));
        }

        foreach (range(1, 6) as $hour) {
            $this->googleOperation($business, 'location_bound', $this->hoursAgo($hour));
        }

        $items = $this->itemsFor($business);

        $this->assertCount(10, $items);
        $this->assertSame('Google listing linked', $items[0]->text, 'The newest events win the ten places.');
        $this->assertSame(6, count(array_filter($items, fn ($item) => $item->key === 'location_bound' || str_starts_with($item->key, 'google_'))));
    }

    public function test_a_smaller_limit_is_honoured(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Limit Venue', 'Limit Account');
        $this->website($business, 'published');

        foreach (range(1, 5) as $version) {
            $this->websiteRevision($business, $version, $this->hoursAgo(10 - $version));
        }

        $this->assertCount(3, app(RecentWorkReader::class)->recent($business, 3));
    }

    public function test_another_businesss_work_never_appears(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Ours Venue', 'Ours Account');
        $rival = $this->addBusiness($customer, $workspace, 'Rival Venue');

        $this->website($rival, 'published');
        $this->websiteRevision($rival, 9, $this->hoursAgo(1));
        $rivalOpportunity = $this->recommendation($rival, ['type' => 'missing_phone']);
        $this->opportunityTransition($rivalOpportunity, 'completed', $this->hoursAgo(1));
        $this->automationRuns($rival, 3, '2026-09-09', 'failed');
        $this->googleOperation($rival, 'connect_completed', $this->hoursAgo(1));
        $this->businessDetailChange($rival, 'name', $this->hoursAgo(1));

        $this->assertSame([], $this->itemsFor($business), 'Not one row of the neighbour leaks in.');
        $this->assertCount(5, $this->itemsFor($rival), 'And the neighbour keeps its own.');
    }

    // =================================================================
    // The band on the Home
    // =================================================================

    public function test_the_band_renders_after_automations_in_the_locked_order(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Order Venue', 'Order Account');
        $this->website($business, 'published');
        $this->websiteRevision($business, 1, $this->hoursAgo(2));
        $this->automationRuns($business, 2, '2026-09-02', 'succeeded');
        $this->conversationWith($business, [['incoming', $this->hoursAgo(1)]]);
        $this->authenticateAs($customer);

        $order = $this->bandOrder($this->home()->assertOk()->getContent());

        $this->assertSame(
            ['headlines', 'visibility', 'conversations', 'automations', 'recent_work', 'actions'],
            array_values(array_filter($order, fn (string $band) => in_array($band, ['headlines', 'visibility', 'conversations', 'automations', 'recent_work', 'actions'], true))),
        );
    }

    public function test_a_business_with_no_supported_event_shows_no_band_at_all(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Venue', 'Quiet Account');
        // A website that was never published, and machinery that is not work.
        $this->website($business, 'draft');
        $this->googleOperation($business, 'token_refreshed', $this->hoursAgo(1));
        $this->authenticateAs($customer);

        $snapshot = $this->dashboardFor($customer->user);
        $html = $this->home()->assertOk()->getContent();

        $this->assertFalse($snapshot->has(DashboardSnapshot::BAND_RECENT_WORK), 'Nothing happened, so nothing is shown.');
        $this->assertStringNotContainsString('data-band="recent_work"', $html);
        $this->assertStringNotContainsString('Recent work', $this->mainText($html), 'No empty heading, no fake row.');
    }

    public function test_an_item_whose_destination_is_closed_still_renders_as_plain_text(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Gated Venue', 'Gated Account');
        $this->website($business, 'published');
        $this->websiteRevision($business, 3, $this->hoursAgo(2));
        $this->googleOperation($business, 'connect_completed', $this->hoursAgo(1));

        // With the permissions, both are links.
        $this->authenticateAs($customer);
        $band = $this->bandHtml($this->home()->assertOk()->getContent(), 'recent_work');

        $this->assertSame(2, substr_count($band, 'data-role="recent-work-link"'));
        $this->assertStringNotContainsString('data-role="recent-work-text"', $band);

        // Without them, the same two facts still render — as text.
        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['website', 'view_google_business_profile'])));
        $band = $this->bandHtml($this->home()->assertOk()->getContent(), 'recent_work');

        $this->assertStringContainsString('Website version 3 published', $band, 'The event happened either way.');
        $this->assertStringContainsString('Google connected', $band);
        $this->assertSame(2, substr_count($band, 'data-role="recent-work-text"'));
        $this->assertStringNotContainsString('data-role="recent-work-link"', $band);
    }

    public function test_an_agency_opened_client_business_gets_its_own_work_and_nothing_of_the_portfolio(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $sibling = $this->addBusiness($agency, $workspace, 'Bravo Bistro');

        $this->website($client, 'published');
        $this->websiteRevision($client, 2, $this->hoursAgo(3));
        $this->website($sibling, 'published');
        $this->websiteRevision($sibling, 7, $this->hoursAgo(1));

        $this->authenticateAs($agency);
        $this->switchTo($workspace, $client)->assertRedirect(route('user.home'));

        $snapshot = $this->dashboardFor($agency->user);
        $band = $snapshot->band(DashboardSnapshot::BAND_RECENT_WORK);
        $main = $this->mainText($this->home()->assertOk()->getContent());

        $this->assertSame(['Website version 2 published'], array_map(fn (array $item) => $item['text'], $band['items']));
        $this->assertStringNotContainsString('version 7', $main, "The sibling client's work is not this Business's.");
        $this->assertDoesNotMatchRegularExpression('/\b(Positive replies|Outreach|Client performance|Client accounts|Prospects)\b/', $main);
    }

    public function test_the_agency_account_home_has_no_recent_work_band(): void
    {
        [$agency, $client, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Alpha Dental', 'Northwind Agency');
        $this->addBusiness($agency, $workspace, 'Bravo Bistro');
        $this->website($client, 'published');
        $this->websiteRevision($client, 1, $this->hoursAgo(1));
        $this->authenticateAs($agency);

        $snapshot = $this->dashboardFor($agency->user);

        $this->assertSame(DashboardSnapshot::KIND_AGENCY, $snapshot->kind);
        $this->assertFalse($snapshot->has(DashboardSnapshot::BAND_RECENT_WORK));
    }

    public function test_no_billing_or_prospecting_event_is_a_source(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Scoped Venue', 'Scoped Account');
        $this->wallet($business, ['paid_activity_paused_at' => now(), 'debt_balance_micro' => 500000]);
        $this->website($business, 'published');
        $this->websiteRevision($business, 1, $this->hoursAgo(2));
        $this->authenticateAs($customer);

        $band = $this->bandText($this->home()->assertOk()->getContent(), 'recent_work');

        $this->assertStringContainsString('Website version 1 published', $band);
        $this->assertDoesNotMatchRegularExpression('/\b(balance|payment|invoice|top.?up|prospect|campaign|outreach)\b/i', $band);
    }

    // =================================================================
    // Cost
    // =================================================================

    public function test_recent_work_costs_five_statements_and_stays_flat_as_rows_double(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Cost Venue', 'Cost Account');
        $this->website($business, 'published');
        $this->seedEverySource($business, 2);

        $before = $this->recentWorkSql($business);

        $this->seedEverySource($business, 4);
        $after = $this->recentWorkSql($business);

        $this->assertCount(5, $before, 'One bounded read per source: ' . implode(' | ', $before));
        $this->assertCount(count($before), $after, 'Flat when the data grows: ' . implode(' | ', $after));

        foreach ($after as $sql) {
            $this->assertStringContainsString('limit', strtolower($sql), 'Every read is bounded.');
        }
    }

    public function test_the_reader_never_queries_the_automation_ledger_itself(): void
    {
        // The CODE, not the prose: the class docblock explains precisely why
        // it does not read these tables.
        $source = $this->codeWithoutComments(app_path('Library/Dashboard/RecentWorkReader.php'));

        foreach (['automation_executions', 'automation_step_runs'] as $table) {
            $this->assertStringNotContainsString($table, $source, 'The ledger belongs to the analytics seam (V2-H).');
        }

        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Seam Venue', 'Seam Account');
        $this->automationRuns($business, 2, '2026-09-09', 'failed');

        $origins = [];
        DB::listen(function ($query) use (&$origins): void {
            if (! str_contains($query->sql, 'automation_executions')) {
                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = str_replace('\\', '/', $frame['file'] ?? '');
                if (str_contains($file, '/app/')) {
                    $origins[] = substr($file, strpos($file, '/app/') + 1);
                    break;
                }
            }
        });

        $this->itemsFor($business);

        $this->assertSame(['app/Library/Analytics/BusinessAnalyticsQueries.php'], array_values(array_unique($origins)));
    }

    public function test_no_projection_table_was_created_for_this_band(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('business_activity_events'), '§14: no projection table.');
        $this->assertSame([], glob(database_path('migrations/*business_activity_events*')) ?: []);
    }

    // -----------------------------------------------------------------

    /** @return array<int, \App\Library\Dashboard\RecentWorkItem> */
    private function itemsFor(Business $business): array
    {
        Cache::flush();

        return app(RecentWorkReader::class)->recent($business->fresh());
    }

    /** @return array<int, string> */
    private function recentWorkSql(Business $business): array
    {
        Cache::flush();

        return array_values(array_filter(
            $this->sqlDuring(fn () => app(RecentWorkReader::class)->recent($business->fresh())),
            fn (string $sql) => str_contains($sql, 'website_revisions')
                || str_contains($sql, 'opportunity_transitions')
                || str_contains($sql, 'automation_executions')
                || str_contains($sql, 'business_google_operations')
                || str_contains($sql, 'business_knowledge_profile_changes'),
        ));
    }

    private function seedEverySource(Business $business, int $each): void
    {
        foreach (range(1, $each) as $i) {
            $this->websiteRevision($business, $i + $this->sourceSequence, $this->hoursAgo(100 + $i));
            $opportunity = $this->recommendation($business, ['type' => 'missing_phone']);
            $this->opportunityTransition($opportunity, 'completed', $this->hoursAgo(90 + $i));
            $this->automationRuns($business, 1, '2026-09-0' . (($i % 8) + 1), 'failed');
            $this->googleOperation($business, 'location_bound', $this->hoursAgo(70 + $i));
            $this->businessDetailChange($business, 'name', $this->hoursAgo(60 + $i));
        }

        $this->sourceSequence += $each;
    }

    private int $sourceSequence = 0;

    /** A PHP file with every comment removed, so prose cannot satisfy or break a source rule. */
    private function codeWithoutComments(string $path): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private function hoursAgo(int $hours): string
    {
        return CarbonImmutable::now()->subHours($hours)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<int, string> */
    private function bandOrder(string $html): array
    {
        preg_match_all('/data-band="([a-z_]+)"/', $this->mainHtml($html), $matches);

        return $matches[1];
    }

    private function bandHtml(string $html, string $band): string
    {
        $main = $this->mainHtml($html);
        $start = strpos($main, 'data-band="' . $band . '"');

        if ($start === false) {
            return '';
        }

        $end = strpos($main, '</section>', $start);

        return substr($main, $start, $end === false ? null : $end - $start);
    }

    private function bandText(string $html, string $band): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->bandHtml($html, $band)))) ?? '');
    }
}
