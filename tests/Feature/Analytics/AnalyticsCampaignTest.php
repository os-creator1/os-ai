<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use App\Models\Campaigns;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §6 (C1–C3), §11.2/§11.4 (two grouped aggregates per page,
 * pagination at 25), §21 "Campaign KPIs", "Foreign campaign exclusion",
 * "Pagination", "Query count / no N+1".
 */
class AnalyticsCampaignTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private function page($business, int $page = 1): array
    {
        return app(BusinessAnalyticsPresenter::class)->buildCampaignsPage($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone), $page);
    }

    public function test_attempted_accepted_failures_and_distinct_contacts_come_from_reports_and_tracking_logs(): void
    {
        [, $business] = $this->tenant();
        $u = $business->customer_id;
        $campaign = $this->campaign($business, ['cache' => json_encode(['delivered' => 999, 'contacts' => 999])]);
        $group = $this->group($business);
        $contact = $this->contact($business, $group);
        $other = $this->contact($business, $group);

        $this->report($business, $u, ['campaign_id' => $campaign->id, 'customer_status' => 'Delivered']);
        $this->report($business, $u, ['campaign_id' => $campaign->id, 'customer_status' => 'Delivered|SM1']);
        $this->report($business, $u, ['campaign_id' => $campaign->id, 'customer_status' => 'Undelivered']);
        $this->report($business, $u, ['campaign_id' => $campaign->id, 'customer_status' => 'Enroute']);

        // Two sends to the same contact must not inflate "contacts targeted".
        $this->trackingLog($business, $campaign, $contact);
        $this->trackingLog($business, $campaign, $contact);
        $this->trackingLog($business, $campaign, $other);

        $rows = $this->page($business)['rows'];

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(4, $row->attempted);
        $this->assertSame(2, $row->accepted);
        $this->assertSame(1, $row->confirmedFailed);
        $this->assertSame(2, $row->contactsTargeted);
        $this->assertSame(50.0, $row->acceptedRate());
        $this->assertSame(25.0, $row->confirmedFailedRate());

        // The legacy cache blob was never consulted and is untouched.
        $this->assertSame(json_encode(['delivered' => 999, 'contacts' => 999]), Campaigns::find($campaign->id)->cache);
    }

    public function test_campaign_with_zero_reports_renders_zeros_without_division_errors(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->campaign($business, ['campaign_name' => 'Quiet campaign']);
        $this->authenticateAsCustomer($customer);

        $rows = $this->page($business)['rows'];
        $this->assertSame(0, $rows[0]->attempted);
        $this->assertNull($rows[0]->acceptedRate());

        $this->campaignsPage($workspace, $business)->assertOk()->assertSee('Quiet campaign')->assertSee('—');
    }

    public function test_c1_and_c2_created_in_range_versus_current_snapshot(): void
    {
        [, $business] = $this->tenant();

        $this->campaign($business, ['status' => Campaigns::STATUS_DONE]);
        $this->campaign($business, ['status' => Campaigns::STATUS_PAUSED]);
        $this->campaign($business, ['status' => Campaigns::STATUS_DONE, 'created_at' => now()->utc()->subDays(400)->format('Y-m-d H:i:s')]);

        $kpis = app(BusinessAnalyticsPresenter::class)->buildOverview($business, AnalyticsDateRange::preset(AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone))->campaigns;

        $this->assertSame(2, $kpis->createdInRange);
        $this->assertSame(['done' => 2, 'paused' => 1], $kpis->statusSnapshot);
        $this->assertSame(3, $kpis->totalNow());
    }

    public function test_foreign_business_campaigns_and_their_aggregates_never_appear_even_when_injected(): void
    {
        [$customer, $businessA, $workspace] = $this->tenant();
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'Other Venue']));
        $u = $businessA->customer_id;

        $mine = $this->campaign($businessA, ['campaign_name' => 'Mine']);
        $theirs = $this->campaign($businessB, ['campaign_name' => 'Not Mine']);
        $this->report($businessB, $u, ['campaign_id' => $theirs->id]);
        // A report row that names A's campaign but carries B's tenancy is excluded by business_id too.
        $this->report($businessB, $u, ['campaign_id' => $mine->id]);

        $this->authenticateAsCustomer($customer);

        $page = $this->campaignsPage($workspace, $businessA)->assertOk();
        $page->assertSee('Mine')->assertDontSee('Not Mine');

        $rows = $this->page($businessA)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]->attempted, 'Aggregates are constrained by business_id, never by campaign_id alone.');

        // Injecting the foreign campaign id into any accepted input changes nothing.
        $this->campaignsPage($workspace, $businessA, ['campaign' => $theirs->id, 'campaign_id' => $theirs->id])->assertOk()->assertDontSee('Not Mine');
        $this->series($workspace, $businessA, ['campaign_id' => $theirs->id])->assertOk()->assertJsonPath('message_volume.series.outgoing', array_fill(0, 30, 0));
    }

    public function test_pagination_is_25_per_page_with_disjoint_pages_and_two_aggregate_queries(): void
    {
        [$customer, $business, $workspace] = $this->tenant();

        for ($i = 1; $i <= 30; $i++) {
            $this->campaign($business, ['campaign_name' => 'Campaign ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
        }

        $first = $this->page($business, 1);
        $second = $this->page($business, 2);

        $this->assertCount(25, $first['rows']);
        $this->assertCount(5, $second['rows']);
        $this->assertSame(30, $first['paginator']->total());
        $firstIds = array_map(fn ($r) => $r->id, $first['rows']);
        $secondIds = array_map(fn ($r) => $r->id, $second['rows']);
        $this->assertSame([], array_intersect($firstIds, $secondIds));

        $this->authenticateAsCustomer($customer);

        $sql = $this->capturedSql(fn () => $this->campaignsPage($workspace, $business)->assertOk());
        $aggregates = array_values(array_filter($sql, fn (string $s) => str_contains($s, 'group by')));
        $analyticsOwned = array_values(array_filter($sql, fn (string $s) => preg_match('/\b(reports|tracking_logs|campaigns|workspaces|businesses|workspace_memberships|workspace_membership_businesses)\b/', $s) === 1));

        $this->assertCount(2, $aggregates, 'Exactly two grouped aggregates per page, regardless of page size: ' . implode(' | ', $aggregates));
        // Tenancy chain (≤4) + paginator count + page select + two aggregates.
        $this->assertLessThanOrEqual(8, count($analyticsOwned), 'Campaign page must stay bounded: ' . implode(' | ', $analyticsOwned));
    }

    public function test_analytics_code_never_uses_legacy_campaign_accessors(): void
    {
        foreach (glob(app_path('Library/Analytics/*.php')) as $file) {
            $source = php_strip_whitespace($file);

            foreach (['deliveredCount', 'failedCount', 'notDeliveredCount', 'contactCount', 'readCache', "'cache'", 'campaigns.cache', '%Delivered%', 'whereDate', 'CONVERT_TZ', 'DATE(', 'DAY(', 'LegacyBusinessResolver', 'findPrimaryByCustomer', 'business_id IS NULL OR', 'OR business_id IS NULL'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, basename($file) . ' must not use ' . $forbidden);
            }
        }
    }
}
