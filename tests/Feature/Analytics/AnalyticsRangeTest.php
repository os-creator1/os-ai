<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\AnalyticsDateRange;
use App\Library\Analytics\BusinessAnalyticsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — contract §4 and §21 "Date-range validation", "Final-day
 * inclusion", "Timezone", "DST" — proven end to end against real rows
 * stored in UTC.
 */
class AnalyticsRangeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private function outbound($business, string $preset = null, array $custom = []): int
    {
        $range = $custom !== []
            ? AnalyticsDateRange::fromInput(array_merge(['range' => 'custom'], $custom), $business->timezone)
            : AnalyticsDateRange::preset($preset ?? AnalyticsDateRange::PRESET_LAST_30_DAYS, $business->timezone);

        return app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range)->messages->outbound;
    }

    public function test_overview_defaults_to_the_last_30_days_and_accepts_every_preset(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business)->assertOk()->assertSee('Last 30 days');

        foreach (['last_7_days' => 'Last 7 days', 'last_90_days' => 'Last 90 days'] as $preset => $label) {
            $this->overview($workspace, $business, ['range' => $preset])->assertOk()->assertSee($label);
        }
    }

    public function test_custom_range_of_92_days_is_accepted_and_93_days_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        // The accepted 92-day window renders as a human span, not a count of
        // "local days" in a named timezone.
        $this->overview($workspace, $business, ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-02'])
            ->assertOk()
            ->assertSee('Jan 1, 2026 to Apr 2, 2026')
            ->assertDontSee('local days');

        $this->overview($workspace, $business, ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-03'])
            ->assertRedirect(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))
            ->assertSessionHasErrors('end');

        $this->series($workspace, $business, ['range' => 'custom', 'start' => '2026-01-01', 'end' => '2026-04-03'])->assertStatus(422);
    }

    public function test_reversed_and_unparseable_ranges_are_rejected_without_a_1970_fallback(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $this->authenticateAsCustomer($customer);

        $this->overview($workspace, $business, ['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-01'])->assertSessionHasErrors('end');

        $response = $this->series($workspace, $business, ['range' => 'custom', 'start' => 'last tuesday', 'end' => '2026-06-10']);
        $response->assertStatus(422);
        $this->assertStringNotContainsString('1970', $response->getContent());

        $this->series($workspace, $business, ['range' => 'everything'])->assertStatus(422);
    }

    public function test_final_selected_local_day_is_fully_included(): void
    {
        [, $business] = $this->tenant('America/New_York');
        $tz = 'America/New_York';
        $u = $business->customer_id;

        // 23:59:59 local on the final date — the legacy date-only
        // whereBetween dropped exactly this row.
        $this->report($business, $u, ['created_at' => $this->utcFromLocal('2026-06-10 23:59:59', $tz)]);
        // 00:00:00 local on the following date — must be excluded.
        $this->report($business, $u, ['created_at' => $this->utcFromLocal('2026-06-11 00:00:00', $tz)]);

        $this->assertSame(1, $this->outbound($business, null, ['start' => '2026-06-01', 'end' => '2026-06-10']));
    }

    public function test_late_evening_rows_bucket_into_their_local_date_for_positive_and_negative_offsets(): void
    {
        [, $tokyo] = $this->tenant('Asia/Tokyo', 'Tokyo Venue');
        [, $la] = $this->tenant('America/Los_Angeles', 'LA Venue');

        $this->report($tokyo, $tokyo->customer_id, ['created_at' => $this->utcFromLocal('2026-06-10 23:30:00', 'Asia/Tokyo')]);
        $this->report($la, $la->customer_id, ['created_at' => $this->utcFromLocal('2026-06-10 23:30:00', 'America/Los_Angeles')]);

        foreach ([$tokyo, $la] as $business) {
            $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-09', 'end' => '2026-06-11'], $business->timezone);
            $series = app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range)->messageVolume;

            $this->assertSame([0, 1, 0], $series->series['outgoing'], $business->timezone . ' must bucket 23:30 local into day 10.');
        }
    }

    public function test_the_same_utc_instant_lands_on_different_local_dates_for_different_timezones(): void
    {
        [, $tokyo] = $this->tenant('Asia/Tokyo', 'Tokyo Venue');
        [, $la] = $this->tenant('America/Los_Angeles', 'LA Venue');
        $instant = $this->storageFromUtc('2026-06-10 16:30:00'); // 01:30 on June 11 in Tokyo, 09:30 on June 10 in LA

        $this->report($tokyo, $tokyo->customer_id, ['created_at' => $instant]);
        $this->report($la, $la->customer_id, ['created_at' => $instant]);

        $tokyoSeries = app(BusinessAnalyticsPresenter::class)->buildOverview($tokyo, AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-11'], 'Asia/Tokyo'))->messageVolume;
        $laSeries = app(BusinessAnalyticsPresenter::class)->buildOverview($la, AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-06-10', 'end' => '2026-06-11'], 'America/Los_Angeles'))->messageVolume;

        $this->assertSame([0, 1], $tokyoSeries->series['outgoing']);
        $this->assertSame([1, 0], $laSeries->series['outgoing']);
    }

    public function test_spring_forward_date_is_one_23_hour_bucket_end_to_end(): void
    {
        [, $business] = $this->tenant('America/New_York');
        $u = $business->customer_id;

        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-03-08 04:59:59')]); // 23:59:59 EST on March 7 — excluded
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-03-08 05:00:00')]); // 00:00 EST March 8 — included
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-03-09 03:59:59')]); // 23:59:59 EDT March 8 — included
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-03-09 04:00:00')]); // 00:00 EDT March 9 — excluded

        $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-03-08', 'end' => '2026-03-08'], 'America/New_York');
        $overview = app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range);

        $this->assertSame(2, $overview->messages->outbound);
        $this->assertSame(['2026-03-08'], $overview->messageVolume->dates);
        $this->assertSame([2], $overview->messageVolume->series['outgoing']);
    }

    public function test_fall_back_date_is_one_25_hour_bucket_end_to_end(): void
    {
        [, $business] = $this->tenant('America/New_York');
        $u = $business->customer_id;

        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-11-01 03:59:59')]); // 23:59:59 EDT Oct 31 — excluded
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-11-01 04:00:00')]); // 00:00 EDT Nov 1 — included
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-11-01 05:30:00')]); // 01:30 EDT (first occurrence) — included
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-11-01 06:30:00')]); // 01:30 EST (second occurrence) — included
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-11-02 04:59:59')]); // 23:59:59 EST Nov 1 — included
        $this->report($business, $u, ['created_at' => $this->storageFromUtc('2026-11-02 05:00:00')]); // 00:00 EST Nov 2 — excluded

        $range = AnalyticsDateRange::fromInput(['range' => 'custom', 'start' => '2026-11-01', 'end' => '2026-11-01'], 'America/New_York');
        $overview = app(BusinessAnalyticsPresenter::class)->buildOverview($business, $range);

        $this->assertSame(4, $overview->messages->outbound);
        $this->assertSame([4], $overview->messageVolume->series['outgoing']);
    }
}
