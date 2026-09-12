<?php

namespace Tests\Feature\Analytics;

use App\Library\Analytics\BusinessAnalyticsQueries;
use App\Models\Business;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 — Unified Home §3.1 (A-1): newContactsForBusinesses(), the one read in
 * this seam that spans several Businesses.
 *
 * It is K4's figure, grouped: same column, same half-open window, one figure
 * per id. It exists so the Agency Account Home can show every client without
 * asking this seam once per client, and it must never blur those clients
 * together or answer for an id the caller did not pass.
 */
class AnalyticsGroupedContactCountsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    public function test_each_business_keeps_its_own_figure_and_an_unasked_business_is_never_included(): void
    {
        [$customer, $first] = $this->tenant('America/New_York', 'First Studio');
        $second = $this->secondBusiness($customer, 'Second Studio');
        $third = $this->secondBusiness($customer, 'Third Studio');

        $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC');

        $this->contactsFor($first, 3, '2026-09-05 10:00:00');
        $this->contactsFor($second, 1, '2026-09-06 10:00:00');
        $this->contactsFor($third, 7, '2026-09-07 10:00:00');

        $counts = app(BusinessAnalyticsQueries::class)->newContactsForBusinesses([$first->id, $second->id], $start, $end);

        $this->assertSame([$first->id => 3, $second->id => 1], $counts);
        $this->assertArrayNotHasKey($third->id, $counts, "A Business the caller did not ask about is never answered for.");
    }

    public function test_the_window_is_half_open_and_a_business_with_nothing_is_simply_absent(): void
    {
        [$customer, $first] = $this->tenant('America/New_York', 'First Studio');
        $second = $this->secondBusiness($customer, 'Second Studio');

        $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-09-02 00:00:00', 'UTC');

        $this->contactsFor($first, 1, '2026-09-01 00:00:00');
        $this->contactsFor($first, 1, '2026-09-02 00:00:00');
        $this->contactsFor($first, 1, '2026-08-31 23:59:59');

        $counts = app(BusinessAnalyticsQueries::class)->newContactsForBusinesses([$first->id, $second->id], $start, $end);

        $this->assertSame([$first->id => 1], $counts, 'The start instant is inside the window; the end instant is not.');
        $this->assertArrayNotHasKey($second->id, $counts, 'A Business with nothing in the window returns no row, never a guessed zero.');
    }

    public function test_it_costs_one_statement_for_any_number_of_businesses_and_none_for_no_businesses(): void
    {
        [$customer, $first] = $this->tenant('America/New_York', 'First Studio');
        $businesses = [$first->id];

        foreach (['Second', 'Third', 'Fourth', 'Fifth'] as $name) {
            $business = $this->secondBusiness($customer, $name . ' Studio');
            $this->contactsFor($business, 2, '2026-09-05 10:00:00');
            $businesses[] = $business->id;
        }

        $queries = app(BusinessAnalyticsQueries::class);
        $start = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $queries->newContactsForBusinesses($businesses, $start, $end);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $log, 'Five Businesses, one grouped statement.');
        $this->assertMatchesRegularExpression('/group by/i', $log[0]['query']);
        $this->assertMatchesRegularExpression('/in \(/i', $log[0]['query']);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], $queries->newContactsForBusinesses([], $start, $end));
        $this->assertCount(0, DB::getQueryLog(), 'No ids, no statement.');
        DB::disableQueryLog();
    }

    // -----------------------------------------------------------------

    private function secondBusiness(Customer $customer, string $name): Business
    {
        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes([
            'name' => $name,
            'timezone' => 'America/New_York',
        ]))->fresh();
    }

    private function contactsFor(Business $business, int $count, string $storageTimestamp): void
    {
        $group = $this->group($business, 'Clients ' . $business->id);

        for ($i = 0; $i < $count; $i++) {
            $this->contact($business, $group, ['created_at' => $storageTimestamp]);
        }
    }
}
