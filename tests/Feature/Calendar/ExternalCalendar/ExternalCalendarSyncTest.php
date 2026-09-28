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
 * Implementation Contract 15 §5.6/§7.5/§11/§12.F — full+incremental sync
 * mechanics: idempotent upsert, delta tombstones, full-sync reconciliation,
 * the never-wipe-on-failure rule, cursor discipline, and provider-outage
 * fail-safe-stale behavior.
 */
class ExternalCalendarSyncTest extends TestCase
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

    private function service(): ExternalCalendarSyncService
    {
        return app(ExternalCalendarSyncService::class);
    }

    public function test_a_full_sync_upserts_busy_blocks_and_seeds_the_cursor(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-1', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour(), busyType: 'busy'),
        ], 'cursor-after-full', true);

        $this->service()->syncConnection($connection);

        $this->assertSame(1, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
        $fresh = $connection->fresh();
        $this->assertSame('cursor-after-full', $fresh->sync_cursor);
        $this->assertNotNull($fresh->last_synced_at);
        $this->assertSame(0, $fresh->sync_failure_count);
    }

    public function test_processing_the_same_event_twice_is_idempotent_no_duplicate_rows(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);

        $page = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-dup', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'cursor-1', true);

        $this->fakeGoogle->fullBusyQueue = [$page];
        $this->service()->syncConnection($connection);

        // Second sync, same connection, incremental this time (cursor now
        // set) — the exact same provider event id delivered again.
        $this->fakeGoogle->incrementalBusyQueue = [new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-dup', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'cursor-2', true)];
        $this->service()->syncConnection($connection);

        $this->assertSame(1, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'evt-dup')
            ->count());
    }

    public function test_a_delta_tombstone_deletes_the_corresponding_busy_block(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'seed-cursor']);
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connection->id,
            'provider_event_id' => 'evt-to-remove',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeGoogle->incrementalBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-to-remove', deleted: true),
        ], 'seed-cursor-2', true);

        $this->service()->syncConnection($connection);

        $this->assertSame(0, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'evt-to-remove')
            ->count());
    }

    public function test_a_tombstone_for_an_already_absent_event_is_a_no_op(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'seed-cursor']);

        $this->fakeGoogle->incrementalBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('never-existed', deleted: true),
        ], 'seed-cursor-2', true);

        // Must not throw, and leaves nothing behind.
        $this->service()->syncConnection($connection);

        $this->assertSame(0, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
    }

    public function test_full_sync_reconciliation_removes_a_busy_block_no_longer_returned(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connection->id,
            'provider_event_id' => 'vanished-event',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A full sync whose complete window read no longer includes it.
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([], 'fresh-cursor', true);

        $this->service()->syncConnection($connection);

        $this->assertSame(0, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'vanished-event')
            ->count());
    }

    public function test_an_incremental_sync_never_reconciles_by_absence_only_explicit_tombstones(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'seed-cursor']);
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connection->id,
            'provider_event_id' => 'untouched-event',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // An incremental delta that says nothing about 'untouched-event' at
        // all (a different event entirely) must not delete it.
        $this->fakeGoogle->incrementalBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('unrelated-event', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'seed-cursor-2', true);

        $this->service()->syncConnection($connection);

        $this->assertSame(1, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'untouched-event')
            ->count());
    }

    public function test_a_failed_sync_never_wipes_the_prior_good_cache_and_never_blocks_an_internal_booking(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'seed-cursor']);
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connection->id,
            'provider_event_id' => 'kept-on-failure',
            'start_at' => now(),
            'end_at' => now()->addHour(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->fakeGoogle->incrementalBusyQueue[] = ExternalCalendarProviderException::providerUnavailable();

        // §11 — provider outage during sync must never throw out of
        // syncConnection() and must never block/fail an internal booking
        // (proven again end-to-end in ExternalCalendarConflictIntegrationTest).
        $this->service()->syncConnection($connection);

        $this->assertSame(1, DB::table('external_calendar_busy_blocks')
            ->where('external_calendar_connection_id', $connection->id)
            ->where('provider_event_id', 'kept-on-failure')
            ->count());

        $fresh = $connection->fresh();
        $this->assertSame('seed-cursor', $fresh->sync_cursor);
        $this->assertSame(1, $fresh->sync_failure_count);
        $this->assertSame(ExternalCalendarProviderException::FAILURE_PROVIDER_UNAVAILABLE, $fresh->failure_classification);
    }

    public function test_an_invalid_cursor_falls_back_to_a_full_sync_exactly_once(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'disowned-cursor']);

        $this->fakeGoogle->incrementalBusyQueue[] = ExternalCalendarProviderException::cursorInvalid();
        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([
            new ExternalCalendarBusyEvent('evt-from-full', deleted: false, startAt: Carbon::now(), endAt: Carbon::now()->addHour()),
        ], 'brand-new-cursor', true);

        $this->service()->syncConnection($connection);

        $this->assertSame(1, $this->fakeGoogle->incrementalBusyCalls);
        $this->assertSame(1, $this->fakeGoogle->fullBusyCalls);
        $this->assertSame('brand-new-cursor', $connection->fresh()->sync_cursor);
        $this->assertSame(1, DB::table('external_calendar_busy_blocks')->where('external_calendar_connection_id', $connection->id)->count());
    }

    public function test_syncing_a_disconnected_connection_is_a_no_op(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        app(\App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager::class)->disconnect($connection, (int) $this->staff->id);

        $this->service()->syncConnection($connection->fresh());

        $this->assertSame(0, $this->fakeGoogle->fullBusyCalls);
        $this->assertSame(0, $this->fakeGoogle->incrementalBusyCalls);
    }

    public function test_the_raw_encrypted_refresh_token_column_is_never_sent_to_the_provider_client(): void
    {
        $connection = $this->createActiveConnection($this->staff, ExternalCalendarProvider::Google);
        $rawCiphertext = DB::table('external_calendar_connections')->where('id', $connection->id)->value('refresh_token_encrypted');

        $this->fakeGoogle->fullBusyQueue[] = new ExternalCalendarSyncPage([], null, true);

        $this->service()->syncConnection($connection);

        // accessTokenFor() decrypts before calling the provider client — the
        // encrypted column's own ciphertext (the `encrypted` cast's base64
        // JSON envelope) must never appear on the wire.
        $this->assertNotEmpty($this->fakeGoogle->observedAccessTokens);
        $this->assertStringNotContainsString((string) $rawCiphertext, $this->fakeGoogle->observedAccessTokens[0]);
    }
}
