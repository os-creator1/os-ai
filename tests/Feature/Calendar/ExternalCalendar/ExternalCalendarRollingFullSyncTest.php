<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarBusyEvent;
use App\DTO\Calendar\ExternalCalendarSyncPage;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §5.6/§11/§12.F, review correction — the
 * periodic rolling full-sync window. Neither provider's incremental
 * mechanism (Google syncToken, Microsoft delta query) widens the bounded
 * date window its OWN originating full read established
 * (config/calendar_external.php's own note explains why). Without a
 * periodic re-read, an event that legitimately falls outside "now" plus the
 * configured horizon at T0 would never be discovered once it drifts inside
 * that horizon later — pure incremental processing has no mechanism to
 * notice a boundary that only moves because time passed.
 *
 * These tests use frozen time (Carbon::setTestNow()) so "the current
 * window" is a concrete, assertable date range at every step, and prove the
 * rolling decision belongs to ExternalCalendarSyncService itself — exercised
 * through BOTH provider fakes — not to either provider client.
 */
class ExternalCalendarRollingFullSyncTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;
    use CreatesExternalCalendarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->bindFakeCalendarProviders();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): ExternalCalendarSyncService
    {
        return app(ExternalCalendarSyncService::class);
    }

    public function test_a_rolling_full_sync_discovers_an_event_that_drifted_inside_the_new_window(): void
    {
        $t0 = Carbon::parse('2026-01-01 00:00:00', 'UTC');
        Carbon::setTestNow($t0);

        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $this->assertNull($connection->last_full_synced_at);

        // T0 — no cursor at all, so the first sync is unconditionally full.
        // The event sits on day 10, comfortably inside the initial
        // [T0, T0+60d] window.
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-day10', deleted: false, startAt: $t0->clone()->addDays(10), endAt: $t0->clone()->addDays(10)->addHour()),
        ], 'cursor-t0', true);

        $this->service()->syncConnection($connection);

        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls);
        $this->assertSame(0, $this->fakeGoogle->incrementalBusyCalls);
        $fresh = $connection->fresh();
        $this->assertSame('cursor-t0', $fresh->sync_cursor);
        $this->assertNotNull($fresh->last_full_synced_at);
        $this->assertTrue($fresh->last_full_synced_at->equalTo($t0));
        $this->assertTrue($fresh->last_synced_at->equalTo($t0));

        // Shortly afterward, well inside full_resync_interval_hours
        // (default 24h) — the scheduled sync must use the incremental path.
        Carbon::setTestNow($t0->clone()->addHour());
        $this->fakeGoogle->incrementalBusyQueue[] = new ExternalCalendarSyncPage([], 'cursor-t0-inc', true);

        $this->service()->syncConnection($connection->fresh());

        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls, 'still exactly one full read — this sync must be incremental');
        $this->assertSame(1, $this->fakeGoogle->incrementalBusyCalls);
        $freshAfterIncremental = $connection->fresh();
        $this->assertSame('cursor-t0-inc', $freshAfterIncremental->sync_cursor);
        $this->assertTrue($freshAfterIncremental->last_full_synced_at->equalTo($t0), 'an incremental success never advances last_full_synced_at');

        // Advance the clock 30 days — well past full_resync_interval_hours.
        // The OLD window [T0, T0+60d] ends at T0+60d; an event on day 75
        // (relative to T0) was legitimately outside it. Relative to the NEW
        // "now" (T1 = T0+30d), day 75 is only 45 days away — inside the NEW
        // [T1, T1+60d] window. Pure incremental processing has no way to
        // discover it; only a rolling full re-read does.
        $t1 = $t0->clone()->addDays(30);
        Carbon::setTestNow($t1);
        $this->assertTrue($t0->clone()->addDays(75)->isAfter($t0->clone()->addDays(60)), 'sanity: day 75 was outside the T0 window');
        $this->assertTrue($t0->clone()->addDays(75)->isBefore($t1->clone()->addDays(60)), 'sanity: day 75 is inside the T1 window');

        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-day75', deleted: false, startAt: $t0->clone()->addDays(75), endAt: $t0->clone()->addDays(75)->addHour()),
        ], 'cursor-t1', true);

        $this->service()->syncConnection($connection->fresh());

        $this->assertSame(2, $this->fakeGoogle->fullBusyCalls, 'the rolling interval elapsed — this sync must be full, not incremental');
        $this->assertSame(1, $this->fakeGoogle->incrementalBusyCalls);

        $freshAfterRoll = $connection->fresh();
        $this->assertSame('cursor-t1', $freshAfterRoll->sync_cursor);
        $this->assertTrue($freshAfterRoll->last_full_synced_at->equalTo($t1), 'last_full_synced_at recomputes from the CURRENT now, not the original T0');
        $this->assertTrue($freshAfterRoll->last_synced_at->equalTo($t1));

        $this->assertSame(1, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'evt-day75')
            ->count(), 'the previously-out-of-window, now-in-window event must be present after the roll');
    }

    public function test_a_failed_rolling_full_sync_leaves_the_prior_cursor_and_cache_and_full_sync_timestamp_untouched(): void
    {
        $t0 = Carbon::parse('2026-01-01 00:00:00', 'UTC');
        Carbon::setTestNow($t0);

        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-good', deleted: false, startAt: $t0->clone()->addDays(5), endAt: $t0->clone()->addDays(5)->addHour()),
        ], 'cursor-t0', true);
        $this->service()->syncConnection($connection);

        $goodState = $connection->fresh();
        $this->assertSame('cursor-t0', $goodState->sync_cursor);
        $this->assertTrue($goodState->last_full_synced_at->equalTo($t0));

        // Past the rolling interval — the next sync is due to be full, but
        // the provider fails during that read.
        $t1 = $t0->clone()->addDays(30);
        Carbon::setTestNow($t1);
        $this->fakeGoogle->fullBusyQueue[] = ExternalCalendarProviderException::providerUnavailable();

        $this->service()->syncConnection($connection->fresh());

        $unchanged = $connection->fresh();
        $this->assertSame('cursor-t0', $unchanged->sync_cursor, 'a failed rolling full sync must never advance the cursor');
        $this->assertTrue($unchanged->last_full_synced_at->equalTo($t0), 'a failed rolling full sync must never advance last_full_synced_at');
        $this->assertSame(1, $unchanged->sync_failure_count);
        $this->assertSame(ExternalCalendarProviderException::FAILURE_PROVIDER_UNAVAILABLE, $unchanged->failure_classification);

        $this->assertSame(1, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'evt-good')
            ->count(), 'the prior good cache must be left exactly as it was');
    }

    /**
     * The rolling decision lives in ExternalCalendarSyncService, not in
     * either provider client — proven by exercising the SAME sequence
     * (full at T0, incremental shortly after, full again once the interval
     * elapses) through the Microsoft fake instead of the Google one.
     */
    public function test_the_rolling_decision_also_governs_outlook_connections(): void
    {
        $t0 = Carbon::parse('2026-01-01 00:00:00', 'UTC');
        Carbon::setTestNow($t0);

        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Outlook);
        $this->fakeOutlook->fullBusyQueue[] = new ExternalCalendarSyncPage([], 'delta-t0', true);
        $this->service()->syncConnection($connection);

        $this->assertSame(1, $this->fakeOutlook->fullBusyCalls);
        $this->assertTrue($connection->fresh()->last_full_synced_at->equalTo($t0));

        Carbon::setTestNow($t0->clone()->addHour());
        $this->fakeOutlook->incrementalBusyQueue[] = new ExternalCalendarSyncPage([], 'delta-t0-inc', true);
        $this->service()->syncConnection($connection->fresh());

        $this->assertSame(1, $this->fakeOutlook->fullBusyCalls);
        $this->assertSame(1, $this->fakeOutlook->incrementalBusyCalls);

        $t1 = $t0->clone()->addDays(30);
        Carbon::setTestNow($t1);
        $this->fakeOutlook->fullBusyQueue[] = new ExternalCalendarSyncPage([], 'delta-t1', true);
        $this->service()->syncConnection($connection->fresh());

        $this->assertSame(2, $this->fakeOutlook->fullBusyCalls, 'the rolling interval elapsed — this sync must be full again, exactly like the Google case');
        $this->assertSame(1, $this->fakeOutlook->incrementalBusyCalls);
        $this->assertTrue($connection->fresh()->last_full_synced_at->equalTo($t1));
    }
}
